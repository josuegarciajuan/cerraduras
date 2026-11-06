<?php
declare(strict_types=1);

namespace App\Domain\Presence;

/**
 * KeyEpisodePolicy — F118.4: episodio de ficha/NFC sin estancia.
 *
 * Deriva de forma PURA la fase de una ficha a partir del último acceso válido
 * (key_access_events) y de los eventos de puerta/presencia posteriores. Se usa
 * en /live y en el SSE para que todos los dispositivos reciban la misma fase
 * (pending/inside/exit/outside), sin estado local en el navegador.
 *
 * Reglas:
 *  - pending: el acceso existe, pero aún no hay un cierre de entrada.
 *  - outside: hubo cierre de entrada sin presencia durante la ventana → no entró.
 *  - inside: entrada confirmada por cierre + presencia; SIN caducidad temporal.
 *  - exit: apertura posterior a la entrada; se cierra por ausencia posterior al
 *    cierre o por EXIT_MAX_VERIFY_SECONDS (15 s) desde el cierre.
 */
final class KeyEpisodePolicy
{
    public const PHASE_IDLE = 'idle';
    public const PHASE_PENDING = 'pending';
    public const PHASE_INSIDE = 'inside';
    public const PHASE_EXIT = 'exit';
    public const PHASE_OUTSIDE = 'outside';

    private const ENTRY_WINDOW_SECONDS = 90;

    /**
     * @param array{key_id?:?int,worker_id?:?int,credential_type?:?string,occurred_at:string}|null $key
     * @param list<array{sensor:string,value:string,occurred_at:string}> $events
     * @return array{active:bool,phase:string,key_id:?int,worker_id:?int,credential_type:?string,
     *               at:?string,entry_open_at:?string,entry_closed_at:?string,entry_confirmed:bool,
     *               exit_open_at:?string,exit_closed_at:?string}
     */
    public static function derive(
        ?array $key,
        array $events,
        string $presenceState,
        ?string $lastAbsentSince,
        int $nowTs,
        int $maxExitSeconds = 15
    ): array {
        $empty = [
            'active' => false,
            'phase' => self::PHASE_IDLE,
            'key_id' => null,
            'worker_id' => null,
            'credential_type' => null,
            'at' => null,
            'entry_open_at' => null,
            'entry_closed_at' => null,
            'entry_confirmed' => false,
            'exit_open_at' => null,
            'exit_closed_at' => null,
        ];
        if ($key === null || empty($key['occurred_at'])) {
            return $empty;
        }

        $keyTs = self::ts((string) $key['occurred_at']);
        if ($keyTs === null) {
            return $empty;
        }

        $entryOpen = null;
        $entryClose = null;
        $entryConfirmed = false;
        $exitOpen = null;
        $exitClose = null;

        foreach ($events as $event) {
            $sensor = strtoupper((string) ($event['sensor'] ?? ''));
            $value = strtoupper((string) ($event['value'] ?? ''));
            $ts = self::ts((string) ($event['occurred_at'] ?? ''));
            if ($ts === null || $ts < $keyTs) {
                continue;
            }

            if ($sensor === PresenceEvent::SENSOR_PROXIMITY) {
                if ($value === PresenceEvent::VALUE_OPEN) {
                    if ($entryOpen === null) {
                        $entryOpen = $ts;
                    } elseif ($entryClose !== null) {
                        // Nueva apertura tras un cierre: nuevo ciclo de salida/reentrada.
                        $exitOpen = $ts;
                        $exitClose = null;
                    }
                } elseif ($value === PresenceEvent::VALUE_CLOSED) {
                    if ($entryOpen !== null && $entryClose === null && $ts >= $entryOpen) {
                        $entryClose = $ts;
                    } elseif ($exitOpen !== null && $exitClose === null && $ts >= $exitOpen) {
                        $exitClose = $ts;
                    }
                }
                continue;
            }

            if ($sensor === PresenceEvent::SENSOR_PRESENCE
                && $value === PresenceEvent::VALUE_PRESENT
                && !$entryConfirmed
            ) {
                if ($entryOpen !== null && $entryClose === null) {
                    $entryConfirmed = true; // presencia vista con la puerta abierta
                } elseif ($entryClose !== null && $ts <= $entryClose + self::ENTRY_WINDOW_SECONDS) {
                    $entryConfirmed = true; // presencia tardía dentro de la ventana
                }
            }
        }

        $phase = self::PHASE_OUTSIDE;
        if ($entryClose === null) {
            $phase = self::PHASE_PENDING;
        } elseif (!$entryConfirmed) {
            $phase = self::PHASE_OUTSIDE;
        } elseif ($exitOpen === null) {
            $phase = self::PHASE_INSIDE;
        } elseif ($exitClose === null) {
            $phase = self::PHASE_EXIT;
        } else {
            $absentTs = $lastAbsentSince !== null ? self::ts($lastAbsentSince) : null;
            $absentAfterExit = $presenceState === IotSession::PRESENCE_ABSENT
                && $absentTs !== null && $absentTs >= $exitClose;
            $maxReached = $maxExitSeconds > 0 && ($nowTs - $exitClose) >= $maxExitSeconds;
            $phase = ($absentAfterExit || $maxReached)
                ? self::PHASE_OUTSIDE : self::PHASE_EXIT;
        }

        $active = in_array($phase, [self::PHASE_PENDING, self::PHASE_INSIDE, self::PHASE_EXIT], true);

        return [
            'active' => $active,
            'phase' => $phase,
            'key_id' => isset($key['key_id']) && $key['key_id'] !== null ? (int) $key['key_id'] : null,
            'worker_id' => isset($key['worker_id']) && $key['worker_id'] !== null ? (int) $key['worker_id'] : null,
            'credential_type' => isset($key['credential_type']) && $key['credential_type'] !== null
                ? (string) $key['credential_type'] : null,
            'at' => (string) $key['occurred_at'],
            'entry_open_at' => self::iso($entryOpen),
            'entry_closed_at' => self::iso($entryClose),
            'entry_confirmed' => $entryConfirmed,
            'exit_open_at' => self::iso($exitOpen),
            'exit_closed_at' => self::iso($exitClose),
        ];
    }

    private static function ts(string $sql): ?int
    {
        if ($sql === '') {
            return null;
        }
        $ts = strtotime($sql . ' UTC');
        return $ts === false ? null : $ts;
    }

    private static function iso(?int $ts): ?string
    {
        return $ts === null ? null : gmdate('Y-m-d H:i:s', $ts) . '.000';
    }
}
