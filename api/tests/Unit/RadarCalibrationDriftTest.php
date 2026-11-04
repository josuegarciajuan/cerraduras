<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarCalibrationDrift;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }

echo "Radar calibration drift\n";

$saved = [
    'maximum_range' => 3.0,
    'minimum_range' => 0.6,
    'radar_sensitivity' => 7,
    'detection_delay' => 0.1,
    'fading_time' => 5.0,
];
$reported = $saved;

$applied = RadarCalibrationDrift::evaluate($saved, $reported);
same('igual → applied', $applied['status'], RadarCalibrationDrift::STATUS_APPLIED);
same('applied sin diferencias', count($applied['differences']), 0);

$missing = RadarCalibrationDrift::evaluate(null, $reported);
same('sin guardada → missing', $missing['status'], RadarCalibrationDrift::STATUS_MISSING);
same('missing sin saved', $missing['saved'], null);

$unknown = RadarCalibrationDrift::evaluate($saved, []);
same('sin reportada → unknown', $unknown['status'], RadarCalibrationDrift::STATUS_UNKNOWN);

$drift = RadarCalibrationDrift::evaluate($saved, ['maximum_range' => 6.0] + $reported);
same('máximo distinto → mismatch', $drift['status'], RadarCalibrationDrift::STATUS_MISMATCH);
same('una diferencia', count($drift['differences']), 1);
same('diferencia clave', $drift['differences'][0]['key'], 'maximum_range');
same('diferencia guardada', $drift['differences'][0]['saved'], 3.0);
same('diferencia reportada', $drift['differences'][0]['reported'], 6.0);

$strings = RadarCalibrationDrift::evaluate(
    ['maximum_range' => '3', 'minimum_range' => '0.6', 'radar_sensitivity' => '7', 'detection_delay' => '0.1', 'fading_time' => '5'],
    $reported
);
same('números como texto → applied', $strings['status'], RadarCalibrationDrift::STATUS_APPLIED);

$partial = RadarCalibrationDrift::evaluate($saved, ['maximum_range' => 3.0]);
same('reportada incompleta → unknown', $partial['status'], RadarCalibrationDrift::STATUS_UNKNOWN);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
