<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarCalibrationPlanner;
use App\Domain\Sensors\RadarDistanceQuality;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }

$caps = ['range_min' => 0.15, 'range_max' => 9.5, 'range_step' => 0.75, 'sens_min' => 0, 'sens_max' => 9];

echo "Radar calibration (F96)\n";
$empty = RadarDistanceQuality::evaluate([]);
same('sin muestras → poor', $empty['quality'], 'poor');
same('sin muestras → motivo', $empty['reason'], 'sin_lecturas');
$zeros = RadarDistanceQuality::evaluate([0, 0, 0]);
same('todo cero → poor', $zeros['quality'], 'poor');
same('todo cero → motivo', $zeros['reason'], 'todas_cero');
$good = RadarDistanceQuality::evaluate([2.5, 2.55, 2.6, 2.5, 2.58]);
same('5 estables → good', $good['quality'], 'good');
same('mediana', $good['median'], 2.55);
check('spread pequeño', $good['spread'] <= 0.8);
$fair = RadarDistanceQuality::evaluate([2.4, 2.5, 2.6]);
same('3 estables → fair', $fair['quality'], 'fair');
$bad = RadarDistanceQuality::evaluate([1.0, 3.5, 2.0]);
same('dispersión alta → poor', $bad['quality'], 'poor');
$withZero = RadarDistanceQuality::evaluate([0, 2.5, 2.5, 2.5]);
same('cero no invalida 3 lecturas', $withZero['valid'], 3);
check('zero_ratio registrado', $withZero['zero_ratio'] > 0);

$insideGood = ['quality' => 'good', 'min' => 2.4, 'max' => 3.0, 'median' => 2.7];
$outsideGood = ['quality' => 'good', 'min' => 4.5, 'max' => 4.9, 'median' => 4.7];
$settings = ['maximum_range' => 6.0, 'radar_sensitivity' => 7];
$plan = RadarCalibrationPlanner::plan($insideGood, $outsideGood, $settings, $caps,
    ['inside_present' => true, 'outside_present' => false]);
same('separación por distancia → apply', $plan['action'], 'apply');
check('candidato entre interior y exterior', $plan['candidate'] >= 3.0 && $plan['candidate'] < 4.5);
same('confianza alta', $plan['confidence'], 'high');
same('motivo distance_separation', $plan['reason'], 'distance_separation');

$plan2 = RadarCalibrationPlanner::plan($insideGood, $outsideGood,
    ['maximum_range' => 3.9, 'radar_sensitivity' => 7], $caps,
    ['inside_present' => true, 'outside_present' => false]);
same('ya ajustado → none', $plan2['action'], 'none');

$outsideBad = ['quality' => 'fair', 'min' => 4.5, 'max' => 4.9, 'median' => 4.7];
$plan3 = RadarCalibrationPlanner::plan($insideGood, $outsideBad, $settings, $caps,
    ['inside_present' => true, 'outside_present' => true]);
same('detecta fuera → apply', $plan3['action'], 'apply');
check('baja el alcance', $plan3['params']['maximum_range'] < 6.0);

$insideBad = ['quality' => 'poor', 'min' => null, 'max' => null, 'median' => null];
$plan4 = RadarCalibrationPlanner::plan($insideBad, $outsideBad,
    ['maximum_range' => 6.0, 'radar_sensitivity' => 5], $caps,
    ['inside_present' => false, 'outside_present' => false]);
same('no detecta dentro → apply', $plan4['action'], 'apply');
same('sube sensibilidad', $plan4['params']['radar_sensitivity'] ?? null, 6);

$insideOnly = RadarCalibrationPlanner::plan($insideBad, [],
    ['maximum_range' => 6.0, 'radar_sensitivity' => 7], $caps,
    ['inside_present' => false, 'outside_present' => false]);
same('ajuste solo con medida interior → apply', $insideOnly['action'], 'apply');
same('ajuste solo interior sube sensibilidad', $insideOnly['params']['radar_sensitivity'] ?? null, 8);
same('motivo inside_not_detected', $insideOnly['reason'], 'inside_not_detected');

$plan5 = RadarCalibrationPlanner::plan($insideBad, $outsideBad,
    ['maximum_range' => 0.15, 'radar_sensitivity' => 0], $caps,
    ['inside_present' => false, 'outside_present' => true]);
same('sin ajustes posibles → reposition', $plan5['action'], 'reposition');

$reentryPlan = RadarCalibrationPlanner::plan($insideGood, $outsideBad,
    ['maximum_range' => 5.25, 'radar_sensitivity' => 6], $caps,
    ['inside_present' => true, 'outside_present' => false, 'reentry_failed' => true]);
same('reentrada fallida → apply', $reentryPlan['action'], 'apply');
same('reentrada fallida sube sensibilidad', $reentryPlan['params']['radar_sensitivity'] ?? null, 7);
same('motivo reentry_failed_sensitivity', $reentryPlan['reason'], 'reentry_failed_sensitivity');

$reentryExhausted = RadarCalibrationPlanner::plan($insideGood, $outsideBad,
    ['maximum_range' => 9.5, 'radar_sensitivity' => 9], $caps,
    ['inside_present' => true, 'outside_present' => false, 'reentry_failed' => true]);
same('reentrada fallida sin margen → reposition', $reentryExhausted['action'], 'reposition');

$protect = RadarCalibrationPlanner::plan(
    ['quality' => 'good', 'min' => 4.5, 'max' => 5.0, 'median' => 4.8],
    ['quality' => 'fair', 'min' => 4.6, 'max' => 4.9, 'median' => 4.7],
    ['maximum_range' => 5.25, 'radar_sensitivity' => 7], $caps,
    ['inside_present' => true, 'outside_present' => true]
);
same('protege el interior → reposition', $protect['action'], 'reposition');
same('motivo would_break_inside', $protect['reason'], 'would_break_inside');

$overlap = ['quality' => 'good', 'min' => 3.05, 'max' => 3.2, 'median' => 3.1];
$plan6 = RadarCalibrationPlanner::plan($insideGood, $overlap, $settings, $caps,
    ['inside_present' => true, 'outside_present' => true]);
same('rangos solapados → comportamiento', $plan6['reason'], 'outside_detected');

// ── F104: punto medio automático ──────────────────────────────────────
echo "\nRadar midpoint planner (F104)\n";
$fineCaps = ['range_min' => 0.15, 'range_max' => 9.5, 'range_step' => 0.15,
    'sens_min' => 0, 'sens_max' => 9, 'min_separation' => 0.45, 'margin' => 0.15];
$insideRef = ['detected' => true, 'sufficient' => true,
    'distance' => ['quality' => 'good', 'min' => 2.8, 'max' => 3.0, 'median' => 2.9]];
$scanOk = ['sufficient' => true, 'boundary_distance' => 4.2, 'reason' => 'boundary_found',
    'distance' => ['quality' => 'good', 'min' => 3.9, 'max' => 4.2, 'median' => 4.1]];
$curMid = ['maximum_range' => 3.9, 'radar_sensitivity' => 7];

$mid = RadarCalibrationPlanner::planMidpoint($insideRef, $scanOk, null, $curMid, $fineCaps);
same('punto medio → apply', $mid['action'], 'apply');
same('punto medio = (I+B)/2', $mid['candidate'], 3.6);
same('motivo midpoint', $mid['reason'], 'midpoint');
same('referencia interior', $mid['inside_reference'], 3.0);
same('frontera', $mid['boundary'], 4.2);

$midSame = RadarCalibrationPlanner::planMidpoint($insideRef, $scanOk, null,
    ['maximum_range' => 3.6, 'radar_sensitivity' => 7], $fineCaps);
same('punto medio ya aplicado → none', $midSame['action'], 'none');
same('motivo already_calibrated', $midSame['reason'], 'already_calibrated');

$noSep = RadarCalibrationPlanner::planMidpoint($insideRef,
    ['sufficient' => true, 'boundary_distance' => 3.2, 'reason' => 'boundary_found',
        'distance' => ['quality' => 'fair', 'min' => 2.9, 'max' => 3.2, 'median' => 3.1]],
    null, $curMid, $fineCaps);
same('sin separación → stop', $noSep['action'], 'stop');
same('motivo no_distance_separation', $noSep['reason'], 'no_distance_separation');

$outsideCloser = RadarCalibrationPlanner::planMidpoint($insideRef, $scanOk,
    ['detected' => true, 'distance' => ['quality' => 'fair', 'min' => 2.5, 'max' => 2.7, 'median' => 2.6]],
    $curMid, $fineCaps, ['outside_present' => true]);
same('punto exterior más cerca → stop', $outsideCloser['action'], 'stop');
same('motivo exterior más cerca', $outsideCloser['reason'], 'no_distance_separation');

$outsideEdge = RadarCalibrationPlanner::planMidpoint($insideRef, $scanOk,
    ['detected' => true, 'distance' => ['quality' => 'fair', 'min' => 3.8, 'max' => 4.0, 'median' => 3.9]],
    $curMid, $fineCaps, ['outside_present' => true]);
same('punto exterior con separación → apply', $outsideEdge['action'], 'apply');
check('candidato por debajo del punto exterior', $outsideEdge['candidate'] <= 3.65);

$sens = RadarCalibrationPlanner::planMidpoint($insideRef, $scanOk,
    ['detected' => true, 'distance' => ['quality' => 'fair', 'min' => 3.8, 'max' => 4.0, 'median' => 3.9]],
    ['maximum_range' => 3.6, 'radar_sensitivity' => 7], $fineCaps, ['outside_present' => true]);
same('fuera detectado con candidato actual → sensibilidad', $sens['action'], 'apply');
same('baja sensibilidad un paso', $sens['params']['radar_sensitivity'] ?? null, 6);
same('motivo outside_still_detected_sensitivity', $sens['reason'], 'outside_still_detected_sensitivity');

$sensLimit = RadarCalibrationPlanner::planMidpoint($insideRef, $scanOk,
    ['detected' => true, 'distance' => ['quality' => 'fair', 'min' => 3.8, 'max' => 4.0, 'median' => 3.9]],
    ['maximum_range' => 3.6, 'radar_sensitivity' => 0], $fineCaps, ['outside_present' => true]);
same('sensibilidad al mínimo → stop', $sensLimit['action'], 'stop');

$reentry = RadarCalibrationPlanner::planMidpoint($insideRef, $scanOk, null,
    ['maximum_range' => 3.6, 'radar_sensitivity' => 6], $fineCaps, ['reentry_failed' => true]);
same('reentrada fallida → apply', $reentry['action'], 'apply');
same('reentrada fallida sube sensibilidad', $reentry['params']['radar_sensitivity'] ?? null, 7);

$scanBad = RadarCalibrationPlanner::planMidpoint($insideRef,
    ['sufficient' => false, 'boundary_distance' => null, 'reason' => 'no_transition'],
    null, $curMid, $fineCaps);
same('barrido insuficiente → stop', $scanBad['action'], 'stop');
same('motivo scan_insufficient', str_starts_with($scanBad['reason'], 'scan_insufficient:'), true);

$tried = RadarCalibrationPlanner::planMidpoint($insideRef, $scanOk, null, $curMid, $fineCaps,
    ['tried' => [['params' => ['maximum_range' => 3.6]]]]);
same('candidato repetido sin detección fuera → stop', $tried['action'], 'stop');
same('motivo candidate_already_tried', $tried['reason'], 'candidate_already_tried');

$noInside = RadarCalibrationPlanner::planMidpoint(['detected' => false, 'sufficient' => true],
    $scanOk, null, ['maximum_range' => 6.0, 'radar_sensitivity' => 5], $fineCaps,
    ['inside_present' => false]);
same('interior no detectado → sube sensibilidad', $noInside['action'], 'apply');
same('motivo inside_not_detected_sensitivity', $noInside['reason'], 'inside_not_detected_sensitivity');

$tight = RadarCalibrationPlanner::planMidpoint(
    ['detected' => true, 'distance' => ['quality' => 'fair', 'min' => 2.8, 'max' => 3.0, 'median' => 2.9]],
    ['sufficient' => true, 'boundary_distance' => 3.5, 'reason' => 'boundary_found',
        'distance' => ['quality' => 'fair', 'min' => 3.2, 'max' => 3.5, 'median' => 3.4]],
    null, ['maximum_range' => 3.9, 'radar_sensitivity' => 7], $fineCaps);
same('separación mínima → punto medio', $tight['action'], 'apply');
check('candidato dentro de márgenes', $tight['candidate'] >= 3.15 && $tight['candidate'] <= 3.35);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
