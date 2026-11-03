<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  OK {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  FAIL {$m}\n"; }
function truthy(string $m, bool $c): void { $c ? ok($m) : ko($m); }

$api = dirname(__DIR__, 2);
$service = (string) file_get_contents($api . '/src/Infrastructure/Zigbee/ZigbeeRadarCalibrationService.php');
$controller = (string) file_get_contents($api . '/src/Http/Controllers/SensorWizardController.php');

$baseline = [
    'maximum_range' => 6.0,
    'minimum_range' => 0.6,
    'radar_sensitivity' => 7,
    'detection_delay' => 0.1,
    'fading_time' => 15.5,
];

echo "Radar baseline trust (F112)\n";

// ── comparador ───────────────────────────────────────────────────────
truthy('coincide exacto', ZigbeeRadarCalibrationService::settingsMatch($baseline, $baseline));
truthy('coincide con enteros', ZigbeeRadarCalibrationService::settingsMatch(
    ['maximum_range' => 6, 'radar_sensitivity' => 7],
    ['maximum_range' => 6.0, 'radar_sensitivity' => 7]
));
truthy('coincide con cadenas numéricas', ZigbeeRadarCalibrationService::settingsMatch(
    ['maximum_range' => '6.0'],
    ['maximum_range' => 6]
));
truthy('tolera 0.001', ZigbeeRadarCalibrationService::settingsMatch(
    ['maximum_range' => 6.0],
    ['maximum_range' => 6.0009]
));
truthy('rechaza 0.01', !ZigbeeRadarCalibrationService::settingsMatch(
    ['maximum_range' => 6.0],
    ['maximum_range' => 6.01]
));
truthy('rechaza distinto', !ZigbeeRadarCalibrationService::settingsMatch(
    ['maximum_range' => 6.0],
    ['maximum_range' => 3.45]
));
truthy('rechaza clave ausente', !ZigbeeRadarCalibrationService::settingsMatch(
    ['maximum_range' => 6.0],
    ['minimum_range' => 0.6]
));
truthy('rechaza no numérico', !ZigbeeRadarCalibrationService::settingsMatch(
    ['maximum_range' => 6.0],
    ['maximum_range' => 'seis']
));
truthy('rechaza comandos vacíos', !ZigbeeRadarCalibrationService::settingsMatch([], $baseline));
truthy('rechaza settings vacíos', !ZigbeeRadarCalibrationService::settingsMatch($baseline, []));
truthy('ignora claves extra del reporte', ZigbeeRadarCalibrationService::settingsMatch(
    ['maximum_range' => 6.0],
    ['maximum_range' => 6.0, 'illuminance' => 40, 'target_distance' => 1.2]
));

// ── cableado ─────────────────────────────────────────────────────────
truthy('existe confirmacion por estado guardado', str_contains($service, "confirmedBy = 'stored_state'"));
truthy('variable de confianza', str_contains($service, 'RADAR_BASELINE_TRUST_SECONDS'));
truthy('solo se relaja al restaurar base', str_contains($service, '!$applied && !$persist'));
truthy('informa already_at_target', str_contains($service, 'already_at_target'));
truthy('no publica si ya está en la base', substr_count($service, 'already_at_target') >= 2);
truthy('confirmado sin publicar cuando ya coincide', str_contains($service, "'published_at' => null,"));
truthy('informa confirmed_by', str_contains($service, "'confirmed_by' => \$confirmedBy"));
truthy('la calibracion real sigue exigiendo push', str_contains($controller, 'resetRadarToBaseline'));
truthy('run/start sigue comprobando applied', str_contains($controller, "if ((\$baseline['ok'] ?? false) !== true)"));
truthy('sin polling nuevo', !str_contains($service, 'setInterval') && !str_contains($service, 'while (true)'));

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
