#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * outbox-worker.php — F14 (TSK-152)
 *
 * Processes PENDING outbox_vb6 items and delivers them to the WS-VB6 bridge.
 *
 * - Fetches up to 10 PENDING items with next_attempt_at <= now.
 * - For each item: calls the appropriate WsVb6Client endpoint.
 * - On success: marks SENT; if topic=debt.created, updates debts.status=SYNCED.
 * - On failure: marks FAILED with exponential backoff (min(2^attempts, 300)s).
 *
 * Usage:
 *   cd /root/cerraduras/api
 *   php bin/outbox-worker.php [--limit N]
 *
 * Exit codes:
 *   0 — OK (all items processed, or nothing to process)
 *   1 — Fatal error (DB unreachable, config missing)
 */

$scriptDir = __DIR__;
$projectDir = dirname($scriptDir);

require $projectDir . '/src/Support/Autoload.php';

use App\Domain\Debts\DebtRepository;
use App\Domain\Debts\OutboxVb6Repository;
use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\WsVb6Client\WsVb6Client;
use App\Support\Config;
use App\Support\Errors\ApiException;

// Load config
Config::load($projectDir . '/.env');

// Parse CLI args
$limit = 10;
foreach ($argv as $i => $arg) {
    if ($arg === '--limit' && isset($argv[$i + 1])) {
        $limit = max(1, min(100, (int) $argv[$i + 1]));
    }
}

// Setup
try {
    $pdo        = PdoFactory::make();
    $outboxRepo = new OutboxVb6Repository($pdo);
    $debtRepo   = new DebtRepository($pdo);
    $wsClient   = new WsVb6Client(10);
} catch (\Throwable $e) {
    fwrite(STDERR, '[outbox-worker] FATAL setup error: ' . $e->getMessage() . "\n");
    exit(1);
}

// Fetch due items
$items = $outboxRepo->fetchDue($limit);

if (empty($items)) {
    echo '[outbox-worker] Nothing to process.' . "\n";
    exit(0);
}

echo '[outbox-worker] Processing ' . count($items) . " item(s)...\n";

$processed = 0;
$failed    = 0;

foreach ($items as $item) {
    $id       = (int) $item['id'];
    $topic    = (string) $item['topic'];
    $idemKey  = (string) $item['idempotency_key'];
    $attempts = (int) $item['attempts'];

    $payload = json_decode((string) $item['payload_json'], true);
    if (!is_array($payload)) {
        $payload = [];
    }

    echo "[outbox-worker] Processing id={$id} topic={$topic} attempts={$attempts}\n";

    try {
        // Route to correct WS-VB6 endpoint
        if ($topic === 'debt.created') {
            // Aplanar vb6_refs al nivel raíz (WS-VB6 espera codtic, codcli, etc.
            // en el nivel superior, no anidados bajo vb6_refs)
            if (isset($payload['vb6_refs']) && is_array($payload['vb6_refs'])) {
                foreach ($payload['vb6_refs'] as $k => $v) {
                    if ($v !== null) {
                        $payload[$k] = $v;
                    }
                }
                unset($payload['vb6_refs']);
            }
            $result = $wsClient->postDebts($payload, $idemKey);
        } elseif ($topic === 'stay.closed' || $topic === 'stay.overstay') {
            $result = $wsClient->postStaysEvents($payload, $idemKey);
        } else {
            // Unknown topic — log and mark as sent to not block queue
            echo "[outbox-worker] WARN Unknown topic '{$topic}', marking as sent.\n";
            $outboxRepo->markSent($id);
            $processed++;
            continue;
        }

        // Success
        $outboxRepo->markSent($id);
        $processed++;

        // If debt.created and result has importe, update debt status
        if ($topic === 'debt.created' && !empty($payload['debt_id'])) {
            $debtId  = (int) $payload['debt_id'];
            $importe = isset($result['importe']) ? (int) $result['importe'] : null;
            $ackRef  = isset($result['codtic'], $result['temporada'])
                ? $result['codtic'] . '-' . $result['temporada']
                : null;

            $updateFields = ['status' => 'SYNCED'];
            if ($importe !== null) {
                $updateFields['amount_eur_snapshot'] = $importe;
            }
            if ($ackRef !== null) {
                $updateFields['vb6_ack_ref'] = $ackRef;
            }
            $debtRepo->update($debtId, $updateFields);
            echo "[outbox-worker] Debt id={$debtId} marked SYNCED\n";
        }

        echo "[outbox-worker] SUCCESS id={$id} topic={$topic}\n";

    } catch (ApiException $e) {
        $failed++;
        $errMsg = "ApiException({$e->errorCode()}): {$e->getMessage()}";
        $outboxRepo->markFailed($id, $errMsg, $attempts);
        echo "[outbox-worker] FAIL id={$id} topic={$topic}: {$errMsg}\n";
    } catch (\Throwable $e) {
        $failed++;
        $errMsg = get_class($e) . ': ' . $e->getMessage();
        $outboxRepo->markFailed($id, $errMsg, $attempts);
        echo "[outbox-worker] FAIL id={$id} topic={$topic}: {$errMsg}\n";
    }
}

echo "[outbox-worker] Done. processed={$processed} failed={$failed}\n";
exit($failed > 0 ? 1 : 0);
