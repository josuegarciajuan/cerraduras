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

$overlap = ['quality' => 'good', 'min' => 3.05, 'max' => 3.2, 'median' => 3.1];
$plan6 = RadarCalibrationPlanner::plan($insideGood, $overlap, $settings, $caps,
    ['inside_present' => true, 'outside_present' => true]);
same('rangos solapados → comportamiento', $plan6['reason'], 'outside_detected');

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
