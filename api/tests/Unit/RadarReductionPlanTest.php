<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarCalibrationPlanner;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  OK {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  FAIL {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void
{
    if ($a === $e) { ok($m); return; }
    ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}
function truthy(string $m, bool $c): void { $c ? ok($m) : ko($m); }

$caps = ['range_min' => 0.6, 'range_max' => 9.5, 'range_step' => 0.15, 'sens_min' => 1, 'sens_max' => 10];
$api = dirname(__DIR__, 2);
$controller = (string) file_get_contents($api . '/src/Http/Controllers/SensorWizardController.php');
$planner = (string) file_get_contents($api . '/src/Domain/Sensors/RadarCalibrationPlanner.php');
$js = (string) file_get_contents($api . '/public/assets/sensor-wizard-v2.js');

echo "Radar reduction plan (F112)\n";

// ── secuencia real de la corrida ─────────────────────────────────────
$a1 = RadarCalibrationPlanner::nextReduction(3.60, 3.60, 0, $caps);
truthy('ronda 1 permitida', $a1['ok']);
same('ronda 1: 3,60 → 3,45', $a1['candidate'], 3.45);
same('suelo 1,00 m', $a1['floor'], 2.60);

$a2 = RadarCalibrationPlanner::nextReduction(3.45, 3.60, 1, $caps);
same('ronda 2: 3,45 → 3,30', $a2['candidate'], 3.30);

$a3 = RadarCalibrationPlanner::nextReduction(3.30, 3.60, 2, $caps);
same('ronda 3: 3,30 → 3,15', $a3['candidate'], 3.15);

$a4 = RadarCalibrationPlanner::nextReduction(3.15, 3.60, 3, $caps);
same('ronda 4: 3,15 → 3,00', $a4['candidate'], 3.00);

$a5 = RadarCalibrationPlanner::nextReduction(3.00, 3.60, 4, $caps);
truthy('ronda 5 bloqueada', !$a5['ok']);
same('motivo: rondas agotadas', $a5['reason'], 'max_attempts_reached');

// ── reducción no está limitada por la referencia interior ────────────
$belowRef = RadarCalibrationPlanner::nextReduction(3.60, 3.60, 0, $caps);
truthy('3,45 por debajo de 3,475 sigue permitido', $belowRef['ok'] && $belowRef['candidate'] === 3.45);

// ── suelo y límites ──────────────────────────────────────────────────
$atFloor = RadarCalibrationPlanner::nextReduction(2.70, 3.60, 3, $caps);
truthy('por debajo del suelo se detiene', !$atFloor['ok']);
same('motivo: límite de reducción', $atFloor['reason'], 'reduction_limit_reached');

$custom = RadarCalibrationPlanner::nextReduction(3.15, 3.60, 1, $caps, 4, 0.5);
truthy('suelo personalizado bloquea', !$custom['ok']);
same('suelo 0,50 → 3,10', $custom['floor'], 3.10);
same('motivo personalizado', $custom['reason'], 'reduction_limit_reached');

$nearMinCaps = ['range_min' => 2.0] + $caps;
$minStep = RadarCalibrationPlanner::nextReduction(2.15, 2.15, 0, $nearMinCaps);
truthy('llega al mínimo', $minStep['ok']);
same('mínimo 2,00', $minStep['candidate'], 2.0);
$blocked = RadarCalibrationPlanner::nextReduction(2.00, 2.15, 1, $nearMinCaps);
truthy('no baja del mínimo', !$blocked['ok']);
same('motivo: mínimo del dispositivo', $blocked['reason'], 'range_min_reached');

// ── rejilla y valores no alineados ───────────────────────────────────
$snap = RadarCalibrationPlanner::nextReduction(3.47, 3.47, 0, $caps);
truthy('alinea a la rejilla', $snap['ok']);
truthy('candidato en rejilla de 0,15', abs((($snap['candidate'] - 0.6) / 0.15) - round(($snap['candidate'] - 0.6) / 0.15)) < 0.0001);
truthy('candidato menor que actual', $snap['candidate'] < 3.47);

// ── compatibilidad del método anterior ───────────────────────────────
same('lowerCandidate sin suelo', RadarCalibrationPlanner::lowerCandidate(3.60, null, $caps), 3.45);
same('lowerCandidate respeta suelo', RadarCalibrationPlanner::lowerCandidate(3.60, 3.50, $caps), null);
same('lowerCandidate no repite', RadarCalibrationPlanner::lowerCandidate(0.60, null, $caps), null);

// ── cableado ─────────────────────────────────────────────────────────
truthy('controlador usa nextReduction', str_contains($controller, 'RadarCalibrationPlanner::nextReduction'));
truthy('no usa la referencia como suelo', !str_contains($controller, 'lowerCandidate($currentMax, $reference'));
truthy('guarda alcance inicial', str_contains($controller, "'start_range' => \$candidate"));
truthy('limita a 4 rondas', str_contains($controller, "'max_attempts' => 4"));
truthy('limita a 1,00 m', str_contains($controller, "'max_reduction' => 1.0"));
truthy('motivo de rondas agotadas', str_contains($controller, 'outside_reduction_attempts_reached'));
truthy('motivo de límite', str_contains($controller, 'outside_reduction_limit_reached'));
truthy('motivo de mínimo', str_contains($controller, 'outside_range_min_reached'));
truthy('evidencia DP1 en la ventana', str_contains($controller, 'outsidePresenceEvidence'));
truthy('solo refuerza detección', str_contains($controller, "detected_dp1"));
truthy('estado expone inside_only', str_contains($controller, "'inside_only' => \$this->insideOnlyView"));
truthy('panel muestra la ronda', str_contains($js, 'radarInsideOnly'));
truthy('panel muestra evidencia exterior', str_contains($js, 'radarOutsideEvidence'));
truthy('planner documenta suelo', str_contains($planner, 'no la referencia interior'));
truthy('lowerCandidate acepta suelo nulo', str_contains($planner, '?float $floor'));

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
