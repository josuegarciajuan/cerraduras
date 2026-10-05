<?php
declare(strict_types=1);

/**
 * Unit tests for the warehouse clip path resolution (F77.1).
 *
 * Trazabilidad: RF-78.x (servido de clips) · F77.1.
 *
 * Regresión del bug que hacía que TODO clip devolviera 404: la raíz de la API
 * se calculaba con `dirname(__DIR__, 2)` = `api/src` en vez de `api/`.
 *
 * Tiny PDO double: no DB, no HTTP.
 *
 * Run:
 *   php tests/Unit/WarehouseClipPathTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Http\Controllers\WarehouseRecordingController;

final class FakeClipPathPdo extends PDO
{
    public function __construct() {}
}

$passed = 0;
$failed = 0;

function pass(string $msg): void
{
    global $passed;
    $passed++;
    echo "  ✅ {$msg}\n";
}

function fail(string $msg): void
{
    global $failed;
    $failed++;
    echo "  ❌ {$msg}\n";
}

echo "WarehouseClipPath (F77.1) Unit Tests\n";
echo str_repeat("=", 60) . "\n\n";

$api  = dirname(__DIR__, 2);              // api/
$root = $api . '/data/cameras/_f77path';  // dentro del almacén real
@mkdir($root, 0775, true);
$relFile = 'data/cameras/_f77path/clip.mp4';
@file_put_contents($api . '/' . $relFile, 'F77-CLIP');

$controller = new WarehouseRecordingController(new FakeClipPathPdo());
$ref        = new ReflectionClass($controller);

// ── 1. La raíz de almacenamiento por defecto es api/data/cameras ─────────
$prop = $ref->getProperty('storageRoot');
$prop->setAccessible(true);
$storageRoot = (string) $prop->getValue($controller);
if (str_ends_with($storageRoot, '/data/cameras') && !str_contains($storageRoot, '/src/data/')) {
    pass('storageRoot por defecto = api/data/cameras');
} else {
    fail("storageRoot inesperado: {$storageRoot}");
}

// ── 2. resolveSafe resuelve un clip real dentro del almacén ──────────────
$resolve = $ref->getMethod('resolveSafe');
$resolve->setAccessible(true);
$resolved = $resolve->invoke($controller, $relFile);
if (is_string($resolved) && is_file($resolved) && str_ends_with($resolved, '/_f77path/clip.mp4')) {
    pass('resolveSafe sirve un clip real (no 404)');
} else {
    fail('resolveSafe no resolvió el clip real: ' . var_export($resolved, true));
}

// ── 3. Path traversal se bloquea ─────────────────────────────────────────
$escape = $resolve->invoke($controller, '../../../../etc/passwd');
if ($escape === null) {
    pass('resolveSafe bloquea path traversal fuera del almacén');
} else {
    fail('resolveSafe permitió escapar del almacén: ' . var_export($escape, true));
}

// ── 4. Fichero inexistente → null (404 controlado) ───────────────────────
$missing = $resolve->invoke($controller, 'data/cameras/_f77path/nope.mp4');
if ($missing === null) {
    pass('resolveSafe devuelve null para un fichero inexistente');
} else {
    fail('resolveSafe resolvió un fichero inexistente: ' . var_export($missing, true));
}

// ── cleanup ──────────────────────────────────────────────────────────────
@unlink($api . '/' . $relFile);
@rmdir($root);

echo "\n" . str_repeat("=", 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
