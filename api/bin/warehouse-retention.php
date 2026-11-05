<?php
declare(strict_types=1);

/**
 * bin/warehouse-retention.php — Retention purge for warehouse recordings (F64/RF-73.3).
 *
 * Deletes SAVED camera recordings (and their files under data/cameras/) older than
 * `warehouse.retention_days` from system_settings.
 *   - default: 1 day (tests)
 *   - 0 or negative: automatic deletion disabled (real environment)
 *
 * Usage: php bin/warehouse-retention.php
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$pdo = PdoFactory::make();
$api = dirname(__DIR__);
$storageRoot = realpath($api . '/data/cameras') ?: ($api . '/data/cameras');

$setting = static function (PDO $pdo, string $key, int $default): int {
    $stmt = $pdo->prepare("SELECT value FROM system_settings WHERE service='api' AND setting_key=:k LIMIT 1");
    $stmt->execute([':k' => $key]);
    $val = $stmt->fetchColumn();
    return ($val !== false && $val !== null && $val !== '') ? (int) $val : $default;
};

$warehouseDays = $setting($pdo, 'warehouse.retention_days', 1);
$timbreDays = $setting($pdo, 'timbre.retention_days', 30);
$rootReal = realpath($storageRoot);
$deleted = 0;

$purge = static function (int $days, string $where) use ($pdo, $api, $rootReal, &$deleted): void {
    if ($days <= 0) {
        return;
    }
    $stmt = $pdo->prepare(
        "SELECT id, file_path, poster_path FROM camera_recordings
         WHERE status='SAVED' AND stopped_at IS NOT NULL
           AND stopped_at < (UTC_TIMESTAMP(3) - INTERVAL :d DAY)
           AND {$where}"
    );
    $stmt->execute([':d' => $days]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        foreach (['file_path', 'poster_path'] as $col) {
            $rel = (string) ($row[$col] ?? '');
            if ($rel === '') {
                continue;
            }
            $abs = $rel[0] === '/' ? $rel : $api . '/' . $rel;
            $real = realpath($abs);
            // Anti path-traversal: only remove inside data/cameras/.
            if ($real === false || $rootReal === false || strpos($real, $rootReal) !== 0) {
                continue;
            }
            @unlink($real);
        }
        $pdo->prepare('DELETE FROM camera_recordings WHERE id=:id')->execute([':id' => (int) $row['id']]);
        $deleted++;
    }
};

$purge($warehouseDays, 'visit_id IS NOT NULL');
$purge($timbreDays, 'timbre_movement_id IS NOT NULL');

fwrite(STDOUT, "[warehouse-retention] purged {$deleted} recording(s) "
    . "(warehouse={$warehouseDays}d, timbre={$timbreDays}d)\n");
exit(0);
