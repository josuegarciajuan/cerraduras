<?php
declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader for the API, without Composer.
 *
 * Root namespace: App\ -> api/src/
 * Namespace prefix convention follows PSR-4:
 *   App\Http\Router -> api/src/Http/Router.php
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
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
