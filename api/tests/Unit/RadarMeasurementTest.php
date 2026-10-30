<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarMeasurement;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }

function ev(string $value, int $sec, float $dist, int $max = 5, int $sens = 7): array
{
    $t = (new DateTimeImmutable('2026-10-30T09:00:00Z'))->modify('+' . $sec . ' seconds');
    return [
        'sensor' => 'PRESENCE', 'value' => $value, 'provider' => 'ZIGBEE2MQTT',
        'occurred_at' => $t->format('Y-m-d\TH:i:s.v\Z'),
        'meta' => ['source' => 'zigbee2mqtt', 'settings' => [
            'maximum_range' => $max, 'minimum_range' => 0.6,
            'radar_sensitivity' => $sens, 'target_distance' => $dist,
        ]],
    ];
}

$expected = ['maximum_range' => 5, 'minimum_range' => 0.6, 'radar_sensitivity' => 7];
$start = '2026-10-30T09:00:00.000Z';
$end = '2026-10-30T09:00:20.000Z';

echo "Radar measurement (F102/RF-174)\n";
$empty = RadarMeasurement::build([], $start, $end, 3, $expected);
same('ventana vacía no suficiente', $empty['sufficient'], false);
same('motivo sin reportes', $empty['reason'], 'not_enough_reports');
same('sin detección', $empty['detected'], false);

$events = [];
for ($i = 0; $i < 10; $i++) {
    $events[] = ev($i < 6 ? 'PRESENT' : 'ABSENT', $i * 2, $i < 6 ? 3.0 : 0);
}
$m = RadarMeasurement::build($events, $start, $end, 3, $expected);
same('10 reportes → suficiente', $m['sufficient'], true);
same('detecta por el último estado (ABSENT)', $m['detected'], false);
same('hubo PRESENT en la ventana', $m['any_present'], true);
same('cuenta PRESENT', $m['presence_reports'], 6);
same('cuenta ABSENT', $m['absent_reports'], 4);
same('no continuo', $m['continuous'], false);
same('ventana de 18 s', $m['span_seconds'], 18.0);
same('distancia válida', $m['distance']['valid'], 6);
same('calidad con ceros de ausencia → fair', $m['distance']['quality'], 'fair');

$short = RadarMeasurement::build([ev('PRESENT', 0, 3), ev('PRESENT', 2, 3)], $start, $end, 3, $expected);
same('pocos reportes → insuficiente', $short['sufficient'], false);
same('motivo pocos reportes', $short['reason'], 'not_enough_reports');

$changed = RadarMeasurement::build([
    ev('PRESENT', 0, 3, 5), ev('PRESENT', 2, 3, 4), ev('PRESENT', 4, 3, 4),
    ev('PRESENT', 6, 3, 4), ev('PRESENT', 8, 3, 4), ev('PRESENT', 10, 3, 4),
    ev('PRESENT', 12, 3, 4), ev('PRESENT', 14, 3, 4), ev('PRESENT', 16, 3, 4),
], $start, $end, 3, $expected);
same('cambio de configuración detectado', $changed['config_changed'], true);
same('ventana contaminada no suficiente', $changed['sufficient'], false);
same('motivo config changed', $changed['reason'], 'config_changed');

$outside = [];
for ($i = 0; $i < 12; $i++) {
    $outside[] = ev('ABSENT', $i * 2, 0);
}
$out = RadarMeasurement::build($outside, $start, $end, 3, $expected);
same('ausencia suficiente', $out['sufficient'], true);
same('ausencia no detecta', $out['detected'], false);
same('último valor ABSENT', $out['last_value'], 'ABSENT');
same('sin distancias válidas', $out['distance']['valid'], 0);

$before = RadarMeasurement::build([ev('PRESENT', -5, 3)], $start, $end, 3, $expected);
same('evento anterior a la ventana no cuenta', $before['reports'], 0);
$after = RadarMeasurement::build([ev('PRESENT', 25, 3)], $start, $end, 3, $expected);
same('evento posterior a la ventana no cuenta', $after['reports'], 0);

// ── F103: evidencia event-driven ────────────────────────────────────────
echo "\nF103 · estado sostenido y transiciones\n";
$seedPresent = ['state' => 'PRESENT', 'occurred_at' => '2026-10-30 08:59:50.000',
    'age_seconds' => 10, 'fresh' => true];
$seedAbsent = ['state' => 'ABSENT', 'occurred_at' => '2026-10-30 08:59:50.000',
    'age_seconds' => 10, 'fresh' => true];

$holdPresent = RadarMeasurement::build([], $start, $end, 3, $expected, [], $seedPresent);
same('seed PRESENT + 0 reportes → suficiente', $holdPresent['sufficient'], true);
same('seed PRESENT → evidence_mode state_hold', $holdPresent['evidence_mode'], 'state_hold');
same('seed PRESENT → detected', $holdPresent['detected'], true);
same('seed PRESENT → continuous', $holdPresent['continuous'], true);
same('seed PRESENT → any_present', $holdPresent['any_present'], true);
same('seed PRESENT → outcome INSIDE', RadarMeasurement::outcomeForPhase($holdPresent, 'INSIDE'), true);
same('seed PRESENT → outcome OUTSIDE detecta', RadarMeasurement::outcomeForPhase($holdPresent, 'OUTSIDE_FIXED'), true);
same('seed PRESENT → reentrada sin PRESENT real no vale', RadarMeasurement::outcomeForPhase($holdPresent, 'REENTRY'), false);

$holdAbsent = RadarMeasurement::build([], $start, $end, 3, $expected, [], $seedAbsent);
same('seed ABSENT + 0 reportes → suficiente', $holdAbsent['sufficient'], true);
same('seed ABSENT → no detected', $holdAbsent['detected'], false);
same('seed ABSENT → outcome INSIDE', RadarMeasurement::outcomeForPhase($holdAbsent, 'INSIDE'), false);
same('seed ABSENT → outcome OUTSIDE', RadarMeasurement::outcomeForPhase($holdAbsent, 'OUTSIDE_FIXED'), false);

$staleSeed = ['state' => 'PRESENT', 'occurred_at' => '2026-10-30 08:00:00.000',
    'age_seconds' => 400, 'fresh' => false];
$stale = RadarMeasurement::build([], $start, $end, 3, $expected, [], $staleSeed);
same('seed caducado no es suficiente', $stale['sufficient'], false);
same('seed caducado → motivo', $stale['reason'], 'stale_state');
same('seed caducado no se usa', $stale['seed_used'], false);

$lost = RadarMeasurement::build([ev('ABSENT', 5, 0)], $start, $end, 3, $expected, [], $seedPresent);
same('PRESENT inicial + ABSENT → suficiente', $lost['sufficient'], true);
same('PRESENT inicial + ABSENT → continuidad falsa', $lost['continuous'], false);
same('PRESENT inicial + ABSENT → no detectado al final', $lost['detected'], false);
same('PRESENT inicial + ABSENT → outcome INSIDE', RadarMeasurement::outcomeForPhase($lost, 'INSIDE'), false);
same('PRESENT inicial + ABSENT → outcome OUTSIDE', RadarMeasurement::outcomeForPhase($lost, 'OUTSIDE_FIXED'), false);

$acquired = RadarMeasurement::build([ev('PRESENT', 5, 3)], $start, $end, 3, $expected, [], $seedAbsent);
same('ABSENT inicial + PRESENT → suficiente', $acquired['sufficient'], true);
same('ABSENT inicial + PRESENT → continuidad', $acquired['continuous'], true);
same('ABSENT inicial + PRESENT → detectado', $acquired['detected'], true);
same('ABSENT inicial + PRESENT → outcome INSIDE', RadarMeasurement::outcomeForPhase($acquired, 'INSIDE'), true);
same('ABSENT inicial + PRESENT → outcome OUTSIDE detecta', RadarMeasurement::outcomeForPhase($acquired, 'OUTSIDE_FIXED'), true);

$lostAndBack = RadarMeasurement::build([ev('ABSENT', 5, 0), ev('PRESENT', 10, 3)],
    $start, $end, 3, $expected, [], $seedPresent);
same('pérdida y reaparición → no continuo', $lostAndBack['continuous'], false);
same('pérdida y reaparición → outcome INSIDE falla', RadarMeasurement::outcomeForPhase($lostAndBack, 'INSIDE'), false);

$reentryEmpty = RadarMeasurement::build([], $start, $end, 3, $expected,
    ['require_new_present' => true]);
same('reentrada sin PRESENT real → insuficiente', $reentryEmpty['sufficient'], false);
same('reentrada sin PRESENT → motivo', $reentryEmpty['reason'], 'new_present_required');
same('reentrada sin PRESENT → no detectada', RadarMeasurement::outcomeForPhase($reentryEmpty, 'REENTRY'), false);

$reentryOk = RadarMeasurement::build([ev('PRESENT', 5, 3)], $start, $end, 3, $expected,
    ['require_new_present' => true]);
same('reentrada con PRESENT real → suficiente', $reentryOk['sufficient'], true);
same('reentrada con PRESENT real → detectada', RadarMeasurement::outcomeForPhase($reentryOk, 'REENTRY'), true);

$reentryLeft = RadarMeasurement::build([ev('PRESENT', 5, 3), ev('ABSENT', 10, 0)],
    $start, $end, 3, $expected, ['require_new_present' => true]);
same('reentrada y salida → suficiente', $reentryLeft['sufficient'], true);
same('reentrada y salida → no mantenida', RadarMeasurement::outcomeForPhase($reentryLeft, 'REENTRY'), false);

$shortWindow = RadarMeasurement::build([], $start, '2026-10-30T09:00:05.000Z', 3, $expected, [], $seedPresent);
same('ventana < 10 s con seed → insuficiente', $shortWindow['sufficient'], false);
same('ventana < 10 s → motivo', $shortWindow['reason'], 'window_too_short');

$seedChanged = RadarMeasurement::build([
    ev('PRESENT', 0, 3, 5), ev('PRESENT', 2, 3, 4), ev('PRESENT', 4, 3, 4),
    ev('PRESENT', 6, 3, 4), ev('PRESENT', 8, 3, 4), ev('PRESENT', 10, 3, 4),
    ev('PRESENT', 12, 3, 4), ev('PRESENT', 14, 3, 4), ev('PRESENT', 16, 3, 4),
], $start, $end, 3, $expected, [], $seedPresent);
same('cambio de config invalida también state_hold', $seedChanged['sufficient'], false);
same('cambio de config con seed → motivo', $seedChanged['reason'], 'config_changed');

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
