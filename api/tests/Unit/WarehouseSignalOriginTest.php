<?php
declare(strict_types=1);

/**
 * Unit tests for F79 (RF-102.1/102.2): el motor del almacén solo reacciona a
 * ciclos reales. Las señales simuladas (`provider=SIMULATED`) y los reenvíos
 * del resync REST (`meta.source='resync'`) no deben tocar la BD.
 *
 * No usa BD: crea un PDO sin conexión. Si el guard funciona, `onSignal` retorna
 * antes de tocar el PDO; si no, el PDO no conectado lanza y el test lo detecta.
 *
 * Run: php tests/Unit/WarehouseSignalOriginTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Warehouse\WarehouseRecordingService;

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }

function disconnectedPdo(): PDO
{
    return (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
}

echo "WarehouseSignalOriginTest (F79 / RF-102)\n";

$service = new WarehouseRecordingService(disconnectedPdo());

// 1) SIMULATED: el guard retorna antes de tocar el PDO.
try {
    $service->onSignal(12, 'DOOR_OPEN', ['provider' => 'SIMULATED', 'source' => 'sim']);
    pass('SIMULATED no toca la BD (visita no creada)');
} catch (\Throwable $e) {
    fail('SIMULATED debe ser ignorado: ' . $e->getMessage());
}

// 2) resync REST: es reconciliación de estado, no transición.
try {
    $service->onSignal(12, 'DOOR_OPEN', ['provider' => 'TUYA', 'source' => 'resync']);
    pass('source=resync no toca la BD (visita no creada)');
} catch (\Throwable $e) {
    fail('resync debe ser ignorado: ' . $e->getMessage());
}

// 3) Control: un evento real SÍ debe llegar a la BD (aquí, PDO sin conexión).
$touchedDb = false;
try {
    $service->onSignal(12, 'DOOR_OPEN', ['provider' => 'TUYA', 'source' => 'real']);
} catch (\Throwable $e) {
    $touchedDb = true;
}
if ($touchedDb) {
    pass('un evento real no lo frena el guard (intenta la BD)');
} else {
    fail('un evento real fue frenado de más (el guard no debe aplicarse)');
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
