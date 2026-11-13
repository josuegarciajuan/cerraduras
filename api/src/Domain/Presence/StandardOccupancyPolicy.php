<?php
declare(strict_types=1);

namespace App\Domain\Presence;

/**
 * StandardOccupancyPolicy — F138.
 *
 * Motor único de ocupación para TODAS las habitaciones STANDARD. No hay una
 * rama "hotelera" separada: QR, llave, ficha, puerta y radar alimentan el mismo
 * estado. Las estancias son contexto de negocio, no otra política.
 *
 * Principios:
 *   - DP1 PRESENT es la señal principal; no exige DP9.
 *   - DP9 es auxiliar: no crea ausencia ni apaga una sala ocupada.
 *   - Silencio/gap/config cambiada = incertidumbre, nunca vacío.
 *   - La puerta abierta mantiene el umbral.
 *   - OFF solo con VACANT_CONFIRMED y guarda.
 *
 * Clase pura: sin I/O, sin reloj global, sin BD. Los plazos se expresan en ms.
 */
final class StandardOccupancyPolicy
{
    public const STATE_VACANT_CONFIRMED      = 'VACANT_CONFIRMED';
    public const STATE_ACCESS_PENDING        = 'ACCESS_PENDING';
    public const STATE_DOOR_OPEN             = 'DOOR_OPEN';
    public const STATE_ENTRY_VERIFY          = 'ENTRY_VERIFY';
    public const STATE_OCCUPIED              = 'OCCUPIED';
    public const STATE_EXIT_VERIFY           = 'EXIT_VERIFY';
    public const STATE_OCCUPANCY_UNCERTAIN   = 'OCCUPANCY_UNCERTAIN';

    public const LIGHT_ON        = 'on';
    public const LIGHT_OFF       = 'off';
    public const LIGHT_CANCEL_OFF = 'cancel_off';
    public const LIGHT_NONE      = 'none';

    public const CYCLE_ENTRY = 'entry';
    public const CYCLE_EXIT  = 'exit';

    public const HEALTH_OK          = 'ok';
    public const HEALTH_STALE       = 'stale';
    public const HEALTH_GAP         = 'gap';
    public const HEALTH_UNAVAILABLE = 'unavailable';

    public const REASON_PRESENT                 = 'presence_detected';
    public const REASON_PRESENT_THRESHOLD       = 'presence_at_threshold';
    public const REASON_PRESENT_WITHOUT_CONTEXT = 'presence_without_context';
    public const REASON_ENTRY_ABSENT_PENDING    = 'entry_absent_pending';
    public const REASON_ENTRY_TIMEOUT           = 'entry_timeout_without_presence';
    public const REASON_EXIT_CONFIRMED          = 'exit_confirmed_by_absence';
    public const REASON_ABSENCE_NO_CYCLE        = 'absence_without_exit_cycle';
    public const REASON_DOOR_OPEN               = 'door_open';
    public const REASON_DOOR_CLOSED             = 'door_closed';
    public const REASON_HEARTBEAT               = 'heartbeat_noop';
    public const REASON_RADAR_STALE             = 'radar_unavailable';
    public const REASON_UNCERTAIN               = 'occupancy_uncertain';
    public const REASON_DOOR_CLOSED_UNKNOWN     = 'door_closed_presence_unknown';

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function decide(array $in): array
    {
        $state = self::normalizeState((string) ($in['state'] ?? ''));
        $presence = self::normalizePresence((string) ($in['presence'] ?? ''));
        $sensor = strtoupper((string) ($in['sensor'] ?? ''));
        $value = strtoupper((string) ($in['value'] ?? ''));
        $door = strtoupper((string) ($in['door_state'] ?? IotSession::DOOR_UNKNOWN));
        $cycle = strtolower((string) ($in['cycle_kind'] ?? ''));
        $now = (int) ($in['now_ms'] ?? 0);
        $entryWindow = max(1000, (int) ($in['entry_window_ms'] ?? 20000));
        $presenceGuard = max(0, (int) ($in['presence_guard_ms'] ?? 400));
        $vacancyGuard = max(1000, (int) ($in['vacancy_guard_ms'] ?? 10000));
        $activeStay = (bool) ($in['active_stay'] ?? false);
        $accessContext = (bool) ($in['access_context'] ?? false);
        $recentOpen = (bool) ($in['recent_open'] ?? false);
        $recentClose = (bool) ($in['recent_close'] ?? false);
        $entryConfirmed = (bool) ($in['entry_confirmed'] ?? false);
        $isRepeat = (bool) ($in['is_repeat'] ?? false);

        $doorOpen = $door === IotSession::DOOR_OPEN;
        $hasContext = $activeStay || $accessContext || $doorOpen || $recentOpen;

        $out = self::base($state, $presence, $cycle, $in);
        $out['light'] = self::LIGHT_NONE;
        $out['reason'] = null;
        $out['confirmed_entry'] = false;
        $out['confirmed_exit'] = false;

        if ($sensor === PresenceEvent::SENSOR_PROXIMITY) {
            if ($value === PresenceEvent::VALUE_OPEN) {
                $cycle = in_array($state, [self::STATE_OCCUPIED, self::STATE_EXIT_VERIFY, self::STATE_DOOR_OPEN], true)
                    ? self::CYCLE_EXIT : self::CYCLE_ENTRY;
                $out['state'] = self::STATE_DOOR_OPEN;
                $out['door_state'] = IotSession::DOOR_OPEN;
                $out['cycle_kind'] = $cycle;
                $out['light'] = self::LIGHT_CANCEL_OFF;
                $out['entry_deadline_ms'] = null;
                $out['vacancy_deadline_ms'] = null;
                $out['uncertain_since_ms'] = null;
                $out['reason'] = self::REASON_DOOR_OPEN;
                return $out;
            }
            if ($value === PresenceEvent::VALUE_CLOSED) {
                $out['door_state'] = IotSession::DOOR_CLOSED;
                if ($state === self::STATE_DOOR_OPEN || $state === self::STATE_ACCESS_PENDING) {
                    if ($presence === IotSession::PRESENCE_PRESENT) {
                        $out['state'] = self::STATE_OCCUPIED;
                        // Si el ciclo era de salida, se conserva durante una
                        // ventana corta: una ausencia inmediata tras cerrar es
                        // compatible con que la persona haya salido.
                        $out['cycle_kind'] = $cycle === self::CYCLE_EXIT ? self::CYCLE_EXIT : null;
                        $out['entry_deadline_ms'] = null;
                        $out['vacancy_deadline_ms'] = null;
                        $out['light'] = self::LIGHT_CANCEL_OFF;
                        $out['confirmed_entry'] = !$entryConfirmed;
                        $out['reason'] = self::REASON_DOOR_CLOSED;
                        return $out;
                    }
                    if ($cycle === self::CYCLE_EXIT || $state === self::STATE_EXIT_VERIFY) {
                        $out['state'] = self::STATE_EXIT_VERIFY;
                        $out['vacancy_deadline_ms'] = $now + $vacancyGuard;
                        $out['entry_deadline_ms'] = null;
                        $out['light'] = self::LIGHT_ON;
                        $out['reason'] = self::REASON_DOOR_CLOSED;
                        return $out;
                    }
                    if ($presence === IotSession::PRESENCE_ABSENT) {
                        $out['state'] = self::STATE_ENTRY_VERIFY;
                        $out['entry_deadline_ms'] = $now + $entryWindow;
                        $out['vacancy_deadline_ms'] = null;
                        $out['light'] = self::LIGHT_ON;
                        $out['reason'] = self::REASON_ENTRY_ABSENT_PENDING;
                        return $out;
                    }
                    $out['state'] = self::STATE_OCCUPANCY_UNCERTAIN;
                    $out['uncertain_since_ms'] = $now;
                    $out['light'] = self::LIGHT_ON;
                    $out['reason'] = self::REASON_DOOR_CLOSED_UNKNOWN;
                    return $out;
                }
                return $out;
            }
        }

        if ($sensor === PresenceEvent::SENSOR_PRESENCE) {
            if ($value === PresenceEvent::VALUE_PRESENT) {
                $out['presence'] = IotSession::PRESENCE_PRESENT;
                $out['light'] = self::LIGHT_CANCEL_OFF;
                if ($isRepeat || $presence === IotSession::PRESENCE_PRESENT) {
                    if ($state === self::STATE_OCCUPIED
                        || $state === self::STATE_DOOR_OPEN
                        || $state === self::STATE_ENTRY_VERIFY
                        || $state === self::STATE_EXIT_VERIFY
                        || $state === self::STATE_ACCESS_PENDING
                    ) {
                        $out['reason'] = self::REASON_HEARTBEAT;
                        return $out;
                    }
                }
                if ($state === self::STATE_DOOR_OPEN) {
                    $out['reason'] = self::REASON_PRESENT_THRESHOLD;
                    return $out;
                }
                if (in_array($state, [self::STATE_ENTRY_VERIFY, self::STATE_EXIT_VERIFY, self::STATE_ACCESS_PENDING, self::STATE_OCCUPIED], true)) {
                    $out['state'] = self::STATE_OCCUPIED;
                    $out['entry_deadline_ms'] = null;
                    $out['vacancy_deadline_ms'] = null;
                    $out['uncertain_since_ms'] = null;
                    $out['cycle_kind'] = null;
                    $out['confirmed_entry'] = !$entryConfirmed;
                    $out['reason'] = self::REASON_PRESENT;
                    return $out;
                }
                if ($hasContext) {
                    $out['state'] = self::STATE_OCCUPIED;
                    $out['entry_deadline_ms'] = null;
                    $out['vacancy_deadline_ms'] = null;
                    $out['uncertain_since_ms'] = null;
                    $out['confirmed_entry'] = !$entryConfirmed;
                    $out['reason'] = self::REASON_PRESENT;
                    return $out;
                }
                $out['state'] = self::STATE_OCCUPANCY_UNCERTAIN;
                $out['uncertain_since_ms'] = $now;
                $out['light'] = self::LIGHT_CANCEL_OFF;
                $out['reason'] = self::REASON_PRESENT_WITHOUT_CONTEXT;
                return $out;
            }

            if ($value === PresenceEvent::VALUE_ABSENT) {
                $out['presence'] = IotSession::PRESENCE_ABSENT;
                if ($isRepeat || $presence === IotSession::PRESENCE_ABSENT) {
                    // Un heartbeat ABSENT no reinicia plazos ni confirma vacío.
                    if (in_array($state, [self::STATE_OCCUPIED, self::STATE_ENTRY_VERIFY, self::STATE_EXIT_VERIFY, self::STATE_DOOR_OPEN], true)) {
                        $out['light'] = self::LIGHT_ON;
                    }
                    $out['reason'] = self::REASON_HEARTBEAT;
                    return $out;
                }
                if ($state === self::STATE_ACCESS_PENDING || $state === self::STATE_ENTRY_VERIFY) {
                    $out['state'] = self::STATE_ENTRY_VERIFY;
                    $out['entry_deadline_ms'] = $out['entry_deadline_ms'] ?? ($now + $entryWindow);
                    $out['light'] = self::LIGHT_ON;
                    $out['reason'] = self::REASON_ENTRY_ABSENT_PENDING;
                    return $out;
                }
                if ($state === self::STATE_DOOR_OPEN) {
                    $out['light'] = self::LIGHT_ON;
                    $out['reason'] = self::REASON_ENTRY_ABSENT_PENDING;
                    return $out;
                }
                if ($state === self::STATE_OCCUPIED) {
                    if ($cycle === self::CYCLE_EXIT && $recentClose) {
                        $out['state'] = self::STATE_EXIT_VERIFY;
                        $out['vacancy_deadline_ms'] = $now + $vacancyGuard;
                        $out['light'] = self::LIGHT_ON;
                        $out['reason'] = self::REASON_EXIT_CONFIRMED;
                        return $out;
                    }
                    // Sin ciclo de salida no se vacía: puede ser una persona
                    // inmóvil o un falso negativo del radar.
                    $out['state'] = self::STATE_OCCUPANCY_UNCERTAIN;
                    $out['uncertain_since_ms'] = $now;
                    $out['light'] = self::LIGHT_ON;
                    $out['reason'] = self::REASON_ABSENCE_NO_CYCLE;
                    return $out;
                }
                if ($state === self::STATE_EXIT_VERIFY) {
                    $out['vacancy_deadline_ms'] = $out['vacancy_deadline_ms'] ?? ($now + $vacancyGuard);
                    $out['light'] = self::LIGHT_ON;
                    return $out;
                }
                if ($state === self::STATE_VACANT_CONFIRMED) {
                    $out['light'] = self::LIGHT_OFF;
                    return $out;
                }
                $out['state'] = self::STATE_OCCUPANCY_UNCERTAIN;
                $out['light'] = self::LIGHT_ON;
                $out['reason'] = self::REASON_UNCERTAIN;
                return $out;
            }
        }

        return $out;
    }

    /**
     * Resuelve plazos sin un mensaje nuevo. Es el camino autónomo del worker.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function resolve(array $in): array
    {
        $state = self::normalizeState((string) ($in['state'] ?? ''));
        $presence = self::normalizePresence((string) ($in['presence'] ?? ''));
        $door = strtoupper((string) ($in['door_state'] ?? IotSession::DOOR_UNKNOWN));
        $health = strtolower((string) ($in['radar_health'] ?? self::HEALTH_UNAVAILABLE));
        $now = (int) ($in['now_ms'] ?? 0);
        $entryDue = self::nullableInt($in['entry_deadline_ms'] ?? null);
        $vacancyDue = self::nullableInt($in['vacancy_deadline_ms'] ?? null);
        $uncertainSince = self::nullableInt($in['uncertain_since_ms'] ?? null);
        $entryConfirmed = (bool) ($in['entry_confirmed'] ?? false);
        $cycle = strtolower((string) ($in['cycle_kind'] ?? ''));
        $activeStay = (bool) ($in['active_stay'] ?? false);
        $accessContext = (bool) ($in['access_context'] ?? false);
        $recentOpen = (bool) ($in['recent_open'] ?? false);
        $hasContext = $activeStay || $accessContext || $door === IotSession::DOOR_OPEN || $recentOpen;

        $out = self::base($state, $presence, $cycle, $in);
        $out['light'] = self::LIGHT_NONE;
        $out['reason'] = null;
        $out['confirmed_entry'] = false;
        $out['confirmed_exit'] = false;

        if ($state === self::STATE_ENTRY_VERIFY) {
            if ($entryDue === null || $now < $entryDue) {
                $out['light'] = self::LIGHT_ON;
                return $out;
            }
            if ($presence === IotSession::PRESENCE_PRESENT) {
                $out['state'] = self::STATE_OCCUPIED;
                $out['entry_deadline_ms'] = null;
                $out['light'] = self::LIGHT_CANCEL_OFF;
                $out['confirmed_entry'] = !$entryConfirmed;
                $out['reason'] = self::REASON_PRESENT;
                return $out;
            }
            if (!$hasContext || $health !== self::HEALTH_OK || $door !== IotSession::DOOR_CLOSED) {
                $out['state'] = self::STATE_OCCUPANCY_UNCERTAIN;
                $out['uncertain_since_ms'] = $uncertainSince ?? $now;
                $out['light'] = self::LIGHT_ON;
                $out['reason'] = $health !== self::HEALTH_OK ? self::REASON_RADAR_STALE : self::REASON_UNCERTAIN;
                return $out;
            }
            $out['state'] = self::STATE_VACANT_CONFIRMED;
            $out['entry_deadline_ms'] = null;
            $out['vacancy_deadline_ms'] = null;
            $out['light'] = self::LIGHT_OFF;
            $out['reason'] = self::REASON_ENTRY_TIMEOUT;
            return $out;
        }

        if ($state === self::STATE_EXIT_VERIFY) {
            if ($vacancyDue === null || $now < $vacancyDue) {
                $out['light'] = self::LIGHT_ON;
                return $out;
            }
            if ($presence === IotSession::PRESENCE_PRESENT) {
                $out['state'] = self::STATE_OCCUPIED;
                $out['vacancy_deadline_ms'] = null;
                $out['light'] = self::LIGHT_CANCEL_OFF;
                $out['reason'] = self::REASON_PRESENT;
                return $out;
            }
            if ($health !== self::HEALTH_OK || $door !== IotSession::DOOR_CLOSED) {
                $out['state'] = self::STATE_OCCUPANCY_UNCERTAIN;
                $out['uncertain_since_ms'] = $uncertainSince ?? $now;
                $out['light'] = self::LIGHT_ON;
                $out['reason'] = $health !== self::HEALTH_OK ? self::REASON_RADAR_STALE : self::REASON_UNCERTAIN;
                return $out;
            }
            $out['state'] = self::STATE_VACANT_CONFIRMED;
            $out['entry_deadline_ms'] = null;
            $out['vacancy_deadline_ms'] = null;
            $out['light'] = self::LIGHT_OFF;
            $out['confirmed_exit'] = true;
            $out['reason'] = self::REASON_EXIT_CONFIRMED;
            return $out;
        }

        if ($state === self::STATE_OCCUPANCY_UNCERTAIN) {
            if ($presence === IotSession::PRESENCE_PRESENT && $hasContext) {
                $out['state'] = self::STATE_OCCUPIED;
                $out['uncertain_since_ms'] = null;
                $out['light'] = self::LIGHT_CANCEL_OFF;
                $out['confirmed_entry'] = !$entryConfirmed;
                $out['reason'] = self::REASON_PRESENT;
                return $out;
            }
            $out['light'] = self::LIGHT_ON;
            $out['reason'] = self::REASON_UNCERTAIN;
            return $out;
        }

        if ($state === self::STATE_OCCUPIED) {
            if ($health !== self::HEALTH_OK) {
                $out['state'] = self::STATE_OCCUPANCY_UNCERTAIN;
                $out['uncertain_since_ms'] = $uncertainSince ?? $now;
                $out['light'] = self::LIGHT_ON;
                $out['reason'] = self::REASON_RADAR_STALE;
                return $out;
            }
            $out['light'] = self::LIGHT_CANCEL_OFF;
            return $out;
        }

        if ($state === self::STATE_DOOR_OPEN) {
            $out['light'] = self::LIGHT_ON;
            return $out;
        }

        if ($state === self::STATE_VACANT_CONFIRMED) {
            $out['light'] = self::LIGHT_OFF;
            $out['reason'] = self::REASON_ENTRY_TIMEOUT;
            return $out;
        }

        $out['light'] = self::LIGHT_ON;
        return $out;
    }

    /** @param array<string,mixed> $in */
    private static function base(string $state, string $presence, string $cycle, array $in): array
    {
        return [
            'state' => $state,
            'presence' => $presence,
            'door_state' => strtoupper((string) ($in['door_state'] ?? IotSession::DOOR_UNKNOWN)),
            'cycle_kind' => $cycle !== '' ? $cycle : null,
            'entry_deadline_ms' => self::nullableInt($in['entry_deadline_ms'] ?? null),
            'vacancy_deadline_ms' => self::nullableInt($in['vacancy_deadline_ms'] ?? null),
            'uncertain_since_ms' => self::nullableInt($in['uncertain_since_ms'] ?? null),
            'light' => self::LIGHT_NONE,
            'reason' => null,
            'confirmed_entry' => false,
            'confirmed_exit' => false,
        ];
    }

    private static function normalizeState(string $state): string
    {
        $state = strtoupper(trim($state));
        return in_array($state, [
            self::STATE_VACANT_CONFIRMED,
            self::STATE_ACCESS_PENDING,
            self::STATE_DOOR_OPEN,
            self::STATE_ENTRY_VERIFY,
            self::STATE_OCCUPIED,
            self::STATE_EXIT_VERIFY,
            self::STATE_OCCUPANCY_UNCERTAIN,
        ], true) ? $state : self::STATE_VACANT_CONFIRMED;
    }

    private static function normalizePresence(string $presence): string
    {
        $presence = strtoupper(trim($presence));
        return in_array($presence, [
            IotSession::PRESENCE_PRESENT,
            IotSession::PRESENCE_ABSENT,
            IotSession::PRESENCE_UNKNOWN,
        ], true) ? $presence : IotSession::PRESENCE_UNKNOWN;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (int) $value : null;
    }
}
