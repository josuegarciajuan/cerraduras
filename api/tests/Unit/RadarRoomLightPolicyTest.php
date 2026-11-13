<?php
declare(strict_types=1);

/**
 * Unit tests for RadarRoomLightPolicy (F137).
 *
 * The policy only decides whether a deferred OFF must be scheduled or
 * cancelled. It requires a completed door cycle; a PRESENT or OPEN cancels.
 *
 * Run:
 *   php tests/Unit/RadarRoomLightPolicyTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\PresenceEvent;
use App\Domain\Presence\RadarRoomLightPolicy;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void {
    $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}

echo "Radar room light policy\n";

$P_ = PresenceEvent::SENSOR_PRESENCE;
$D_ = PresenceEvent::SENSOR_PROXIMITY;

same(
    'OPEN cancela el OFF',
    RadarRoomLightPolicy::decide($D_, PresenceEvent::VALUE_OPEN, 'ABSENT', true),
    RadarRoomLightPolicy::ACTION_CANCEL_OFF
);
same(
    'PRESENT cancela el OFF',
    RadarRoomLightPolicy::decide($P_, PresenceEvent::VALUE_PRESENT, 'PRESENT', true),
    RadarRoomLightPolicy::ACTION_CANCEL_OFF
);
same(
    'ABSENT sin ciclo de puerta no programa OFF',
    RadarRoomLightPolicy::decide($P_, PresenceEvent::VALUE_ABSENT, 'ABSENT', false),
    RadarRoomLightPolicy::ACTION_NONE
);
same(
    'ABSENT con ciclo de puerta programa OFF',
    RadarRoomLightPolicy::decide($P_, PresenceEvent::VALUE_ABSENT, 'ABSENT', true),
    RadarRoomLightPolicy::ACTION_SCHEDULE_OFF
);
same(
    'CLOSED con sesión ABSENT y ciclo programa OFF',
    RadarRoomLightPolicy::decide($D_, PresenceEvent::VALUE_CLOSED, 'ABSENT', true),
    RadarRoomLightPolicy::ACTION_SCHEDULE_OFF
);
same(
    'CLOSED con sesión PRESENT no programa OFF',
    RadarRoomLightPolicy::decide($D_, PresenceEvent::VALUE_CLOSED, 'PRESENT', true),
    RadarRoomLightPolicy::ACTION_NONE
);
same(
    'CLOSED con ABSENT pero sin ciclo no programa OFF',
    RadarRoomLightPolicy::decide($D_, PresenceEvent::VALUE_CLOSED, 'ABSENT', false),
    RadarRoomLightPolicy::ACTION_NONE
);
same(
    'sensor desconocido no programa OFF',
    RadarRoomLightPolicy::decide('OTHER', 'ABSENT', 'ABSENT', true),
    RadarRoomLightPolicy::ACTION_NONE
);
same(
    'valores en minúsculas se normalizan',
    RadarRoomLightPolicy::decide('proximity', 'closed', 'absent', true),
    RadarRoomLightPolicy::ACTION_SCHEDULE_OFF
);

same('sin apertura ni cierre no hay ciclo', RadarRoomLightPolicy::hasDoorCycle(null, null), false);
same('sin cierre no hay ciclo', RadarRoomLightPolicy::hasDoorCycle('2026-11-13 10:00:00.000', null), false);
same(
    'cierre anterior a apertura no es ciclo',
    RadarRoomLightPolicy::hasDoorCycle('2026-11-13 10:00:05.000', '2026-11-13 10:00:01.000'),
    false
);
same(
    'mismo instante cuenta como ciclo',
    RadarRoomLightPolicy::hasDoorCycle('2026-11-13 10:00:01.000', '2026-11-13 10:00:01.000'),
    true
);
same(
    'cierre posterior es ciclo',
    RadarRoomLightPolicy::hasDoorCycle('2026-11-13 10:00:01.000', '2026-11-13 10:00:05.000'),
    true
);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
