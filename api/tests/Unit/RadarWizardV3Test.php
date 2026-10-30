<?php
declare(strict_types=1);

/**
 * F104/RF-214..RF-216 — acciones y textos del asistente v3.
 *
 * Run:
 *   php tests/Unit/RadarWizardV3Test.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Http\Controllers\SensorWizardController;

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void
{
    if ($a === $e) { pass($m); return; }
    fail($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}
function check(string $m, bool $c, string $d = ''): void { $c ? pass($m) : fail($m . ($d ? " — {$d}" : '')); }

$controller = new SensorWizardController(
    static fn () => null,
    sys_get_temp_dir() . '/cerraduras-f104-tests',
    __DIR__ . '/../../public/assets/sensor-tests-v2.json'
);
$next = new ReflectionMethod($controller, 'radarNextActions');
$copy = new ReflectionMethod($controller, 'radarCopy');
$countdown = new ReflectionMethod($controller, 'radarCountdown');
foreach ([$next, $copy, $countdown] as $m) {
    $m->setAccessible(true);
}

function sess(array $overrides = []): array
{
    return array_replace_recursive([
        'version' => 3,
        'model' => 'outside_point_v1',
        'phase' => 'OUTSIDE_MEASURE',
        'state' => 'IDLE',
        'config_version' => 1,
        'inside_ref' => ['reference' => 3.0, 'quality' => 'good', 'distance' => ['max' => 3.0]],
        'inside_valid' => ['sufficient' => true, 'detected' => true, 'config_version' => 1],
        'outside_measure' => ['sufficient' => true, 'detected' => true, 'distance' => ['median' => 1.5, 'quality' => 'good']],
        'outside_valid' => null,
        'outside_pass' => null,
        'reentry' => null,
        'last_measurement' => null,
        'calibration' => null,
        'candidates' => [],
        'active_operation' => null,
    ], $overrides);
}

echo "Radar wizard v3 (F104)\n";

same('outside measure IDLE → medir punto exterior', $next->invoke($controller, sess()),
    ['start_measure', 'restore', 'cancel']);
same('verify IDLE → verificar fuera', $next->invoke($controller, sess(['phase' => 'OUTSIDE_VERIFY'])),
    ['start_verify', 'restore', 'cancel']);
same('pass IDLE → medir pasada', $next->invoke($controller, sess(['phase' => 'OUTSIDE_PASS'])),
    ['start_measure', 'restore', 'cancel']);
same('reentry IDLE → entrar', $next->invoke($controller, sess(['phase' => 'REENTRY'])),
    ['start_reentry', 'restore', 'cancel']);

same('VERIFY_READY → cerrar verificación', $next->invoke($controller, sess([
    'phase' => 'OUTSIDE_VERIFY', 'state' => 'VERIFY_READY'])), ['finish_verify', 'cancel']);

$measureOp = ['operation_id' => 'op-1', 'kind' => 'MEASURE', 'state' => 'MEASURING',
    'window_end' => gmdate('Y-m-d\TH:i:s\Z', time() + 30)];
same('punto exterior midiendo → solo cancelar', $next->invoke($controller, sess([
    'state' => 'MEASURING', 'active_operation' => $measureOp])), ['cancel']);

$verifyOp = ['operation_id' => 'op-2', 'kind' => 'VERIFY', 'state' => 'MEASURING',
    'window_end' => gmdate('Y-m-d\TH:i:s\Z', time() - 1), 'published_at' => gmdate('Y-m-d\TH:i:s\Z')];
same('VERIFY vencido → cerrar', $next->invoke($controller, sess([
    'phase' => 'OUTSIDE_VERIFY', 'state' => 'MEASURING', 'active_operation' => $verifyOp])),
    ['finish_verify', 'cancel']);

same('STOPPED exterior → repetir medición', $next->invoke($controller, sess([
    'state' => 'STOPPED', 'result' => 'no_distance_separation'])),
    ['start_measure', 'restore', 'cancel']);
same('STOPPED verify → repetir verificación', $next->invoke($controller, sess([
    'phase' => 'OUTSIDE_VERIFY', 'state' => 'STOPPED', 'result' => 'no_distance_separation'])),
    ['start_verify', 'restore', 'cancel']);
same('DONE → validar', $next->invoke($controller, sess([
    'phase' => 'DONE', 'state' => 'DONE', 'result' => 'PASS'])), ['validate', 'restore']);

$noSep = $copy->invoke($controller, sess(['state' => 'STOPPED', 'result' => 'no_distance_separation']));
same('texto sin separación', $noSep['title'], 'Sin separación por distancia');
check('texto avisa de que no se aplica alcance',
    str_contains($noSep['message'], 'No se ha aplicado ningún alcance'));

$measureCopy = $copy->invoke($controller, sess(['state' => 'MEASURING',
    'active_operation' => $measureOp]));
same('texto medición exterior', $measureCopy['title'], 'Midiendo el punto exterior');
check('texto pide quedarse donde no debe detectar', str_contains($measureCopy['instruction'], 'NO quieres que te detecte'));

$verifyCopy = $copy->invoke($controller, sess(['phase' => 'OUTSIDE_VERIFY']));
same('texto verificación', $verifyCopy['title'], 'Verificar el punto exterior');
check('texto pide punto sin detección', str_contains($verifyCopy['instruction'], 'NO quieres que te detecte'));

$cdMeasure = $countdown->invoke($controller, sess(['state' => 'MEASURING', 'active_operation' => $measureOp]));
same('countdown medición exterior', $cdMeasure['kind'], 'measure');
$cdVerify = $countdown->invoke($controller, sess(['phase' => 'OUTSIDE_VERIFY',
    'state' => 'MEASURING', 'active_operation' => $verifyOp]));
same('countdown verificación', $cdVerify['kind'], 'verify');


// ── F105: media por punto y ayuda ─────────────────────────────────────
$applyPoint = new ReflectionMethod($controller, 'applyPointMeasurement');
$applyPoint->setAccessible(true);
$withPoint = new ReflectionMethod($controller, 'withPointInstruction');
$withPoint->setAccessible(true);
$pointOk = [
    'status' => 'accepted', 'sufficient' => true, 'reason' => 'ok', 'help' => 'none',
    'fresh_readings' => 3, 'readings' => [], 'cluster' => [2.84, 2.87, 2.90],
    'excluded' => [], 'mean' => 2.87, 'min' => 2.84, 'max' => 2.90,
    'spread' => 0.06, 'median' => 2.87, 'tolerance' => 0.30, 'min_samples' => 2,
    'detected' => true, 'reports' => 3, 'observations' => [],
];
$m105 = $applyPoint->invoke($controller, ['distance' => [], 'sufficient' => true], $pointOk, 'INSIDE');
same('F105: punto aceptado es suficiente', $m105['sufficient'], true);
same('F105: referencia es la media', $m105['reference'], 2.87);
same('F105: no usa semilla', $m105['seed_distance_used'], false);
same('F105: media en distance', $m105['distance']['mean'], 2.87);
check('F105: calidad buena por dispersión baja', $m105['distance']['quality'] === 'good');

$pointBad = $pointOk;
$pointBad['status'] = 'insufficient';
$pointBad['sufficient'] = false;
$pointBad['reason'] = 'not_enough_fresh_readings';
$pointBad['help'] = 'move_arms';
$pointBad['fresh_readings'] = 1;
$m105b = $applyPoint->invoke($controller, ['distance' => [], 'sufficient' => true], $pointBad, 'INSIDE');
same('F105: punto insuficiente no es suficiente', $m105b['sufficient'], false);
same('F105: motivo propagado', $m105b['reason'], 'not_enough_fresh_readings');

$copy105 = $withPoint->invoke($controller,
    ['title' => 'X', 'instruction' => 'Y', 'message' => 'Z'], $pointBad,
    ['state' => 'MEASURING', 'phase' => 'OUTSIDE_MEASURE']);
check('F105: la UI pide mover los brazos', str_contains($copy105['instruction'], 'mueve los brazos'));
check('F105: progreso 1 de 2', str_contains($copy105['message'], '1 de 2'));

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
