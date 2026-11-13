<?php
declare(strict_types=1);

/**
 * F138 — motor único de ocupación STANDARD.
 *
 * Run: php tests/Unit/StandardOccupancyPolicyTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\IotSession;
use App\Domain\Presence\PresenceEvent;
use App\Domain\Presence\StandardOccupancyPolicy as P;

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

function base(array $overrides = []): array
{
    return array_merge([
        'state' => P::STATE_VACANT_CONFIRMED,
        'presence' => IotSession::PRESENCE_ABSENT,
        'sensor' => '',
        'value' => '',
        'door_state' => IotSession::DOOR_CLOSED,
        'cycle_kind' => null,
        'now_ms' => 100000,
        'entry_window_ms' => 20000,
        'presence_guard_ms' => 400,
        'vacancy_guard_ms' => 10000,
        'active_stay' => false,
        'access_context' => false,
        'recent_open' => false,
        'recent_close' => true,
        'entry_confirmed' => false,
        'is_repeat' => false,
        'radar_health' => P::HEALTH_OK,
    ], $overrides);
}

echo "StandardOccupancyPolicy (F138)\n";

// Puerta abierta desde vacío: umbral y luz ON.
$d = P::decide(base([
    'sensor' => PresenceEvent::SENSOR_PROXIMITY,
    'value' => PresenceEvent::VALUE_OPEN,
]));
check('OPEN desde vacío → DOOR_OPEN', $d['state'] === P::STATE_DOOR_OPEN);
check('OPEN enciende luz', $d['light'] === P::LIGHT_CANCEL_OFF);
check('OPEN marca ciclo de entrada', $d['cycle_kind'] === P::CYCLE_ENTRY);

// Puerta abierta desde ocupada: posible salida.
$d = P::decide(base([
    'state' => P::STATE_OCCUPIED,
    'presence' => IotSession::PRESENCE_PRESENT,
    'sensor' => PresenceEvent::SENSOR_PROXIMITY,
    'value' => PresenceEvent::VALUE_OPEN,
]));
check('OPEN desde ocupada → DOOR_OPEN', $d['state'] === P::STATE_DOOR_OPEN);
check('OPEN desde ocupada marca ciclo de salida', $d['cycle_kind'] === P::CYCLE_EXIT);

// Cierre con presencia: dentro.
$d = P::decide(base([
    'state' => P::STATE_DOOR_OPEN,
    'presence' => IotSession::PRESENCE_PRESENT,
    'sensor' => PresenceEvent::SENSOR_PROXIMITY,
    'value' => PresenceEvent::VALUE_CLOSED,
    'cycle_kind' => P::CYCLE_ENTRY,
]));
check('CLOSE con presencia → OCCUPIED', $d['state'] === P::STATE_OCCUPIED);
check('CLOSE con presencia confirma entrada', $d['confirmed_entry'] === true);

// Cierre sin presencia en entrada: verificación con plazo.
$d = P::decide(base([
    'state' => P::STATE_DOOR_OPEN,
    'presence' => IotSession::PRESENCE_ABSENT,
    'sensor' => PresenceEvent::SENSOR_PROXIMITY,
    'value' => PresenceEvent::VALUE_CLOSED,
    'cycle_kind' => P::CYCLE_ENTRY,
]));
check('CLOSE sin presencia → ENTRY_VERIFY', $d['state'] === P::STATE_ENTRY_VERIFY);
check('ENTRY_VERIFY programa plazo', $d['entry_deadline_ms'] === 120000);
check('ENTRY_VERIFY mantiene luz ON', $d['light'] === P::LIGHT_ON);

// Presencia durante la verificación: dentro.
$d = P::decide(base([
    'state' => P::STATE_ENTRY_VERIFY,
    'presence' => IotSession::PRESENCE_ABSENT,
    'sensor' => PresenceEvent::SENSOR_PRESENCE,
    'value' => PresenceEvent::VALUE_PRESENT,
    'entry_deadline_ms' => 120000,
]));
check('PRESENT en verificación → OCCUPIED', $d['state'] === P::STATE_OCCUPIED);
check('PRESENT confirma entrada', $d['confirmed_entry'] === true);
check('PRESENT cancela OFF', $d['light'] === P::LIGHT_CANCEL_OFF);

// Ausencia en ocupada SIN ciclo de salida: no se vacía.
$d = P::decide(base([
    'state' => P::STATE_OCCUPIED,
    'presence' => IotSession::PRESENCE_PRESENT,
    'sensor' => PresenceEvent::SENSOR_PRESENCE,
    'value' => PresenceEvent::VALUE_ABSENT,
    'cycle_kind' => P::CYCLE_ENTRY,
]));
check('ABSENT sin ciclo de salida → UNCERTAIN', $d['state'] === P::STATE_OCCUPANCY_UNCERTAIN);
check('ABSENT sin ciclo mantiene luz ON', $d['light'] === P::LIGHT_ON);
check('ABSENT sin ciclo no apaga', $d['light'] !== P::LIGHT_OFF);

// Ausencia en ocupada CON ciclo de salida: verificación.
$d = P::decide(base([
    'state' => P::STATE_OCCUPIED,
    'presence' => IotSession::PRESENCE_PRESENT,
    'sensor' => PresenceEvent::SENSOR_PRESENCE,
    'value' => PresenceEvent::VALUE_ABSENT,
    'cycle_kind' => P::CYCLE_EXIT,
]));
check('ABSENT con ciclo de salida → EXIT_VERIFY', $d['state'] === P::STATE_EXIT_VERIFY);
check('EXIT_VERIFY programa plazo', $d['vacancy_deadline_ms'] === 110000);

// Un ciclo de salida antiguo no basta para vaciar: protección.
$d = P::decide(base([
    'state' => P::STATE_OCCUPIED,
    'presence' => IotSession::PRESENCE_PRESENT,
    'sensor' => PresenceEvent::SENSOR_PRESENCE,
    'value' => PresenceEvent::VALUE_ABSENT,
    'cycle_kind' => P::CYCLE_EXIT,
    'recent_close' => false,
]));
check('ABSENT con ciclo antiguo → UNCERTAIN', $d['state'] === P::STATE_OCCUPANCY_UNCERTAIN);
check('ABSENT con ciclo antiguo no apaga', $d['light'] !== P::LIGHT_OFF);

// Heartbeat ABSENT no reinicia el plazo ni vacía.
$d = P::decide(base([
    'state' => P::STATE_EXIT_VERIFY,
    'presence' => IotSession::PRESENCE_ABSENT,
    'sensor' => PresenceEvent::SENSOR_PRESENCE,
    'value' => PresenceEvent::VALUE_ABSENT,
    'vacancy_deadline_ms' => 110000,
    'is_repeat' => true,
]));
check('heartbeat ABSENT → noop', $d['reason'] === P::REASON_HEARTBEAT);
check('heartbeat ABSENT no reinicia plazo', $d['vacancy_deadline_ms'] === 110000);
check('heartbeat ABSENT mantiene luz ON', $d['light'] === P::LIGHT_ON);

// Resolver EXIT_VERIFY con ausencia y salud OK: vacío + OFF.
$d = P::resolve(base([
    'state' => P::STATE_EXIT_VERIFY,
    'presence' => IotSession::PRESENCE_ABSENT,
    'vacancy_deadline_ms' => 100000,
    'now_ms' => 100000,
    'radar_health' => P::HEALTH_OK,
]));
check('resolver EXIT_VERIFY → VACANT', $d['state'] === P::STATE_VACANT_CONFIRMED);
check('resolver EXIT_VERIFY apaga luz', $d['light'] === P::LIGHT_OFF);
check('resolver EXIT_VERIFY confirma salida', $d['confirmed_exit'] === true);

// Resolver con radar stale: incertidumbre, no OFF.
$d = P::resolve(base([
    'state' => P::STATE_EXIT_VERIFY,
    'presence' => IotSession::PRESENCE_ABSENT,
    'vacancy_deadline_ms' => 100000,
    'now_ms' => 100000,
    'radar_health' => P::HEALTH_STALE,
]));
check('stale en EXIT_VERIFY → UNCERTAIN', $d['state'] === P::STATE_OCCUPANCY_UNCERTAIN);
check('stale en EXIT_VERIFY no apaga', $d['light'] !== P::LIGHT_OFF);

// Resolver ENTRY_VERIFY sin presencia: vacío + OFF.
$d = P::resolve(base([
    'state' => P::STATE_ENTRY_VERIFY,
    'presence' => IotSession::PRESENCE_ABSENT,
    'door_state' => IotSession::DOOR_CLOSED,
    'entry_deadline_ms' => 100000,
    'now_ms' => 100000,
    'active_stay' => true,
    'access_context' => true,
    'radar_health' => P::HEALTH_OK,
]));
check('resolver ENTRY_VERIFY → VACANT', $d['state'] === P::STATE_VACANT_CONFIRMED);
check('resolver ENTRY_VERIFY apaga luz', $d['light'] === P::LIGHT_OFF);

// Presencia sin contexto: protegida, nunca fuera.
$d = P::decide(base([
    'state' => P::STATE_VACANT_CONFIRMED,
    'presence' => IotSession::PRESENCE_ABSENT,
    'sensor' => PresenceEvent::SENSOR_PRESENCE,
    'value' => PresenceEvent::VALUE_PRESENT,
]));
check('PRESENT sin contexto → UNCERTAIN', $d['state'] === P::STATE_OCCUPANCY_UNCERTAIN);
check('PRESENT sin contexto enciende luz', $d['light'] === P::LIGHT_CANCEL_OFF);

// Presencia con contexto: ocupada.
$d = P::decide(base([
    'state' => P::STATE_VACANT_CONFIRMED,
    'presence' => IotSession::PRESENCE_ABSENT,
    'sensor' => PresenceEvent::SENSOR_PRESENCE,
    'value' => PresenceEvent::VALUE_PRESENT,
    'active_stay' => true,
]));
check('PRESENT con contexto → OCCUPIED', $d['state'] === P::STATE_OCCUPIED);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
