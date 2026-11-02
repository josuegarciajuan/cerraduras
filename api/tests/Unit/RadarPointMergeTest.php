<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Http\Controllers\SensorWizardController;

$P = 0; $F = 0;
function pass(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? pass($m) : fail($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c): void { $c ? pass($m) : fail($m); }

$controller = new SensorWizardController(static fn () => null, '/tmp', '/tmp/sensor-tests-v2.json');
$method = new ReflectionMethod(SensorWizardController::class, 'applyPointMeasurement');
$method->setAccessible(true);

$seedPoint = [
    'sufficient' => true, 'detected' => true, 'fresh_readings' => 0,
    'median' => 3.4, 'mean' => 3.4, 'min' => 3.4, 'max' => 3.4,
    'spread' => 0.0, 'reason' => 'seed_distance_hold',
    'seed_distance_used' => true, 'tolerance' => 0.3,
];
$measurement = ['seed_used' => true, 'seed_distance_used' => false, 'seed_distance' => 3.4];
$merged = $method->invoke($controller, $measurement, $seedPoint, 'INSIDE');
same('F110: conserva seed_used', $merged['seed_used'], true);
same('F110: conserva seed_distance_used', $merged['seed_distance_used'], true);
same('F110: evidencia seed_distance_hold', $merged['evidence_mode'], 'seed_distance_hold');
same('F110: suficiente con semilla', $merged['sufficient'], true);
same('F110: distancia mediana', $merged['distance']['median'], 3.4);

$freshPoint = [
    'sufficient' => true, 'detected' => true, 'fresh_readings' => 2,
    'median' => 2.9, 'mean' => 2.9, 'min' => 2.8, 'max' => 3.0,
    'spread' => 0.2, 'reason' => 'lateral_median', 'tolerance' => 0.3,
];
$mergedFresh = $method->invoke($controller, ['seed_used' => true, 'seed_distance_used' => true], $freshPoint, 'INSIDE');
same('F110: lectura nueva prevalece sobre semilla', $mergedFresh['distance']['median'], 2.9);

$next = new ReflectionMethod(SensorWizardController::class, 'radarNextActionsV3');
$next->setAccessible(true);
$outsidePass = ['version' => 5, 'model' => 'distance_inside_only_v1',
    'phase' => 'OUTSIDE_PASS', 'state' => 'IDLE',
    'outside_pass' => ['detected' => false, 'sufficient' => true],
    'outside_valid' => ['detected' => false, 'sufficient' => true],
    'candidates' => [['candidate_id' => 'x']]];
same('F110: exterior claro ofrece advance', $next->invoke($controller, $outsidePass), ['advance', 'restore']);

$copy = new ReflectionMethod(SensorWizardController::class, 'radarCopyV3');
$copy->setAccessible(true);
$outsideCopy = $copy->invoke($controller, $outsidePass);
same('F110: exterior claro no pide pasillo', $outsideCopy['title'], 'Exterior correcto');

$measuring = ['version' => 5, 'model' => 'distance_inside_only_v1', 'phase' => 'INSIDE',
    'state' => 'MEASURING', 'active_operation' => ['kind' => 'MEASURE']];
$measuringCopy = $copy->invoke($controller, $measuring);
check('F110: interior pide movimiento lateral', str_contains($measuringCopy['instruction'], 'lateral'));

$clear = new ReflectionMethod(SensorWizardController::class, 'clearRadarEvidenceForPhase');
$clear->setAccessible(true);
$dirty = [
    'inside_valid' => ['detected' => true], 'inside_ref' => ['reference' => 2.9],
    'outside_valid' => ['detected' => false], 'outside_pass' => ['detected' => false],
    'reentry' => ['detected' => true], 'last_measurement' => ['phase' => 'OUTSIDE_MEASURE'],
    'calibration' => ['target_range' => 3.0], 'candidates' => [['candidate_id' => 'x']],
    'outside_message' => 'old',
];
$clear->invokeArgs($controller, [&$dirty, 'OUTSIDE_MEASURE']);
check('F110: conserva el interior al medir fuera', isset($dirty['inside_valid']));
check('F110: limpia evidencia exterior', !isset($dirty['outside_valid']) && !isset($dirty['outside_pass']));
check('F110: limpia última medición', !isset($dirty['last_measurement']));
check('F110: conserva candidato interior', !empty($dirty['candidates']));

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
