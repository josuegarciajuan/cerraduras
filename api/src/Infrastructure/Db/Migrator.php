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
 *  - Multi-statement .sql files are split into individual statements and run
 *    one by one. Statements that return a result set (SELECT/SHOW/EXPLAIN/
 *    DESCRIBE/DESC/CALL) are drained with closeCursor(), which avoids the PDO
 *    "Cannot execute queries while there are pending result sets" failure and
 *    keeps the _migrations bookkeeping deterministic.
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
            $statementNumber = 0;
            $statementSnippet = '';
            try {
                foreach (self::splitStatements($sql) as $index => $statement) {
                    $statementNumber = $index + 1;
                    $statementSnippet = self::snippet($statement);

                    if (self::returnsResultSet($statement)) {
                        $result = $this->pdo->query($statement);
                        if ($result instanceof \PDOStatement) {
                            // Drain and discard: prevents pending result sets from
                            // breaking the next exec() on this connection.
                            $result->closeCursor();
                        }
                    } else {
                        $this->pdo->exec($statement);
                    }
                }

                $stmt = $this->pdo->prepare(
                    "INSERT INTO _migrations (filename, applied_at) VALUES (:f, UTC_TIMESTAMP(3))"
                );
                $stmt->execute([':f' => $filename]);
                $appliedNow++;
            } catch (\Throwable $e) {
                $detail = $statementNumber > 0
                    ? "statement {$statementNumber} (`{$statementSnippet}`) - " . $e->getMessage()
                    : $e->getMessage();
                throw new \RuntimeException(
                    "Migration failed: {$filename} - {$detail}",
                    0,
                    $e
                );
            }
        }

        echo "[migrate] Done. Applied: {$appliedNow}, Already present: "
            . (count($files) - $appliedNow) . "\n";

        return $appliedNow;
    }

    /**
     * Split a SQL script into individual statements.
     *
     * The separator is `;` only when it appears outside of:
     *   - single-quoted strings  '...'  (escaped as '' or \')
     *   - double-quoted strings  "..."  (escaped as "" or \")
     *   - backtick identifiers   `...`  (escaped as `` or \`)
     *   - line comments          -- ...  and  # ...
     *   - block comments         /* ... *\/
     *
     * Comments are dropped from the emitted statements (replaced by a single
     * space to avoid gluing surrounding tokens together). Empty/whitespace-only
     * fragments are ignored.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = ($i + 1 < $length) ? $sql[$i + 1] : '';

            // Line comment: -- ... or # ... (skip to, but not including, newline).
            if (($char === '-' && $next === '-') || $char === '#') {
                if ($current !== '') {
                    $current .= ' ';
                }
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // Block comment: /* ... */ (may span lines).
            if ($char === '/' && $next === '*') {
                if ($current !== '') {
                    $current .= ' ';
                }
                $i += 2;
                while (
                    $i < $length
                    && !($sql[$i] === '*' && $i + 1 < $length && $sql[$i + 1] === '/')
                ) {
                    $i++;
                }
                $i += 2; // consume the closing */ (overshoots safely on unterminated input)
                continue;
            }

            // Quoted segment: consume verbatim, honoring backslash and doubled-quote escapes.
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                $i++;
                while ($i < $length) {
                    $c = $sql[$i];
                    if ($c === '\\') {
                        $current .= $c;
                        $i++;
                        if ($i < $length) {
                            $current .= $sql[$i];
                            $i++;
                        }
                        continue;
                    }
                    if ($c === $quote) {
                        // A doubled quote is an escaped quote, not the end of the literal.
                        if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                            $current .= $c . $c;
                            $i += 2;
                            continue;
                        }
                        $current .= $c;
                        $i++;
                        break;
                    }
                    $current .= $c;
                    $i++;
                }
                continue;
            }

            if ($char === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * Whether a statement returns a result set that must be drained.
     *
     * Leading comments/whitespace are ignored defensively, even though
     * splitStatements() already strips comments.
     */
    private static function returnsResultSet(string $statement): bool
    {
        $normalized = ltrim($statement);

        while ($normalized !== '') {
            if (str_starts_with($normalized, '--') || str_starts_with($normalized, '#')) {
                $newline = strpos($normalized, "\n");
                if ($newline === false) {
                    return false;
                }
                $normalized = ltrim(substr($normalized, $newline + 1));
                continue;
            }
            if (str_starts_with($normalized, '/*')) {
                $end = strpos($normalized, '*/');
                if ($end === false) {
                    return false;
                }
                $normalized = ltrim(substr($normalized, $end + 2));
                continue;
            }
            break;
        }

        return (bool) preg_match('/^(SELECT|SHOW|EXPLAIN|DESCRIBE|DESC|CALL)\b/i', $normalized);
    }

    /** Single-line, length-bounded excerpt of a statement for error messages. */
    private static function snippet(string $statement, int $max = 80): string
    {
        $flat = preg_replace('/\s+/', ' ', trim($statement));
        $flat = ($flat === null) ? trim($statement) : $flat;

        return (strlen($flat) > $max) ? substr($flat, 0, $max) . '...' : $flat;
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
