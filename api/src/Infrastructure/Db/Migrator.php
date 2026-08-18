<?php
declare(strict_types=1);

namespace App\Infrastructure\Db;

use PDO;

/**
 * Migrator: simple file-based migration runner.
 *
 *  - Applies *.sql files from a directory, in lexicographic order.
 *  - Tracks applied files in table `_migrations(filename, applied_at)`.
 *  - Idempotent: re-runs skip already applied files.
 *
 * Design decisions:
 *  - No down migrations for MVP. Rolling back is done via explicit new files.
 *  - Multi-statement .sql files are supported using PDO::exec, which accepts
 *    compound statements for MySQL/MariaDB drivers.
 *  - Each file is applied in its own transaction when possible. DDL in MySQL
 *    auto-commits; we still wrap to ensure the _migrations row commits
 *    consistently.
 */
final class Migrator
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function run(string $migrationsDir): int
    {
        if (!is_dir($migrationsDir)) {
            throw new \RuntimeException("Migrations dir not found: {$migrationsDir}");
        }

        $this->ensureRegistryTable();

        $applied = $this->loadAppliedSet();

        $files = glob(rtrim($migrationsDir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $appliedNow = 0;
        foreach ($files as $fullPath) {
            $filename = basename($fullPath);
            if (isset($applied[$filename])) {
                echo "[migrate] SKIP  {$filename} (already applied)\n";
                continue;
            }

            $sql = file_get_contents($fullPath);
            if ($sql === false) {
                throw new \RuntimeException("Cannot read migration file: {$fullPath}");
            }

            echo "[migrate] APPLY {$filename} ...\n";
            try {
                $this->pdo->exec($sql);
                $stmt = $this->pdo->prepare(
                    "INSERT INTO _migrations (filename, applied_at) VALUES (:f, UTC_TIMESTAMP(3))"
                );
                $stmt->execute([':f' => $filename]);
                $appliedNow++;
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "Migration failed: {$filename} - " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        echo "[migrate] Done. Applied: {$appliedNow}, Already present: "
            . (count($files) - $appliedNow) . "\n";

        return $appliedNow;
    }

    private function ensureRegistryTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS `_migrations` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `filename` VARCHAR(255) NOT NULL,
                `applied_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_filename` (`filename`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return array<string,true> */
    private function loadAppliedSet(): array
    {
        $rows = $this->pdo->query('SELECT filename FROM _migrations')->fetchAll(PDO::FETCH_COLUMN);
        $set = [];
        foreach ($rows as $f) {
            $set[(string) $f] = true;
        }
        return $set;
    }
}
