#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * sim-scenario.php — F15 (TSK-161)
 *
 * Executes a complete simulated stay flow:
 *   1. Find a free room (or use --room-id N)
 *   2. Emit QR for that room (POST /api/v1/qr)
 *   3. Validate QR → stay passes to OCCUPIED (POST /api/v1/qr/validate)
 *   4. POST /sim/rooms/{id}/door OPEN
 *   5. POST /sim/rooms/{id}/presence PRESENT
 *   6. POST /sim/rooms/{id}/presence ABSENT → triggers auto-exit check
 *   7. Run overstay-scan to detect any overstay (bin/overstay-scan.php)
 *   8. Execute outbox-worker to sync to WS-VB6
 *   9. Report final state
 *
 * Usage:
 *   php bin/sim-scenario.php [--room-id N] [--api-base http://127.0.0.1:8080]
 *
 * Exit codes:
 *   0 — scenario completed successfully
 *   1 — scenario failed
 */

$scriptDir  = __DIR__;
$projectDir = dirname($scriptDir);

require $projectDir . '/src/Support/Autoload.php';

use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load($projectDir . '/.env');

// --- CLI Args ---
$apiBase = 'http://127.0.0.1:8080';
$roomId  = null;

foreach ($argv as $i => $arg) {
    if ($arg === '--room-id' && isset($argv[$i + 1])) {
        $roomId = (int) $argv[$i + 1];
    }
    if ($arg === '--api-base' && isset($argv[$i + 1])) {
        $apiBase = rtrim($argv[$i + 1], '/');
    }
}

// --- Setup ---
$keysFile = $projectDir . '/seeds/dev_api_keys.txt';

function getKey(string $code, string $keysFile): string
{
    if (!is_file($keysFile)) {
        return '';
    }
    foreach (file($keysFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        $parts = preg_split('/\s+/', trim($line));
        if (isset($parts[0], $parts[1]) && $parts[0] === $code) {
            return $parts[1];
        }
    }
    return '';
}

$vb6Key  = getKey('VB6-MAIN', $keysFile);
$rpiKey  = getKey('RPI-DEV', $keysFile);
$simKey  = getKey('SIM-CLIENT', $keysFile);
$admKey  = getKey('ADMIN-CLI', $keysFile);

if (!$vb6Key || !$rpiKey || !$simKey) {
    echo "[sim-scenario] FAIL: API keys not found in {$keysFile}\n";
    exit(1);
}

// HTTP helper
function apiRequest(
    string $method,
    string $url,
    string $apiKey,
    ?array $body = null,
    ?string $idemKey = null
): array {
    $ch = curl_init();
    $headers = [
        'X-API-Key: ' . $apiKey,
        'Content-Type: application/json',
    ];
    if ($idemKey !== null) {
        $headers[] = 'Idempotency-Key: ' . $idemKey;
    }
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $resp     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string) $resp, true);
    return [
        'code' => $httpCode,
        'body' => is_array($decoded) ? $decoded : ['raw' => (string) $resp],
    ];
}

echo "[sim-scenario] Starting simulated stay flow...\n";

// --- Step 1: Find a free room ---
if ($roomId === null) {
    try {
        $pdo   = PdoFactory::make();
        $stmt  = $pdo->query("SELECT id, code FROM rooms WHERE status = 'FREE' ORDER BY id ASC LIMIT 1");
        $row   = $stmt->fetch();
        if ($row === false) {
            echo "[sim-scenario] SKIP: No free rooms available\n";
            exit(0);
        }
        $roomId   = (int) $row['id'];
        $roomCode = (string) $row['code'];
        echo "[sim-scenario] Step 1: Using room id={$roomId} code={$roomCode}\n";
    } catch (\Throwable $e) {
        echo "[sim-scenario] FAIL: DB error: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    echo "[sim-scenario] Step 1: Using room id={$roomId} (specified)\n";
}

// --- Step 2: Emit QR ---
$idemQr = 'sim-qr-' . $roomId . '-' . time();
$qrResp = apiRequest('POST', $apiBase . '/api/v1/qr', $vb6Key, [
    'room_id'           => $roomId,
    'duracion_minutos'  => 60,
], $idemQr);

if ($qrResp['code'] === 201) {
    $qrText = $qrResp['body']['qr_text'] ?? '';
    echo "[sim-scenario] Step 2: QR emitted (HTTP 201) qr_text=" . substr($qrText, 0, 30) . "...\n";
} elseif ($qrResp['code'] === 409) {
    // Room already occupied — skip with message
    echo "[sim-scenario] SKIP: Room {$roomId} is already occupied (409). Run with a fresh DB or different room.\n";
    exit(0);
} else {
    echo "[sim-scenario] FAIL: POST /qr returned HTTP " . $qrResp['code'] . "\n";
    echo "  Body: " . json_encode($qrResp['body']) . "\n";
    exit(1);
}

if (empty($qrText)) {
    echo "[sim-scenario] FAIL: No qr_text in response\n";
    exit(1);
}

// --- Step 3: Validate QR ---
$validateResp = apiRequest('POST', $apiBase . '/api/v1/qr/validate', $rpiKey, [
    'qr_text'   => $qrText,
    'device_id' => 'sim-device-1',
]);

if ($validateResp['code'] === 200) {
    $decision = $validateResp['body']['decision'] ?? 'unknown';
    echo "[sim-scenario] Step 3: QR validated (HTTP 200) decision={$decision}\n";
} else {
    echo "[sim-scenario] WARN: QR validate returned HTTP " . $validateResp['code'] . " (continuing...)\n";
}

// --- Step 4: Door OPEN ---
$doorResp = apiRequest('POST', $apiBase . "/sim/rooms/{$roomId}/door", $simKey, [
    'state' => 'OPEN',
]);
if ($doorResp['code'] === 202) {
    echo "[sim-scenario] Step 4: Door OPEN → 202\n";
} else {
    echo "[sim-scenario] WARN: door OPEN returned HTTP " . $doorResp['code'] . "\n";
}

// --- Step 5: Presence PRESENT ---
$presResp = apiRequest('POST', $apiBase . "/sim/rooms/{$roomId}/presence", $simKey, [
    'sensor' => 'PRESENCE',
    'value'  => 'PRESENT',
]);
if ($presResp['code'] === 202) {
    echo "[sim-scenario] Step 5: Presence PRESENT → 202\n";
} else {
    echo "[sim-scenario] WARN: presence PRESENT returned HTTP " . $presResp['code'] . "\n";
}

// --- Step 6: Presence ABSENT (trigger auto-exit check) ---
$absentResp = apiRequest('POST', $apiBase . "/sim/rooms/{$roomId}/presence", $simKey, [
    'sensor' => 'PRESENCE',
    'value'  => 'ABSENT',
]);
if ($absentResp['code'] === 202) {
    echo "[sim-scenario] Step 6: Presence ABSENT → 202 (auto-exit may trigger)\n";
} else {
    echo "[sim-scenario] WARN: presence ABSENT returned HTTP " . $absentResp['code'] . "\n";
}

// --- Step 7: Run overstay-scan ---
echo "[sim-scenario] Step 7: Running overstay-scan...\n";
$scanOut  = shell_exec('cd ' . escapeshellarg($projectDir) . ' && php bin/overstay-scan.php 2>&1');
echo "  " . trim((string) $scanOut) . "\n";

// --- Step 8: Run outbox-worker ---
echo "[sim-scenario] Step 8: Running outbox-worker...\n";
$workerOut = shell_exec('cd ' . escapeshellarg($projectDir) . ' && php bin/outbox-worker.php 2>&1');
echo "  " . trim((string) $workerOut) . "\n";

// --- Step 9: Final state ---
echo "[sim-scenario] Step 9: Checking final state...\n";

try {
    $pdo = PdoFactory::make();

    // Check stay status
    $stayStmt = $pdo->prepare(
        "SELECT id, status, room_id FROM stays WHERE room_id = :rid ORDER BY created_at DESC LIMIT 1"
    );
    $stayStmt->execute([':rid' => $roomId]);
    $stay = $stayStmt->fetch();

    if ($stay) {
        echo "  Stay id=" . $stay['id'] . " status=" . $stay['status'] . "\n";
    }

    // Check outbox
    $outboxStmt = $pdo->query(
        "SELECT COUNT(*) as total, SUM(status='SENT') as sent, SUM(status='PENDING') as pending
         FROM outbox_vb6 WHERE topic IN ('stay.closed','debt.created','stay.overstay')"
    );
    $outboxRow = $outboxStmt->fetch();
    echo "  Outbox: total=" . $outboxRow['total'] . " sent=" . $outboxRow['sent'] . " pending=" . $outboxRow['pending'] . "\n";

} catch (\Throwable $e) {
    echo "  WARN: Could not fetch final state: " . $e->getMessage() . "\n";
}

echo "[sim-scenario] Scenario completed.\n";
exit(0);
