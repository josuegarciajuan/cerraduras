<?php
declare(strict_types=1);

/**
 * bin/db-check.php
 *
 * Smoke test for the API database connection.
 *  - Loads .env
 *  - Opens PDO
 *  - Prints server version, current timezone offset, and SHOW TABLES output
 *
 * Usage:
 *   php bin/db-check.php
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Support\Config;
use App\Infrastructure\Db\PdoFactory;

Config::load(__DIR__ . '/../.env');

try {
    $pdo = PdoFactory::make();
} catch (\Throwable $e) {
    fwrite(STDERR, "[db-check] Connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "[db-check] Connected to API database.\n";

$version = $pdo->query('SELECT VERSION() AS v')->fetchColumn();
echo "[db-check] Server version: {$version}\n";

$tz = $pdo->query("SELECT @@session.time_zone AS tz, NOW() AS now_utc")->fetch();
echo "[db-check] Session TZ: {$tz['tz']} | NOW(): {$tz['now_utc']}\n";

$rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
if (count($rows) === 0) {
    echo "[db-check] No tables found (expected if migrations have not been run yet).\n";
} else {
    echo "[db-check] Tables:\n";
    foreach ($rows as $r) {
        echo "  - {$r[0]}\n";
    }
}

exit(0);
