<?php
declare(strict_types=1);

/**
 * F110 opción 3 — retención de radar_distance_reports.
 *
 * Solo deben existir DP9 positivos (el repositorio ya descarta los ceros).
 * Este comando purga los positivos antiguos y, con --purge-zeros, limpia los
 * ceros históricos de instalaciones anteriores.
 *
 * Uso:
 *   php bin/radar-distance-retention.php --days=30 --dry-run
 *   php bin/radar-distance-retention.php --days=30 --purge-zeros
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

$opts = getopt('', ['days::', 'batch::', 'purge-zeros', 'dry-run']);
$days = isset($opts['days']) ? (int) $opts['days'] : (int) (getenv('RADAR_DISTANCE_RETENTION_DAYS') ?: 30);
$days = max(1, min(3650, $days));
$batch = isset($opts['batch']) ? (int) $opts['batch'] : 5000;
$batch = max(100, min(50000, $batch));
$purgeZeros = array_key_exists('purge-zeros', $opts);
$dryRun = array_key_exists('dry-run', $opts);

Config::load(dirname(__DIR__) . '/.env');
$pdo = PdoFactory::make();
$count = static function (PDO $pdo, string $sql): int {
    return (int) $pdo->query($sql)->fetchColumn();
};
$total = $count($pdo, 'SELECT COUNT(*) FROM radar_distance_reports');
$zeros = $count($pdo, 'SELECT COUNT(*) FROM radar_distance_reports WHERE distance_m <= 0');
$cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->modify('-' . $days . ' days')
    ->format('Y-m-d H:i:s.v');
$oldStmt = $pdo->prepare('SELECT COUNT(*) FROM radar_distance_reports WHERE received_at < :cutoff');
$oldStmt->execute([':cutoff' => $cutoff]);
$old = (int) $oldStmt->fetchColumn();

$deletedZeros = 0;
$deletedOld = 0;
if (!$dryRun) {
    if ($purgeZeros) {
        $deletedZeros = $pdo->exec('DELETE FROM radar_distance_reports WHERE distance_m <= 0') ?: 0;
    }
    do {
        $stmt = $pdo->prepare(
            'DELETE FROM radar_distance_reports WHERE received_at < :cutoff ORDER BY received_at ASC LIMIT :batch'
        );
        $stmt->bindValue(':cutoff', $cutoff);
        $stmt->bindValue(':batch', $batch, PDO::PARAM_INT);
        $stmt->execute();
        $n = $stmt->rowCount();
        $deletedOld += $n;
    } while ($n >= $batch);
}

$remaining = $count($pdo, 'SELECT COUNT(*) FROM radar_distance_reports');
echo json_encode([
    'ok' => true,
    'dry_run' => $dryRun,
    'days' => $days,
    'cutoff' => $cutoff,
    'total_before' => $total,
    'zeros_before' => $zeros,
    'older_than_cutoff' => $old,
    'purge_zeros' => $purgeZeros,
    'deleted_zeros' => $deletedZeros,
    'deleted_old' => $deletedOld,
    'remaining' => $remaining,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
