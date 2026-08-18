<?php
/**
 * Loads .env into $_ENV for standalone bin scripts that don't go through
 * the API bootstrap (Config::load). Idempotent — safe to include multiple times.
 */
if (!isset($_ENV['TUYA_ACCESS_ID'])) {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq !== false) {
                $k = trim(substr($line, 0, $eq));
                $v = trim(substr($line, $eq + 1), " \t\n\r\0\x0B\"'");
                $_ENV[$k] = $v;
                putenv("$k=$v");
            }
        }
    }
}
