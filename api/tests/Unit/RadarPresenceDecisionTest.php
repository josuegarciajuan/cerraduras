<?php
declare(strict_types=1);

/**
 * F91/TSF-91.07 — pure radar presence decision.
 *
 * Run:
 *   php tests/Unit/RadarPresenceDecisionTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\PresenceEvent;
use App\Domain\Presence\RadarPresenceDecision;

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void
{
    if ($a === $e) { pass($m); return; }
    fail($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}
function check(string $m, bool $c): void { $c ? pass($m) : fail($m); }

$base = new DateTimeImmutable('2026-10-27T12:00:00Z');
function ev(string $value, int $offset, string $sensor = PresenceEvent::SENSOR_PRESENCE, ?string $id = null): array
{
    global $base;
    $t = $base->modify(($offset >= 0 ? '+' : '') . $offset . ' seconds');
    return [
        'sensor' => $sensor,
        'value' => $value,
        'provider' => PresenceEvent::PROVIDER_ZIGBEE2MQTT,
        'occurred_at' => $t->format('Y-m-d\TH:i:s.v\Z'),
        'source_event_id' => $id ?? ('e-' . $sensor . '-' . $value . '-' . $offset),
    ];
}
function cfg(string $mode, array $over = []): array
{
    return array_merge([
        'mode' => $mode,
        'confirm_reports' => 3,
        'confirm_window_seconds' => 12,
        'min_span_seconds' => 6,
        'hold_seconds' => 20,
        'absent_reports' => 3,
        'absent_window_seconds' => 30,
        'door_window_seconds' => 90,
        'stale_seconds' => 180,
    ], $over);
}
function now(int $offset = 20): DateTimeImmutable
{
    global $base;
    return $base->modify('+' . $offset . ' seconds');
}

echo "Radar presence decision (F91/TSK-91.07)\n";
echo str_repeat("=", 60) . "\n\n";

// radar_only
$one = RadarPresenceDecision::decide([ev('PRESENT', 0)], now(), cfg('radar_only'));
same('1 report → CANDIDATE_PRESENT', $one['state'], 'CANDIDATE_PRESENT');
same('1 report does not apply', $one['apply'], false);
same('1 report reason candidate', $one['discard_reason'], 'candidate');

$three = RadarPresenceDecision::decide(
    [ev('PRESENT', 0), ev('PRESENT', 3), ev('PRESENT', 7)],
    now(),
    cfg('radar_only')
);
same('3 reports spanning 7s → PRESENT_CONFIRMED', $three['state'], 'PRESENT_CONFIRMED');
same('confirmed PRESENT applies', $three['apply'], true);
same('confirmed reports count', $three['window_reports'], 3);

$two = RadarPresenceDecision::decide([ev('PRESENT', 0), ev('PRESENT', 7)], now(), cfg('radar_only'));
same('2 reports → candidate', $two['state'], 'CANDIDATE_PRESENT');

$dup = RadarPresenceDecision::decide(
    [ev('PRESENT', 0, id: 'same'), ev('PRESENT', 3, id: 'same'), ev('PRESENT', 7, id: 'same')],
    now(),
    cfg('radar_only')
);
same('duplicate source ids do not add evidence', $dup['reports'], 1);
same('only duplicates remain candidate', $dup['state'], 'CANDIDATE_PRESENT');

$short = RadarPresenceDecision::decide(
    [ev('PRESENT', 0, id: 'a'), ev('PRESENT', 1, id: 'b'), ev('PRESENT', 2, id: 'c')],
    now(),
    cfg('radar_only')
);
same('3 reports but only 2s span → candidate', $short['state'], 'CANDIDATE_PRESENT');

$gap = RadarPresenceDecision::decide(
    [ev('PRESENT', 0, id: 'a'), ev('PRESENT', 40, id: 'b'), ev('PRESENT', 41, id: 'c'), ev('PRESENT', 42, id: 'd'), ev('PRESENT', 43, id: 'e')],
    now(45),
    cfg('radar_only')
);
check('a gap larger than hold is not confirmed', $gap['apply'] === false);

// radar_door
$noDoor = RadarPresenceDecision::decide([ev('PRESENT', 0), ev('PRESENT', 3), ev('PRESENT', 7)], now(), cfg('radar_door'));
same('radar_door without door → candidate', $noDoor['state'], 'CANDIDATE_PRESENT');
same('radar_door without door reason', $noDoor['discard_reason'], 'uncorroborated');

$withDoor = RadarPresenceDecision::decide(
    [ev('OPEN', -5, PresenceEvent::SENSOR_PROXIMITY), ev('PRESENT', 0), ev('PRESENT', 3), ev('PRESENT', 7)],
    now(),
    cfg('radar_door')
);
same('radar_door with recent OPEN → confirmed', $withDoor['state'], 'PRESENT_CONFIRMED');
same('radar_door door corroborated flag', $withDoor['door_corroborated'], true);

$oldDoor = RadarPresenceDecision::decide(
    [ev('OPEN', -200, PresenceEvent::SENSOR_PROXIMITY), ev('PRESENT', 0), ev('PRESENT', 3)],
    now(),
    cfg('radar_door')
);
same('radar_door with old door → candidate', $oldDoor['state'], 'CANDIDATE_PRESENT');

// camera_fusion
$camNo = RadarPresenceDecision::decide([ev('PRESENT', 0), ev('PRESENT', 3), ev('PRESENT', 7)], now(), cfg('camera_fusion'), false);
same('camera_fusion without physical evidence → candidate', $camNo['state'], 'CANDIDATE_PRESENT');
$camYes = RadarPresenceDecision::decide([ev('PRESENT', 0), ev('PRESENT', 3), ev('PRESENT', 7)], now(), cfg('camera_fusion'), true);
same('camera_fusion with physical evidence → confirmed', $camYes['state'], 'PRESENT_CONFIRMED');

// radar_only_shadow: operational radar_door, shadow radar_only
$shadow = RadarPresenceDecision::decide(
    [ev('PRESENT', 0), ev('PRESENT', 3), ev('PRESENT', 7)],
    now(),
    cfg('radar_only_shadow')
);
same('shadow operational is radar_door candidate', $shadow['state'], 'CANDIDATE_PRESENT');
same('shadow radar_only is confirmed', $shadow['shadow']['state'] ?? null, 'PRESENT_CONFIRMED');
same('shadow disagreement detected', $shadow['shadow']['disagrees'] ?? null, true);

// ABSENT is always applicable
$oneAbsent = RadarPresenceDecision::decide([ev('ABSENT', 0)], now(), cfg('radar_only'));
same('one ABSENT → CANDIDATE_ABSENT', $oneAbsent['state'], 'CANDIDATE_ABSENT');
same('ABSENT always applies', $oneAbsent['apply'], true);
$threeAbsent = RadarPresenceDecision::decide(
    [ev('ABSENT', 0, id: 'a'), ev('ABSENT', 15, id: 'b'), ev('ABSENT', 35, id: 'c')],
    now(40),
    cfg('radar_only')
);
same('3 ABSENT spanning 35s → ABSENT_CONFIRMED', $threeAbsent['state'], 'ABSENT_CONFIRMED');

// Long occupancy: recent reports keep confirming; no 24h cap.
$long = [];
for ($i = 0; $i < 1200; $i++) {
    $long[] = ev('PRESENT', $i * 3, id: 'l' . $i);
}
$longDecision = RadarPresenceDecision::decide($long, now(3605), cfg('radar_only'));
same('continuous occupancy remains confirmed (no time cap)', $longDecision['state'], 'PRESENT_CONFIRMED');

// F91 mixed: door closed → radar_door; door open → radar_only.
$mixedClosed = RadarPresenceDecision::decide(
    [ev('PRESENT', 0), ev('PRESENT', 3), ev('PRESENT', 7)],
    now(),
    cfg('mixed')
);
same('mixed sin puerta → candidate (radar_door)', $mixedClosed['state'], 'CANDIDATE_PRESENT');
same('mixed sin puerta opera radar_door', $mixedClosed['operational_mode'], 'radar_door');

$mixedClosedDoor = RadarPresenceDecision::decide(
    [ev('OPEN', -5, PresenceEvent::SENSOR_PROXIMITY), ev('PRESENT', 0), ev('PRESENT', 3), ev('PRESENT', 7)],
    now(),
    cfg('mixed')
);
same('mixed puerta reciente → confirmed', $mixedClosedDoor['state'], 'PRESENT_CONFIRMED');
same('mixed puerta reciente opera radar_only (puerta abierta)', $mixedClosedDoor['operational_mode'], 'radar_only');

$mixedDoorClosed = RadarPresenceDecision::decide(
    [
        ev('OPEN', -12, PresenceEvent::SENSOR_PROXIMITY),
        ev('CLOSED', -10, PresenceEvent::SENSOR_PROXIMITY),
        ev('PRESENT', 0), ev('PRESENT', 3), ev('PRESENT', 7),
    ],
    now(),
    cfg('mixed')
);
same('mixed puerta cerrada tras apertura → radar_door', $mixedDoorClosed['operational_mode'], 'radar_door');
same('mixed puerta cerrada tras apertura → confirmed', $mixedDoorClosed['state'], 'PRESENT_CONFIRMED');

$mixedOpen = RadarPresenceDecision::decide(
    [ev('OPEN', -200, PresenceEvent::SENSOR_PROXIMITY), ev('PRESENT', 0), ev('PRESENT', 3), ev('PRESENT', 7)],
    now(),
    cfg('mixed')
);
same('mixed puerta abierta → radar_only confirmed', $mixedOpen['state'], 'PRESENT_CONFIRMED');
same('mixed puerta abierta opera radar_only', $mixedOpen['operational_mode'], 'radar_only');

$mixedCurrentOpen = RadarPresenceDecision::decide(
    [ev('PRESENT', 0), ev('PRESENT', 3), ev('PRESENT', 7)],
    now(),
    cfg('mixed'),
    false,
    PresenceEvent::VALUE_OPEN
);
same('mixed con puerta actual OPEN sin evento en historial → radar_only confirmed', $mixedCurrentOpen['state'], 'PRESENT_CONFIRMED');
same('mixed puerta actual OPEN usa radar_only', $mixedCurrentOpen['operational_mode'], 'radar_only');

$mixedCurrentClosed = RadarPresenceDecision::decide(
    [ev('PRESENT', 0), ev('PRESENT', 3), ev('PRESENT', 7)],
    now(),
    cfg('mixed'),
    false,
    PresenceEvent::VALUE_CLOSED
);
same('mixed con puerta actual CLOSED sin apertura → candidate', $mixedCurrentClosed['state'], 'CANDIDATE_PRESENT');

$mixedOpenOne = RadarPresenceDecision::decide(
    [ev('OPEN', -200, PresenceEvent::SENSOR_PROXIMITY), ev('PRESENT', 0)],
    now(),
    cfg('mixed')
);
same('mixed puerta abierta con 1 reporte → candidate', $mixedOpenOne['state'], 'CANDIDATE_PRESENT');

$unknown = RadarPresenceDecision::decide([], now(), cfg('radar_only'));
same('no events → UNKNOWN', $unknown['state'], 'UNKNOWN');
same('no events does not apply', $unknown['apply'], false);

echo "\nTotal: {$passed} passed, {$failed} failed\n\n";
exit($failed === 0 ? 0 : 1);
