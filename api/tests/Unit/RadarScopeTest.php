<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarScope;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }
function near(string $m, float $a, float $e, float $tol = 0.001): void { abs($a - $e) <= $tol ? ok($m) : ko($m . ' — expected ' . $e . ', got ' . $a); }

function hasCode(array $diagnosis, string $code): bool {
    foreach ($diagnosis as $item) {
        if (($item['code'] ?? '') === $code) return true;
    }
    return false;
}

echo "Radar scope\n";

$settings = [
    'maximum_range' => 3.0,
    'minimum_range' => 0.6,
    'radar_sensitivity' => 7,
    'detection_delay' => 0.1,
    'fading_time' => 5.0,
];
$distances = [
    ['received_at' => '2026-11-11T10:00:00Z', 'distance_m' => 1.0],
    ['received_at' => '2026-11-11T10:00:03Z', 'distance_m' => 1.2],
    ['received_at' => '2026-11-11T10:00:06Z', 'distance_m' => 1.5],
    ['received_at' => '2026-11-11T10:00:09Z', 'distance_m' => 1.8],
    ['received_at' => '2026-11-11T10:00:12Z', 'distance_m' => 2.4],
];
$presence = [
    ['received_at' => '2026-11-11T10:00:00Z', 'value' => 'PRESENT', 'marker' => 'a'],
    ['received_at' => '2026-11-11T10:00:10Z', 'value' => 'ABSENT', 'marker' => 'b'],
];
$raw = ['state' => 'ABSENT', 'age_seconds' => 1, 'stale' => false];
$options = [
    'now' => '2026-11-11T10:00:13Z',
    'window_seconds' => 60,
    'settings_valid' => true,
    'presence_mode' => 'mixed',
    'door_state' => 'CLOSED',
];

$scope = RadarScope::build($settings, $distances, $presence, $raw, $options);
same('estado del scope', $scope['status'], RadarScope::STATUS_ATTENTION);
same('reportes DP9', $scope['stats']['reports'], 5);
same('última presencia', $scope['stats']['latest_presence'], 'ABSENT');
same('hay movimiento reciente', $scope['stats']['motion_recent'], true);
near('mediana de distancias', (float) $scope['stats']['median'], 1.5);
near('p90 de distancias', (float) $scope['stats']['p90'], 2.16, 0.01);
same('borde encontrado', $scope['boundary']['found'], true);
near('sugerencia de alcance por borde', (float) $scope['suggestions']['maximum_range']['value'], 1.20);
same('motivo sugerencia', $scope['suggestions']['maximum_range']['reason'], 'walk_boundary');
check('diagnóstico motion_without_presence', hasCode($scope['diagnosis'], 'motion_without_presence'));
check('diagnóstico boundary_measured', hasCode($scope['diagnosis'], 'boundary_measured'));
near('escala de ejes', (float) $scope['axes']['max_m'], 3.0);
same('serie recortada', count($scope['series']), 5);
same('presencia en serie', count($scope['presence_series']), 2);

// Configuración inválida.
$invalid = RadarScope::build($settings, [], [], ['state' => 'UNKNOWN', 'stale' => false], [
    'now' => '2026-11-11T10:00:13Z',
    'settings_valid' => false,
    'settings_invalid_reason' => 'maximum_range_out_of_bounds',
]);
same('config inválida → blocked', $invalid['status'], RadarScope::STATUS_BLOCKED);
check('diagnóstico settings_invalid', hasCode($invalid['diagnosis'], 'settings_invalid'));

// Radar sin datos.
$stale = RadarScope::build($settings, [], [], ['state' => 'UNKNOWN', 'age_seconds' => 400, 'stale' => true], [
    'now' => '2026-11-11T10:00:13Z',
]);
same('radar sin datos → blocked', $stale['status'], RadarScope::STATUS_BLOCKED);
check('diagnóstico no_reports', hasCode($stale['diagnosis'], 'no_reports'));

// Presencia sin ecos.
$presenceNoMotion = RadarScope::build($settings, [], [
    ['received_at' => '2026-11-11T10:00:00Z', 'value' => 'PRESENT', 'marker' => 'a'],
], ['state' => 'PRESENT', 'age_seconds' => 1, 'stale' => false], $options);
check('diagnóstico presence_without_motion', hasCode($presenceNoMotion['diagnosis'], 'presence_without_motion'));

// Fusión que puede descartar el PRESENT.
$fusion = RadarScope::build($settings, $distances, $presence, $raw, [
    'now' => '2026-11-11T10:00:13Z',
    'presence_mode' => 'camera_fusion',
    'discarded_uncorroborated' => 3,
]);
check('diagnóstico fusion_can_discard', hasCode($fusion['diagnosis'], 'fusion_can_discard'));
check('diagnóstico presence_discarded', hasCode($fusion['diagnosis'], 'presence_discarded'));

// Sin transición: sugerencia por huella observada.
$footprint = RadarScope::build($settings, $distances, [], ['state' => 'ABSENT', 'age_seconds' => 1, 'stale' => false], [
    'now' => '2026-11-11T10:00:13Z',
]);
same('sugerencia sin borde', $footprint['suggestions']['maximum_range']['reason'], 'observed_footprint');
check('sugerencia sin borde tiene valor', $footprint['suggestions']['maximum_range']['value'] !== null);

// Puerta abierta: aviso de pasillo.
$open = RadarScope::build($settings, $distances, $presence, $raw, [
    'now' => '2026-11-11T10:00:13Z',
    'door_state' => 'OPEN',
]);
check('diagnóstico door_open', hasCode($open['diagnosis'], 'door_open'));

// fading_time muy bajo.
$fading = RadarScope::build(['fading_time' => 2.0] + $settings, $distances, $presence, $raw, [
    'now' => '2026-11-11T10:00:13Z',
]);
check('diagnóstico fading_too_low', hasCode($fading['diagnosis'], 'fading_too_low'));

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
