<?php
declare(strict_types=1);

/**
 * bin/db-check.php
 *
 * Smoke test for the WS-VB6 two PDO connections:
 *   - vb6: bs2026 (program VB6)
 *   - aux: auxiliary schema (idempotency)
 *
 * Both are checked independently; failures on one do not mask the other.
 *
 * Usage:
 *   php bin/db-check.php
 */

require __DIR__ . '/../src/Support/Autoload.php';

use Ws\Support\Config;
use Ws\Support\Db\PdoFactory;

Config::load(__DIR__ . '/../.env');

$exit = 0;

// --- VB6 connection ---
echo "[db-check] Checking VB6 connection (bs2026)...\n";
try {
    $vb6 = PdoFactory::vb6();
    $version = $vb6->query('SELECT VERSION() AS v')->fetchColumn();
    echo "  OK. Server version: {$version}\n";
    $rows = $vb6->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    echo "  Tables: " . count($rows) . "\n";
    foreach ($rows as $r) {
        echo "    - {$r[0]}\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "  FAIL: " . $e->getMessage() . "\n");
    $exit = 1;
}

// --- Auxiliary connection ---
echo "[db-check] Checking AUX connection...\n";
try {
    $aux = PdoFactory::aux();
    $version = $aux->query('SELECT VERSION() AS v')->fetchColumn();
    echo "  OK. Server version: {$version}\n";
    $rows = $aux->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    if (count($rows) === 0) {
        echo "  No tables found (expected if migrations have not been run yet).\n";
    } else {
        echo "  Tables:\n";
        foreach ($rows as $r) {
            echo "    - {$r[0]}\n";
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "  FAIL: " . $e->getMessage() . "\n");
    $exit = 1;
}

exit($exit);
