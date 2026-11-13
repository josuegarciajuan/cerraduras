<?php
declare(strict_types=1);

/**
 * F138 — integración pura del motor de ocupación (sin BD).
 *
 * Run: php tests/Unit/StandardOccupancyEngineTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\IotSession;
use App\Domain\Presence\PresenceEvent;
use App\Domain\Presence\StandardOccupancyEngine;
use App\Domain\Presence\StandardOccupancyPolicy;
use App\Domain\Rooms\RoomType;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  {$label}\n";
        return;
    }
    $fail++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function session(): IotSession
{
    return new IotSession(
        1, 12, null,
        IotSession::DOOR_CLOSED,
        IotSession::PRESENCE_ABSENT,
        null, null, null, null,
        '2026-11-13 16:00:00.000'
    );
}

function event(string $sensor, string $value, string $at): array
{
    return [
        'room_id' => 12,
        'sensor' => $sensor,
        'value' => $value,
        'provider' => PresenceEvent::PROVIDER_ZIGBEE2MQTT,
        'occurred_at' => $at,
        'source_event_id' => $sensor . '-' . $value . '-' . $at,
        'meta' => [],
    ];
}

$rt = new RoomType(1, 'STANDARD', 'Standard', 30, 15, 20, 30, 20);

echo "StandardOccupancyEngine (F138)\n";

$s = session();
$now = new DateTimeImmutable('2026-11-13T16:00:01Z', new DateTimeZone('UTC'));

$r = StandardOccupancyEngine::apply(
    $s,
    event(PresenceEvent::SENSOR_PROXIMITY, PresenceEvent::VALUE_OPEN, '2026-11-13T16:00:01Z'),
    null,
    $rt,
    $now
);
check('OPEN → DOOR_OPEN', $s->occupancyState === StandardOccupancyPolicy::STATE_DOOR_OPEN);
check('OPEN → luz ON', $s->lightDesired === 'ON');
check('OPEN → puerta OPEN', $s->doorState === IotSession::DOOR_OPEN);

$r = StandardOccupancyEngine::apply(
    $s,
    event(PresenceEvent::SENSOR_PRESENCE, PresenceEvent::VALUE_PRESENT, '2026-11-13T16:00:02Z'),
    null,
    $rt,
    new DateTimeImmutable('2026-11-13T16:00:02Z', new DateTimeZone('UTC'))
);
check('PRESENT con puerta abierta → sigue DOOR_OPEN', $s->occupancyState === StandardOccupancyPolicy::STATE_DOOR_OPEN);
check('PRESENT con puerta abierta → presencia PRESENT', $s->presenceState === IotSession::PRESENCE_PRESENT);

$r = StandardOccupancyEngine::apply(
    $s,
    event(PresenceEvent::SENSOR_PROXIMITY, PresenceEvent::VALUE_CLOSED, '2026-11-13T16:00:03Z'),
    null,
    $rt,
    new DateTimeImmutable('2026-11-13T16:00:03Z', new DateTimeZone('UTC'))
);
check('CLOSE con presencia → OCCUPIED', $s->occupancyState === StandardOccupancyPolicy::STATE_OCCUPIED);
check('CLOSE con presencia → outcome applied', ($s->presenceOutcome['outcome'] ?? '') === 'applied');

$r = StandardOccupancyEngine::apply(
    $s,
    event(PresenceEvent::SENSOR_PRESENCE, PresenceEvent::VALUE_ABSENT, '2026-11-13T16:00:10Z'),
    null,
    $rt,
    new DateTimeImmutable('2026-11-13T16:00:10Z', new DateTimeZone('UTC'))
);
check('ABSENT sin ciclo de salida → UNCERTAIN', $s->occupancyState === StandardOccupancyPolicy::STATE_OCCUPANCY_UNCERTAIN);
check('ABSENT sin ciclo → luz protegida ON', $s->lightDesired === 'ON');
check('ABSENT sin ciclo → no hay OFF programado', $s->lightOffAt === null);

// F138: heartbeat de una sala vacía con radar sano apaga la luz.
$s2 = session();
$s2->occupancyState = StandardOccupancyPolicy::STATE_VACANT_CONFIRMED;
$s2->presenceState = IotSession::PRESENCE_ABSENT;
$s2->lightDesired = 'ON';
$heartbeat = event(PresenceEvent::SENSOR_PRESENCE, PresenceEvent::VALUE_ABSENT, '2026-11-13T16:01:00Z');
$heartbeat['meta'] = ['presence_report' => ['value' => 'ABSENT', 'marker' => 'p1|test|1|ABSENT|push']];
StandardOccupancyEngine::apply(
    $s2,
    $heartbeat,
    null,
    $rt,
    new DateTimeImmutable('2026-11-13T16:01:00Z', new DateTimeZone('UTC'))
);
check('heartbeat en vacío → luz OFF deseada', $s2->lightDesired === 'OFF');
check('heartbeat en vacío → OFF programado', $s2->lightOffAt !== null);

// F138: un evento histórico no reescribe estado ni frescura.
$s3 = session();
$s3->occupancyState = StandardOccupancyPolicy::STATE_OCCUPIED;
$s3->presenceState = IotSession::PRESENCE_PRESENT;
$s3->lastPresenceEventAt = '2026-11-13 16:10:00.000';
$staleEvent = event(PresenceEvent::SENSOR_PRESENCE, PresenceEvent::VALUE_ABSENT, '2026-11-13T16:00:00Z');
$staleEvent['meta'] = ['presence_report' => ['value' => 'ABSENT', 'marker' => 'p1|test|old|ABSENT|push']];
$r = StandardOccupancyEngine::apply(
    $s3,
    $staleEvent,
    null,
    $rt,
    new DateTimeImmutable('2026-11-13T16:10:01Z', new DateTimeZone('UTC'))
);
check('evento anterior → stale', ($r['reason'] ?? '') === 'stale');
check('evento anterior no cambia ocupación', $s3->occupancyState === StandardOccupancyPolicy::STATE_OCCUPIED);
check('evento anterior no cambia presencia', $s3->presenceState === IotSession::PRESENCE_PRESENT);

$s4 = session();
$tooOld = event(PresenceEvent::SENSOR_PRESENCE, PresenceEvent::VALUE_ABSENT, '2026-11-13T14:00:00Z');
$tooOld['meta'] = ['presence_report' => ['value' => 'ABSENT', 'marker' => 'p1|test|old2|ABSENT|push']];
$r = StandardOccupancyEngine::apply(
    $s4,
    $tooOld,
    null,
    $rt,
    new DateTimeImmutable('2026-11-13T16:10:00Z', new DateTimeZone('UTC'))
);
check('evento demasiado antiguo → too_old', ($r['reason'] ?? '') === 'too_old');
check('evento demasiado antiguo no cambia estado', $s4->occupancyState === null);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
