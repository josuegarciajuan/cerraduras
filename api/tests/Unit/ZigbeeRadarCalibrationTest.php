<?php
declare(strict_types=1);

/**
 * F93/RF-156 — calibración del radar Zigbee.
 *
 * Cubre la parte pura (validación y rejilla) y el contrato del publicador MQTT
 * (allowlist de parámetros). No requiere MQTT, BD ni hardware.
 *
 * Run:
 *   php tests/Unit/ZigbeeRadarCalibrationTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService;

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
function throws(string $m, callable $fn): void
{
    try {
        $fn();
        fail($m . ' — no lanzó excepción');
    } catch (\Throwable $e) {
        pass($m);
    }
}

echo "Zigbee radar calibration (F93/RF-156)\n";

// Rejilla real del ZY-M100: rango 0.15 m, sensibilidad 0–9, delay 0.1 s.
same('maximum_range se ajusta a 0.15 m', ZigbeeRadarCalibrationService::normalizeCommands(['maximum_range' => 1.7]), ['maximum_range' => 1.65]);
same('minimum_range se ajusta a 0.15 m', ZigbeeRadarCalibrationService::normalizeCommands(['minimum_range' => 0.61]), ['minimum_range' => 0.6]);
same('radar_sensitivity es entero', ZigbeeRadarCalibrationService::normalizeCommands(['radar_sensitivity' => 7.4]), ['radar_sensitivity' => 7]);
same('detection_delay se ajusta a 0.1 s', ZigbeeRadarCalibrationService::normalizeCommands(['detection_delay' => 0.26]), ['detection_delay' => 0.3]);
same('fading_time se ajusta a la rejilla 0.5+1s', ZigbeeRadarCalibrationService::normalizeCommands(['fading_time' => 15.6]), ['fading_time' => 15.5]);
same('valores fuera de rango se recortan a la rejilla', ZigbeeRadarCalibrationService::normalizeCommands(['maximum_range' => 99]), ['maximum_range' => 9.45]);
same('claves ajenas se ignoran', ZigbeeRadarCalibrationService::normalizeCommands(['state' => 'ON', 'maximum_range' => 3]), ['maximum_range' => 3.0]);
same('sin parámetros válidos devuelve vacío', ZigbeeRadarCalibrationService::normalizeCommands(['state' => 'ON']), []);
throws('valor no numérico rechazado', static fn () => ZigbeeRadarCalibrationService::normalizeCommands(['maximum_range' => 'abc']));

// F96.5: valores válidos/inválidos del dispositivo
check('settings válidos', \App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService::isValidSettings([
    'maximum_range' => 5.25, 'minimum_range' => 0.6, 'radar_sensitivity' => 7,
]));
check('maximum_range 0.01 inválido', !\App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService::isValidSettings([
    'maximum_range' => 0.01, 'minimum_range' => 0.01, 'radar_sensitivity' => 6,
]));
check('maximum_range 4866048.01 inválido', !\App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService::isValidSettings([
    'maximum_range' => 4866048.01, 'minimum_range' => 0.6, 'radar_sensitivity' => 7,
]));
check('mínimo mayor que máximo inválido', !\App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService::isValidSettings([
    'maximum_range' => 3.0, 'minimum_range' => 3.5, 'radar_sensitivity' => 7,
]));
check('sensibilidad 10 inválida', !\App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService::isValidSettings([
    'maximum_range' => 5.25, 'minimum_range' => 0.6, 'radar_sensitivity' => 10,
]));
same('motivo max fuera de rango', \App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService::settingsInvalidReason([
    'maximum_range' => 0.01, 'minimum_range' => 0.01, 'radar_sensitivity' => 6,
]), 'maximum_range_out_of_bounds');

// Contrato del publicador: no permite escribir estado del relé ni valores raros.
$helper = dirname(__DIR__, 2) . '/bin/zigbee-ingress/publish-json.js';
if (!is_file($helper)) {
    fail('publish-json.js existe');
} else {
    pass('publish-json.js existe');
}
$run = static function (string $payload) use ($helper): array {
    $cmd = 'node ' . escapeshellarg($helper) . ' '
        . escapeshellarg('zigbee2mqtt/test-presencia/set') . ' '
        . escapeshellarg($payload) . ' 1 2>&1';
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
};
[$code, $out] = $run('{"state":"ON"}');
check('rechaza parámetros no permitidos', $code !== 0 && str_contains($out, 'invalid_parameter'), $out);
[$code, $out] = $run('{"maximum_range":"3"}');
check('rechaza valores no numéricos', $code !== 0 && str_contains($out, 'invalid_parameter_value'), $out);
[$code, $out] = $run('[]');
check('rechaza payload que no es objeto', $code !== 0, $out);

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
