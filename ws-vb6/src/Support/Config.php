<?php
declare(strict_types=1);

namespace Ws\Support;

/**
 * Config: lightweight .env loader for WS-VB6.
 *
 * Mirrors the API Config implementation but kept inside the Ws namespace so
 * each service can be deployed independently without cross-dependencies.
 */
final class Config
{
    /** @var array<string, string> */
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            self::$loaded = true;
            return;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            self::$loaded = true;
            return;
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $raw = trim(substr($line, $eq + 1));

            if (strlen($raw) >= 2) {
                $first = $raw[0];
                $last = $raw[strlen($raw) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $raw = substr($raw, 1, -1);
                }
            }

            if ($key === '' || !self::isValidKey($key)) {
                continue;
            }

            if (getenv($key) !== false) {
                self::$values[$key] = (string) getenv($key);
                continue;
            }

            self::$values[$key] = $raw;
            putenv($key . '=' . $raw);
            $_ENV[$key] = $raw;
            $_SERVER[$key] = $raw;
        }

        fclose($handle);
        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (isset(self::$values[$key])) {
            return self::$values[$key];
        }
        $env = getenv($key);
        return $env === false ? $default : (string) $env;
    }

    public static function getRequired(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Missing required config key: {$key}");
        }
        return $value;
    }

    public static function getInt(string $key, ?int $default = null): ?int
    {
        $raw = self::get($key);
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (!preg_match('/^-?\d+$/', $raw)) {
            throw new \RuntimeException("Config key {$key} is not an integer: {$raw}");
        }
        return (int) $raw;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $raw = self::get($key);
        if ($raw === null) {
            return $default;
        }
        $lower = strtolower($raw);
        if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($lower, ['0', 'false', 'no', 'off', ''], true)) {
            return false;
        }
        return $default;
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        return self::$values;
    }

    private static function isValidKey(string $key): bool
    {
        return (bool) preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key);
    }
}
