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
same('texto medición exterior', $measureCopy['title'], 'Comprobando el exterior');
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
check('F108: la UI pide permanecer en el punto', str_contains($copy105['instruction'], 'Permanece en el punto'));
check('F108: la UI no pide moverse', !str_contains($copy105['instruction'], 'mueve los brazos'));
check('F105: progreso 1 de 2', str_contains($copy105['message'], '1 de 2'));

// ── F108: evidencia exterior sin confundir silencio con ausencia ───────
$outside = new ReflectionMethod($controller, 'outsideEvidenceState');
$outside->setAccessible(true);
$silence = $outside->invoke($controller, ['reports'=>0,'presence_reports'=>0,'absent_reports'=>0,'last_value'=>null,'detected'=>false], null);
same('F108: silencio exterior no es claro', $silence['clear'], false);
same('F108: motivo sin reportes', $silence['reason'], 'no_reports');
$oneAbsent = $outside->invoke($controller, ['reports'=>1,'presence_reports'=>0,'absent_reports'=>1,'last_value'=>'ABSENT','detected'=>false], null);
same('F108: una ausencia no basta', $oneAbsent['clear'], false);
same('F108: ausencia sin confirmar', $oneAbsent['reason'], 'absence_unconfirmed');
$clear = $outside->invoke($controller, ['reports'=>2,'presence_reports'=>0,'absent_reports'=>2,'last_value'=>'ABSENT','detected'=>false], null);
same('F108: dos ABSENT confirman exterior', $clear['clear'], true);
$present = $outside->invoke($controller, ['reports'=>1,'presence_reports'=>1,'absent_reports'=>0,'last_value'=>'PRESENT','detected'=>true], null);
same('F108: presencia exterior detectada', $present['detected'], true);
$distanceOnly = $outside->invoke($controller, ['reports'=>0,'presence_reports'=>0,'absent_reports'=>0,'last_value'=>null,'detected'=>false], ['positive_reports'=>1]);
same('F108: distancia positiva cuenta como detección', $distanceOnly['detected'], true);

// ── F108: autoridad por evidencia DP 9 ───────────────────────────────
$asPoint = new ReflectionMethod($controller, 'distancePointAsPoint');
$asPoint->setAccessible(true);
$distance = [
    'status' => 'accepted', 'sufficient' => true, 'reason' => 'ok',
    'window' => ['started_at' => '2026-11-02T09:00:00Z', 'ended_at' => '2026-11-02T09:00:30Z'],
    'window_seconds' => 30, 'reports' => 3, 'positive_reports' => 2,
    'readings' => [['at' => '2026-11-02T09:00:05Z', 'distance' => 2.71]],
    'cluster' => [2.71, 2.71], 'excluded' => [], 'mean' => 2.71,
    'min' => 2.71, 'max' => 2.71, 'spread' => 0.0, 'median' => 2.71,
    'tolerance' => 0.30, 'min_samples' => 2, 'positive_span_seconds' => 5.0,
    'positive_last_at' => '2026-11-02T09:00:10Z', 'discarded_device' => 0,
    'discarded_config' => 0, 'frozen' => true,
];
$legacy = $asPoint->invoke($controller, $distance, true);
same('F108: distancia DP9 manda en la media', $legacy['mean'], 2.71);
same('F108: muestra suficiente', $legacy['sufficient'], true);
same('F108: presencia se conserva aparte', $legacy['detected'], true);
same('F108: lecturas nuevas = positivas', $legacy['fresh_readings'], 2);
same('F108: ayuda neutra', $legacy['help'], 'none');

// ── F106: diagnóstico de "sin mensajes" frente a "sin distancia" ──────
$diagPoint = $pointBad;
$diagPoint['reports'] = 2;
$diagPoint['has_reports'] = true;
$diagPoint['window_seconds'] = 30;
$diagPoint['last_report_age_seconds'] = 7;
$diagPoint['last_distance'] = 2.71;
$diagPoint['repeated_values'] = 2;
$copyDiag = $withPoint->invoke($controller,
    ['title' => 'X', 'instruction' => 'Y', 'message' => 'Z'], $diagPoint,
    ['state' => 'MEASURING', 'phase' => 'INSIDE']);
check('F106: la UI muestra mensajes y último reporte', str_contains($copyDiag['message'], 'Mensajes del radar: 2 en 30 s'));
check('F106: la UI muestra edad del último reporte', str_contains($copyDiag['message'], 'último hace 7 s'));
check('F106: la UI muestra distancia repetida', str_contains($copyDiag['message'], '2 valores repetidos'));

$copyNoReports = $copy->invoke($controller, sess([
    'phase' => 'INSIDE',
    'state' => 'STOPPED',
    'result' => 'inside_point_no_reports',
    'last_measurement' => [
        'phase' => 'INSIDE',
        'point' => ['reports' => 0, 'has_reports' => false, 'window_seconds' => 30],
    ],
]));
same('F106: distingue sensor en silencio', $copyNoReports['title'], 'El radar no envió datos');
same('F106: informa cero mensajes', $copyNoReports['message'], 'Mensajes del radar en la ventana: 0.');

$copyNotPresent = $copy->invoke($controller, sess([
    'phase' => 'INSIDE',
    'state' => 'STOPPED',
    'result' => 'inside_point_not_present',
    'last_measurement' => [
        'phase' => 'INSIDE',
        'point' => ['reports' => 4, 'has_reports' => true, 'window_seconds' => 30],
    ],
]));
same('F106: distingue envío sin presencia', $copyNotPresent['title'], 'El radar no detecta presencia');

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
