<?php
declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Support\Config;
use PDO;

/**
 * PdoFactory: creates a configured PDO connection to the API MySQL database.
 *
 * Conventions (design.md §4, RNF-8, RNF-10):
 *  - charset utf8mb4
 *  - session time zone '+00:00' (DB stores UTC)
 *  - ERRMODE_EXCEPTION, FETCH_ASSOC default
 *  - EMULATE_PREPARES=false (real prepared statements)
 */
final class PdoFactory
{
    private static ?PDO $instance = null;

    public static function make(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host = Config::get('DB_HOST', '127.0.0.1');
        $port = Config::getInt('DB_PORT', 3306) ?? 3306;
        $name = Config::getRequired('DB_NAME');
        $user = Config::getRequired('DB_USER');
        $pass = Config::get('DB_PASS', '') ?? '';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $name
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::MYSQL_ATTR_INIT_COMMAND =>
                "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, " .
                "time_zone='+00:00', " .
                "sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
        ];

        $pdo = new PDO($dsn, $user, $pass, $options);

        self::$instance = $pdo;
        return $pdo;
    }

    /** For tests: reset the singleton. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Verify the connection is alive. Returns true if healthy.
     * If the connection is dead, resets the singleton so the next
     * call to make() creates a fresh connection.
     */
    public static function ping(): bool
    {
        if (self::$instance === null) {
            return false;
        }
        try {
            self::$instance->query('SELECT 1');
            return true;
        } catch (\PDOException $e) {
            self::$instance = null;
            return false;
        }
    }
}
