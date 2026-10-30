<?php
declare(strict_types=1);

/**
 * F105/RF-221 — medición por punto con lecturas nuevas y coherentes.
 *
 * Run:
 *   php tests/Unit/RadarPointMeasurementTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarPointMeasurement;

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

const F105_SETTINGS = [
    'maximum_range' => 3.9, 'minimum_range' => 0.6, 'radar_sensitivity' => 7,
    'fading_time' => 15.5, 'detection_delay' => 0.1,
];

/** @return array<string,mixed> */
function ev(int $sec, float $distance, string $value = 'PRESENT', array $settings = F105_SETTINGS, string $source = 'zigbee2mqtt'): array
{
    return [
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', 1700000000 + $sec),
        'received_at' => gmdate('Y-m-d\TH:i:s\Z', 1700000000 + $sec),
        'sensor' => 'PRESENCE',
        'provider' => 'ZIGBEE2MQTT',
        'value' => $value,
        'source_event_id' => 'e-' . $sec . '-' . $distance . '-' . $value,
        'meta' => ['source' => $source, 'settings' => $settings + ['target_distance' => $distance]],
    ];
}

function analyze(array $events, array $opts = []): array
{
    return RadarPointMeasurement::analyze(
        $events,
        gmdate('Y-m-d\TH:i:s\Z', 1700000000),
        gmdate('Y-m-d\TH:i:s\Z', 1700000030),
        1,
        F105_SETTINGS,
        $opts + ['min_samples' => 2, 'tolerance' => 0.30]
    );
}

echo "Radar point measurement (F105)\n";

$a = analyze([ev(2, 2.84), ev(8, 2.90), ev(14, 2.87)]);
same('tres lecturas nuevas → aceptado', $a['status'], 'accepted');
same('intentos mínimos', $a['fresh_readings'], 3);
same('media de las tres', $a['mean'], 2.87);
same('dispersión 6 cm', $a['spread'], 0.06);

$repeated = analyze([ev(2, 2.84), ev(8, 2.84), ev(14, 2.84)]);
same('valor repetido no cuenta como nueva', $repeated['status'], 'insufficient');
same('solo una lectura nueva', $repeated['fresh_readings'], 1);
same('motivo pocas nuevas', $repeated['reason'], 'not_enough_fresh_readings');
same('ayuda mover brazos', $repeated['help'], 'move_arms');
same('las repetidas se conservan como evidencia', count($repeated['observations']), 3);

$afterZero = analyze([ev(2, 2.84), ev(5, 0, 'ABSENT'), ev(9, 2.84)]);
same('misma distancia tras cero → nueva', $afterZero['status'], 'accepted');
same('dos lecturas nuevas', $afterZero['fresh_readings'], 2);
same('media', $afterZero['mean'], 2.84);

$outlier = analyze([ev(2, 2.78), ev(6, 2.84), ev(10, 2.89), ev(14, 3.70)]);
same('tres coherentes + atípica → aceptado', $outlier['status'], 'accepted');
same('conjunto de tres', count($outlier['cluster']), 3);
check('media sin la atípica', abs((float) $outlier['mean'] - 2.837) < 0.0001);
same('una lectura excluida', count($outlier['excluded']), 1);

$incoherent = analyze([ev(2, 2.40), ev(8, 3.36)]);
same('dos nuevas muy dispersas → inestable', $incoherent['status'], 'unstable');
same('motivo incoherente', $incoherent['reason'], 'incoherent_readings');
same('sin media', $incoherent['mean'], null);

$zeroOnly = analyze([ev(2, 0), ev(8, 0)]);
same('solo ceros → insuficiente', $zeroOnly['status'], 'insufficient');
check('ceros registrados', count($zeroOnly['observations']) === 2);

$empty = analyze([]);
same('sin reportes → sin detección', $empty['status'], 'not_detected');
same('no usa semilla', $empty['fresh_readings'], 0);

$config = analyze([ev(2, 2.84, 'PRESENT', ['maximum_range' => 6.0])]);
same('cambio de configuración → no aceptado', $config['status'], 'config_changed');

$resync = analyze([ev(2, 2.84, 'PRESENT', F105_SETTINGS, 'resync'), ev(8, 2.90)]);
same('resync no cuenta', $resync['fresh_readings'], 1);

$dupe = analyze([ev(2, 2.84), ev(2, 2.84)]);
same('mismo instante y valor → duplicado', count($dupe['observations']), 2);
same('duplicado no completa', $dupe['fresh_readings'], 1);

$cluster = RadarPointMeasurement::coherentCluster([2.40, 2.84, 2.90, 3.36], 0.30);
same('cluster determinista elige el mayor', count($cluster), 2);

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
