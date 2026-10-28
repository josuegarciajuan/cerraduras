<?php
declare(strict_types=1);

/**
 * F94/RF-160 — store de corridas del asistente de sensores.
 *
 * Run:
 *   php tests/Unit/SensorRunStoreTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Sensors\SensorRunStore;

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }
function check(string $m, bool $c, string $d = ''): void { $c ? pass($m) : fail($m . ($d ? " — {$d}" : '')); }

$dir = sys_get_temp_dir() . '/sensores-store-' . bin2hex(random_bytes(4));
$store = new SensorRunStore($dir);

echo "Sensor run store (F94/RF-160)\n";

check('id válido aceptado', $store->isValidId('14-20261028T120000Z-ab12cd'));
check('path traversal rechazado', !$store->isValidId('../evil'));
check('id con barra rechazado', !$store->isValidId('a/b'));
check('id vacío rechazado', !$store->isValidId(''));

$run = $store->create(['room_id' => 14, 'operator' => 'tester', 'catalog_version' => 2,
    'plan' => ['door', 'radar_inside', 'radar_outside', 'light'],
    'initial_settings' => ['maximum_range' => 6]]);
check('create devuelve run_id', isset($run['run_id']) && $run['run_id'] !== '');
check('run_id válido', $store->isValidId((string) $run['run_id']));
check('estado inicial running', $run['status'] === 'running');
check('guarda settings iniciales', ($run['calibration']['initial_settings']['maximum_range'] ?? null) === 6);
check('guarda plan y versión', $run['plan'] === ['door', 'radar_inside', 'radar_outside', 'light'] && $run['catalog_version'] === 2);

// Intentos activos y lock
$run['steps']['door'] = ['status' => 'IN_PROGRESS', 'active_attempt' => ['attempt_id' => 'a-test', 'step_id' => 'door']];
$store->save($run);
$active = $store->findActiveAttemptForRoom(14);
check('findActiveAttemptForRoom encuentra el intento', is_array($active) && $active['attempt_id'] === 'a-test');
check('findActiveAttemptForRoom ignora otras salas', $store->findActiveAttemptForRoom(99) === null);
$locked = $store->withLock((string) $run['run_id'], static function (array $r): array {
    $r['steps']['door']['status'] = 'PASS';
    return $r;
});
check('withLock persiste la mutación', ($locked['steps']['door']['status'] ?? null) === 'PASS');
check('withLock releyó el fichero', ($store->load((string) $run['run_id'])['steps']['door']['status'] ?? null) === 'PASS');
$run = $store->load((string) $run['run_id']);
unset($run['steps']['door']['active_attempt']);
$store->save($run);
check('sin intento activo deja de encontrarlo', $store->findActiveAttemptForRoom(14) === null);

$loaded = $store->load((string) $run['run_id']);
check('load devuelve la corrida', is_array($loaded) && $loaded['run_id'] === $run['run_id']);

$run['steps']['D1'] = ['status' => 'PASS', 'started_at' => '2026-10-28T12:00:00Z'];
$run['summary'] = ['pass' => 1, 'fail' => 0, 'skip' => 0, 'pending' => 0, 'total' => 1, 'green' => true];
$store->save($run);
$reloaded = $store->load((string) $run['run_id']);
check('save persiste pasos', ($reloaded['steps']['D1']['status'] ?? null) === 'PASS');
check('save actualiza summary', ($reloaded['summary']['green'] ?? null) === true);

$store->appendLog((string) $run['run_id'], ['type' => 'step_check', 'step_id' => 'D1', 'status' => 'PASS']);
$log = @file_get_contents($store->logPath((string) $run['run_id']));
check('log append-only con una línea', is_string($log) && substr_count(trim($log), "\n") === 1);
check('log contiene el paso', is_string($log) && str_contains($log, 'step_check'));

$list = $store->listRuns();
check('listRuns incluye la corrida', count($list) === 1 && $list[0]['run_id'] === $run['run_id']);
check('listRuns trae el resumen', ($list[0]['summary']['pass'] ?? null) === 1);

check('load inexistente null', $store->load('14-20260101T000000Z-ffffff') === null);
check('load inválido null', $store->load('../x') === null);

// Limpieza
foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($dir);

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
