<?php
declare(strict_types=1);

/**
 * bin/migrate.php (WS-VB6)
 *
 * Apply pending SQL migrations from ws-vb6/migrations to the AUXILIARY
 * database. This script never writes DDL to bs2026.
 *
 * Usage:
 *   php bin/migrate.php
 */

require __DIR__ . '/../src/Support/Autoload.php';

use Ws\Support\Config;
use Ws\Support\Db\PdoFactory;
use Ws\Support\Db\Migrator;

Config::load(__DIR__ . '/../.env');

try {
    $pdo = PdoFactory::aux();
    $migrator = new Migrator($pdo);
    $migrator->run(__DIR__ . '/../migrations');
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[migrate] ERROR: " . $e->getMessage() . "\n");
    if (Config::getBool('APP_DEBUG', false)) {
        fwrite(STDERR, $e->getTraceAsString() . "\n");
    }
    exit(1);
}
