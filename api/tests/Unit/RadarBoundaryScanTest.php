<?php
declare(strict_types=1);

/**
 * F104/RF-213 — evidencia del barrido de salida del radar.
 *
 * Run:
 *   php tests/Unit/RadarBoundaryScanTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarBoundaryScan;

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

function bs(string $value, int $ms, ?float $distance = null, array $settings = [], string $source = 'zigbee2mqtt'): array
{
    $base = (new DateTimeImmutable('2026-10-30T12:00:00Z'))->modify('+' . $ms . ' milliseconds');
    $meta = ['source' => $source];
    if ($distance !== null || $settings !== []) {
        $meta['settings'] = array_merge(['target_distance' => $distance ?? 0], $settings);
    }
    return [
        'sensor' => 'PRESENCE',
        'value' => $value,
        'provider' => 'ZIGBEE2MQTT',
        'occurred_at' => $base->format('Y-m-d\TH:i:s.v\Z'),
        'meta' => $meta,
    ];
}

$expected = ['maximum_range' => 3.9, 'minimum_range' => 0.6, 'radar_sensitivity' => 7,
    'fading_time' => 15.5, 'detection_delay' => 0.1];
$start = '2026-10-30T12:00:00.000Z';
$end = '2026-10-30T12:00:20.000Z';

echo "Radar boundary scan (F104)\n";

$events = [
    bs('PRESENT', 0, 2.5),
    bs('PRESENT', 3000, 3.0),
    bs('PRESENT', 6000, 3.5),
    bs('ABSENT', 9000),
    bs('ABSENT', 12000),
];
$r = RadarBoundaryScan::summarize($events, $start, $end, 1, $expected);
same('frontera encontrada', $r['reason'], 'boundary_found');
same('suficiente', $r['sufficient'], true);
same('frontera = última distancia detectada', $r['boundary_distance'], 3.5);
same('presentes', $r['presents'], 3);
same('ausentes', $r['absents'], 2);
same('primer ausente', $r['first_absent_at'], '2026-10-30T12:00:09.000Z');
check('ausencia mantenida', $r['clear_seconds'] >= 10.0);
same('version de configuración', $r['config_version'], 1);

$noTransition = RadarBoundaryScan::summarize([
    bs('PRESENT', 0, 2.5), bs('PRESENT', 3000, 3.0),
], $start, $end, 1, $expected);
same('sin transición → no_transition', $noTransition['reason'], 'no_transition');
same('sin transición no es suficiente', $noTransition['sufficient'], false);

$noPresence = RadarBoundaryScan::summarize([
    bs('ABSENT', 0), bs('ABSENT', 3000),
], $start, $end, 1, $expected);
same('sin presencia → no_presence', $noPresence['reason'], 'no_presence');

$noDistance = RadarBoundaryScan::summarize([
    bs('PRESENT', 0), bs('ABSENT', 3000), bs('ABSENT', 6000),
], $start, $end, 1, $expected);
same('transición sin distancia → no_distance', $noDistance['reason'], 'no_distance');
same('frontera nula', $noDistance['boundary_distance'], null);

$zeros = RadarBoundaryScan::summarize([
    bs('PRESENT', 0, 0), bs('PRESENT', 2000, 0), bs('ABSENT', 5000), bs('ABSENT', 9000),
], $start, $end, 1, $expected);
same('distancias cero no fabrican frontera', $zeros['reason'], 'no_distance');

$short = RadarBoundaryScan::summarize([
    bs('PRESENT', 0, 3.0), bs('ABSENT', 4000),
], $start, '2026-10-30T12:00:05.000Z', 1, $expected);
same('ausencia corta → window_too_short', $short['reason'], 'window_too_short');

$configChanged = RadarBoundaryScan::summarize([
    bs('PRESENT', 0, 2.5, ['maximum_range' => 6.0]),
    bs('PRESENT', 3000, 3.0, ['maximum_range' => 6.0]),
    bs('ABSENT', 9000),
    bs('ABSENT', 12000),
], $start, $end, 1, $expected);
same('configuración distinta → config_changed', $configChanged['reason'], 'config_changed');

$resync = RadarBoundaryScan::summarize([
    bs('PRESENT', 0, 2.5, [], 'resync'),
    bs('PRESENT', 3000, 3.2),
    bs('ABSENT', 9000),
    bs('ABSENT', 12000),
], $start, $end, 1, $expected);
same('ignora resync para la frontera', $resync['boundary_distance'], 3.2);
same('no cuenta resync como presente', $resync['presents'], 1);

$tail = RadarBoundaryScan::summarize([
    bs('PRESENT', 0, 4.0),
    bs('PRESENT', 2000, 3.6),
    bs('PRESENT', 4000, 3.1),
    bs('PRESENT', 6000, 3.2),
    bs('ABSENT', 9000),
    bs('ABSENT', 12000),
], $start, $end, 1, $expected);
same('usa el máximo de las 3 últimas muestras', $tail['boundary_distance'], 3.6);

$unordered = RadarBoundaryScan::summarize([
    bs('ABSENT', 12000),
    bs('PRESENT', 3000, 3.0),
    bs('PRESENT', 0, 2.5),
    bs('ABSENT', 9000),
], $start, $end, 1, $expected);
same('ordena por milisegundos', $unordered['boundary_distance'], 3.0);
same('ordena y detecta transición', $unordered['first_absent_at'], '2026-10-30T12:00:09.000Z');

$seed = RadarBoundaryScan::summarize([
    bs('ABSENT', 3000),
    bs('ABSENT', 6000),
], $start, $end, 1, $expected, [
    'state' => 'PRESENT', 'occurred_at' => '2026-10-30T12:00:00.000Z',
    'age_seconds' => 2, 'fresh' => true,
]);
same('seed PRESENT sin distancia → no_distance', $seed['reason'], 'no_distance');
same('seed usado', $seed['seed_used'], true);

$staleSeed = RadarBoundaryScan::summarize([
    bs('ABSENT', 3000), bs('ABSENT', 6000),
], $start, $end, 1, $expected, [
    'state' => 'PRESENT', 'occurred_at' => '2026-10-30T11:59:00.000Z',
    'age_seconds' => 60, 'fresh' => false,
]);
same('seed viejo → stale_state', $staleSeed['reason'], 'stale_state');

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
