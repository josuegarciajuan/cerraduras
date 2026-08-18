<?php
declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader for WS-VB6.
 *
 * Root namespace: Ws\ -> ws-vb6/src/
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Ws\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    $file = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $relativePath;

    if (is_file($file)) {
        require $file;
    }
});
