<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use App\Domain\Rooms\RoomType;
use App\Domain\Stays\Stay;
use DateTimeImmutable;
use DateTimeZone;

/**
 * StandardOccupancyEngine — F138.
 *
 * Aplica StandardOccupancyPolicy a una sesión bloqueada. No hace I/O ni publica
 * MQTT: traduce hechos a estado persistible y devuelve la acción de luz para
 * que IotSessionService la ejecute post-commit.
 */
final class StandardOccupancyEngine
{
    public const LIGHT_ON  = StandardOccupancyPolicy::LIGHT_ON;
    public const LIGHT_OFF = StandardOccupancyPolicy::LIGHT_OFF;
    public const LIGHT_NONE = StandardOccupancyPolicy::LIGHT_NONE;

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    public static function apply(
        IotSession $session,
        array $event,
        ?Stay $activeStay,
        RoomType $roomType,
        DateTimeImmutable $now
    ): array {
        $sensor = strtoupper((string) ($event['sensor'] ?? ''));
        $value = strtoupper((string) ($event['value'] ?? ''));
        $meta = is_array($event['meta'] ?? null) ? $event['meta'] : [];
        $evtUtc = self::toMysqlUtc((string) ($event['occurred_at'] ?? ''));
        $nowMs = (int) $now->format('Uv');
        $evtMs = self::toMs($evtUtc) ?? $nowMs;

        $entryWindow = max(5, min(300, (int) $roomType->presenceEntryWindowSeconds)) * 1000;
        $presenceGuard = self::envInt('STANDARD_PRESENCE_GUARD_MS', 400, 0, 3000);
        $vacancyGuard = self::envInt('STANDARD_VACANCY_GUARD_SECONDS', 10, 1, 120) * 1000;

        $currentState = $session->occupancyState;
        if ($currentState === null || $currentState === '') {
            if ($session->doorState === IotSession::DOOR_OPEN) {
                $currentState = StandardOccupancyPolicy::STATE_DOOR_OPEN;
            } elseif ($session->presenceState === IotSession::PRESENCE_PRESENT) {
                $currentState = StandardOccupancyPolicy::STATE_OCCUPIED;
            } else {
                $currentState = StandardOccupancyPolicy::STATE_VACANT_CONFIRMED;
            }
        }

        $activeStay = $activeStay !== null && $activeStay->isActive() ? $activeStay : null;
        $recentOpen = false;
        if ($session->lastOpenAt !== null) {
            $openMs = self::toMs($session->lastOpenAt);
            $recentOpen = $openMs !== null && ($nowMs - $openMs) <= $entryWindow;
        }
        $recentClose = false;
        if ($session->lastCloseAt !== null) {
            $closeMs = self::toMs($session->lastCloseAt);
            $exitCycleMax = self::envInt('STANDARD_EXIT_CYCLE_MAX_SECONDS', 30, 5, 300) * 1000;
            $recentClose = $closeMs !== null && ($nowMs - $closeMs) <= $exitCycleMax;
        }
        $entryConfirmed = $activeStay !== null && $activeStay->entryConfirmedAt !== null;
        $cycle = isset($session->occupancyData['cycle_kind'])
            && is_string($session->occupancyData['cycle_kind'])
            ? (string) $session->occupancyData['cycle_kind'] : '';
        $health = self::healthFor($session, $sensor, $meta, $nowMs);
        $isRepeat = $sensor === PresenceEvent::SENSOR_PRESENCE
            && $value === $session->presenceState;

        $input = [
            'state' => $currentState,
            'presence' => $session->presenceState,
            'sensor' => $sensor,
            'value' => $value,
            'door_state' => $session->doorState,
            'cycle_kind' => $cycle,
            'now_ms' => $nowMs,
            'occurred_ms' => $evtMs,
            'entry_window_ms' => $entryWindow,
            'presence_guard_ms' => $presenceGuard,
            'vacancy_guard_ms' => $vacancyGuard,
            'active_stay' => $activeStay !== null,
            'access_context' => $activeStay !== null,
            'recent_open' => $recentOpen,
            'recent_close' => $recentClose,
            'entry_confirmed' => $entryConfirmed,
            'is_repeat' => $isRepeat,
            'radar_health' => $health,
        ];
        $decision = StandardOccupancyPolicy::decide($input);

        $previousState = $currentState;
        $previousPresence = $session->presenceState;

        // Puerta: el hecho físico se refleja siempre, aunque la ocupación quede
        // en incertidumbre.
        if ($sensor === PresenceEvent::SENSOR_PROXIMITY) {
            $session->lastDoorEventAt = $evtUtc;
            $session->lastDoorValue = $value;
            if ($value === PresenceEvent::VALUE_OPEN) {
                $session->doorState = IotSession::DOOR_OPEN;
                $session->lastOpenAt = $evtUtc;
            } elseif ($value === PresenceEvent::VALUE_CLOSED) {
                $session->doorState = IotSession::DOOR_CLOSED;
                $session->lastCloseAt = $evtUtc;
            }
        }

        // Presencia: se conserva el valor real del sensor; la ocupación decide
        // por separado si protege o vacía.
        if ($sensor === PresenceEvent::SENSOR_PRESENCE) {
            $session->lastPresenceEventAt = $evtUtc;
            $session->lastPresenceValue = $value;
            if ($value === PresenceEvent::VALUE_PRESENT) {
                $session->lastAbsentSince = null;
            } elseif ($value === PresenceEvent::VALUE_ABSENT
                && $previousPresence !== IotSession::PRESENCE_ABSENT
            ) {
                $session->lastAbsentSince = $evtUtc;
            }
        }

        $session->presenceState = (string) ($decision['presence'] ?? $session->presenceState);
        $session->occupancyState = (string) ($decision['state'] ?? $currentState);
        $session->entryDeadlineAt = self::fromMs($decision['entry_deadline_ms'] ?? null);
        $session->vacancyDeadlineAt = self::fromMs($decision['vacancy_deadline_ms'] ?? null);
        $session->uncertainSince = self::fromMs($decision['uncertain_since_ms'] ?? null);
        $session->radarHealth = $health;

        $light = (string) ($decision['light'] ?? self::LIGHT_NONE);
        if ($light === self::LIGHT_ON || $light === StandardOccupancyPolicy::LIGHT_CANCEL_OFF) {
            $session->lightDesired = 'ON';
            $session->lightOffAt = null;
        } elseif ($light === self::LIGHT_OFF) {
            $session->lightDesired = 'OFF';
            $delay = self::envInt('STANDARD_LIGHT_OFF_DELAY_SECONDS', 1, 0, 10);
            $session->lightOffAt = $now->modify('+' . $delay . ' seconds')->format('Y-m-d H:i:s.v');
        } elseif ($light === self::LIGHT_NONE
            && $session->occupancyState === StandardOccupancyPolicy::STATE_VACANT_CONFIRMED
            && $health === StandardOccupancyPolicy::HEALTH_OK
            && $session->lightDesired !== 'OFF'
        ) {
            // Un heartbeat de una sala ya vacía no debe dejar la luz encendida
            // indefinidamente. Solo ocurre con radar sano y estado vacío.
            $session->lightDesired = 'OFF';
            $delay = self::envInt('STANDARD_LIGHT_OFF_DELAY_SECONDS', 1, 0, 10);
            $session->lightOffAt = $now->modify('+' . $delay . ' seconds')->format('Y-m-d H:i:s.v');
        }

        $session->occupancyData = array_merge(
            is_array($session->occupancyData) ? $session->occupancyData : [],
            [
                'reason' => $decision['reason'] ?? null,
                'cycle_kind' => $decision['cycle_kind'] ?? null,
                'sensor_presence' => $sensor === PresenceEvent::SENSOR_PRESENCE ? $value : null,
                'last_dp1_at' => $sensor === PresenceEvent::SENSOR_PRESENCE ? $evtUtc : null,
                'last_dp9_at' => is_array($meta['distance_report'] ?? null)
                    && ($meta['distance_report']['received_at'] ?? '') !== ''
                    ? (string) $meta['distance_report']['received_at'] : null,
                'last_dp9_m' => is_array($meta['distance_report'] ?? null)
                    && is_numeric($meta['distance_report']['distance_m'] ?? null)
                    ? (float) $meta['distance_report']['distance_m'] : null,
                'presence_guard_ms' => $presenceGuard,
                'vacancy_guard_ms' => $vacancyGuard,
                'entry_window_ms' => $entryWindow,
                'updated_at' => $now->format('Y-m-d\TH:i:s.v\Z'),
            ]
        );

        $session->presenceOutcome = [
            'state' => $session->occupancyState,
            'occupancy_state' => $session->occupancyState,
            'reason' => $decision['reason'] ?? null,
            'outcome' => $session->occupancyState === StandardOccupancyPolicy::STATE_OCCUPIED
                ? 'applied'
                : ($session->occupancyState === StandardOccupancyPolicy::STATE_VACANT_CONFIRMED
                    ? 'vacant'
                    : 'protected'),
            'effective_presence' => $session->presenceState,
            'sensor_presence' => $sensor === PresenceEvent::SENSOR_PRESENCE ? $value : $session->lastPresenceValue,
            'light' => $session->lightDesired,
            'light_desired' => $session->lightDesired,
            'cycle_kind' => $decision['cycle_kind'] ?? null,
            'radar_health' => $health,
            'decided_at' => $now->format('Y-m-d\TH:i:s.v\Z'),
            'event_at' => $evtUtc,
            'source' => 'standard_occupancy',
        ];

        $stateChanged = $previousState !== $session->occupancyState
            || $previousPresence !== $session->presenceState;

        return [
            'applied' => $stateChanged || $sensor === PresenceEvent::SENSOR_PROXIMITY,
            'state_changed' => $stateChanged,
            'occupancy_state' => $session->occupancyState,
            'presence_state' => $session->presenceState,
            'light' => $light,
            'reason' => $decision['reason'] ?? null,
            'confirmed_entry' => (bool) ($decision['confirmed_entry'] ?? false),
            'confirmed_exit' => (bool) ($decision['confirmed_exit'] ?? false),
            'radar_health' => $health,
        ];
    }

    /** @param array<string,mixed> $meta */
    private static function healthFor(IotSession $session, string $sensor, array $meta, int $nowMs): string
    {
        if ($sensor === PresenceEvent::SENSOR_PRESENCE
            && is_array($meta['presence_report'] ?? null)
        ) {
            return StandardOccupancyPolicy::HEALTH_OK;
        }
        $last = $session->lastPresenceEventAt;
        if ($last === null || trim($last) === '') {
            // Sin radar registrado no se puede evaluar; no se inventa presencia.
            return StandardOccupancyPolicy::HEALTH_UNAVAILABLE;
        }
        $lastMs = self::toMs($last);
        if ($lastMs === null) {
            return StandardOccupancyPolicy::HEALTH_UNAVAILABLE;
        }
        $staleMs = self::envInt('RADAR_PRESENCE_STALE_SECONDS', 30, 5, 300) * 1000;
        return ($nowMs - $lastMs) > $staleMs
            ? StandardOccupancyPolicy::HEALTH_STALE
            : StandardOccupancyPolicy::HEALTH_OK;
    }

    private static function envInt(string $key, int $default, int $min, int $max): int
    {
        $raw = \App\Support\Config::get($key);
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return $default;
        }
        return max($min, min($max, (int) $raw));
    }

    private static function toMysqlUtc(string $value): string
    {
        if ($value === '') {
            return gmdate('Y-m-d H:i:s') . '.000';
        }
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private static function toMs(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $normalized = rtrim(str_replace('T', ' ', trim($value)), 'Z');
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $normalized, new DateTimeZone('UTC'));
        if ($dt === false) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized, new DateTimeZone('UTC'));
        }
        if ($dt === false) {
            return null;
        }
        return (int) $dt->format('Uv');
    }

    private static function fromMs(mixed $ms): ?string
    {
        if ($ms === null || $ms === '' || !is_numeric($ms)) {
            return null;
        }
        $seconds = intdiv((int) $ms, 1000);
        $millis = (int) $ms % 1000;
        return gmdate('Y-m-d H:i:s', $seconds) . '.' . str_pad((string) $millis, 3, '0', STR_PAD_LEFT);
    }
}
