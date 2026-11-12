<?php
declare(strict_types=1);

/**
 * F125.4 — purga operativa del spool Zigbee.
 *
 * Elimina ficheros antiguos de `processed` y `quarantine`. Nunca toca
 * `incoming` ni `processing`. Por defecto hace dry-run; requiere `--apply`.
 *
 * Uso:
 *   php bin/zigbee-spool-prune.php
 *   php bin/zigbee-spool-prune.php --apply
 *   php bin/zigbee-spool-prune.php --dir=run/zigbee-spool-dev --processed-days=2 --apply
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Infrastructure\Zigbee\ZigbeeSpoolQueue;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
if (isset($opts['help'])) {
    echo "Uso: php bin/zigbee-spool-prune.php [--dir=RUTA] [--processed-days=N] [--quarantine-days=N] [--apply]\n";
    exit(0);
}

$apiDir = dirname(__DIR__);
$dir = (string) ($opts['dir'] ?? Config::get('ZIGBEE_SPOOL_DIR', 'run/zigbee-spool') ?? 'run/zigbee-spool');
if (!str_starts_with($dir, '/')) {
    $dir = $apiDir . '/' . $dir;
}
$processedDays = (int) ($opts['processed-days'] ?? Config::get('ZIGBEE_SPOOL_PROCESSED_RETENTION_DAYS', '2') ?? 2);
$quarantineDays = (int) ($opts['quarantine-days'] ?? Config::get('ZIGBEE_SPOOL_QUARANTINE_RETENTION_DAYS', '14') ?? 14);
$apply = isset($opts['apply']);

$queue = new ZigbeeSpoolQueue($dir);
$before = [
    'processed' => $queue->countFiles('processed'),
    'quarantine' => $queue->countFiles('quarantine'),
];
$removed = [
    'processed' => $queue->pruneOlderThan('processed', $processedDays, !$apply),
    'quarantine' => $queue->pruneOlderThan('quarantine', $quarantineDays, !$apply),
];
$after = $apply ? [
    'processed' => $queue->countFiles('processed'),
    'quarantine' => $queue->countFiles('quarantine'),
] : $before;

echo json_encode([
    'ok' => true,
    'dir' => $dir,
    'apply' => $apply,
    'processed_days' => $processedDays,
    'quarantine_days' => $quarantineDays,
    'before' => $before,
    'removed' => $removed,
    'after' => $after,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
