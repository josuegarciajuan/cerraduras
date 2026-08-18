<?php
declare(strict_types=1);

/**
 * bin/migrate.php
 *
 * Apply pending SQL migrations from api/migrations to the API database.
 * Idempotent: previously applied files are skipped.
 *
 * Usage:
 *   php bin/migrate.php
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Support\Config;
use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Db\Migrator;

Config::load(__DIR__ . '/../.env');

try {
    $pdo = PdoFactory::make();
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
