<?php
declare(strict_types=1);

namespace Ws\Support\Db;

use PDO;
use Ws\Support\Config;

/**
 * PdoFactory: creates the two PDO connections WS-VB6 needs.
 *
 *  - vb6(): connection to bs2026 (the VB6 program database). Used by Vb6Repo
 *           classes to insert into `deudas` and `log_usuarios`. We never alter
 *           that schema.
 *  - aux(): connection to the WS-VB6 own auxiliary schema (e.g. ws_vb6_aux),
 *           used to store idempotency records and internal state.
 *
 * Both connections use utf8mb4 and session TZ '+00:00'. The VB6 DB is stored
 * in its historical TZ by the main program; we keep the session at UTC so our
 * timestamps (occurred_at from the API) round-trip correctly. Any field
 * already stored by VB6 is read/written in raw as a DATETIME.
 */
final class PdoFactory
{
    private static ?PDO $vb6 = null;
    private static ?PDO $aux = null;

    public static function vb6(): PDO
    {
        if (self::$vb6 !== null) {
            return self::$vb6;
        }

        $host = Config::get('VB6_DB_HOST', '127.0.0.1');
        $port = Config::getInt('VB6_DB_PORT', 3306) ?? 3306;
        $name = Config::getRequired('VB6_DB_NAME');
        $user = Config::getRequired('VB6_DB_USER');
        $pass = Config::get('VB6_DB_PASS', '') ?? '';

        self::$vb6 = self::buildPdo($host, $port, $name, $user, $pass);
        return self::$vb6;
    }

    public static function aux(): PDO
    {
        if (self::$aux !== null) {
            return self::$aux;
        }

        $host = Config::get('AUX_DB_HOST', '127.0.0.1');
        $port = Config::getInt('AUX_DB_PORT', 3306) ?? 3306;
        $name = Config::getRequired('AUX_DB_NAME');
        $user = Config::getRequired('AUX_DB_USER');
        $pass = Config::get('AUX_DB_PASS', '') ?? '';

        self::$aux = self::buildPdo($host, $port, $name, $user, $pass);
        return self::$aux;
    }

    /** For tests */
    public static function reset(): void
    {
        self::$vb6 = null;
        self::$aux = null;
    }

    private static function buildPdo(
        string $host,
        int $port,
        string $name,
        string $user,
        string $pass
    ): PDO {
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
                "SET NAMES utf8mb4, " .
                "time_zone='+00:00', " .
                "sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
        ];

        return new PDO($dsn, $user, $pass, $options);
    }
}
