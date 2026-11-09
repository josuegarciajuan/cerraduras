<?php
declare(strict_types=1);

/**
 * bin/panel-reopen-dismissed.php — F120/RF-313
 *
 * Reabre (DISMISSED → OPEN) las anomalías descartadas MANUALMENTE para que el
 * operador pueda revisarlas con el nuevo tratamiento visual. Las resueltas
 * automáticamente por `system` no se tocan.
 *
 *   php bin/panel-reopen-dismissed.php --dry-run
 *   php bin/panel-reopen-dismissed.php --apply
 *
 * Idempotente: solo actúa sobre filas con reopened_at NULL y dismissed_by
 * distinto de 'system'. Conserva el reconocimiento/descarte previos.
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$mode = $argv[1] ?? '--dry-run';
if (!in_array($mode, ['--dry-run', '--apply'], true)) {
    fwrite(STDERR, "Uso: php bin/panel-reopen-dismissed.php --dry-run|--apply\n");
    exit(2);
}

$reason = 'Reapertura inicial para revisión F120';
$actor  = 'system:F120';

$pdo = PdoFactory::make();

$where = "status = 'DISMISSED'
          AND dismissed_by IS NOT NULL
          AND dismissed_by <> 'system'
          AND reopened_at IS NULL";

$count = (int) $pdo->query("SELECT COUNT(*) FROM anomalies WHERE {$where}")->fetchColumn();

if ($mode === '--dry-run') {
    echo json_encode([
        'ok' => true,
        'mode' => 'dry-run',
        'candidates' => $count,
        'reason' => $reason,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "UPDATE anomalies
         SET status = 'OPEN', reopened_at = UTC_TIMESTAMP(3),
             reopened_by = :actor, reopen_reason = :reason
         WHERE {$where}"
    );
    $stmt->execute([':actor' => $actor, ':reason' => $reason]);
    $changed = $stmt->rowCount();
    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'mode' => 'apply',
        'reopened' => $changed,
        'reason' => $reason,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[panel-reopen-dismissed] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
