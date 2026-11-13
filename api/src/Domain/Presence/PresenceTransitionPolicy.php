<?php
declare(strict_types=1);

namespace App\Domain\Presence;

/**
 * PresenceTransitionPolicy — F132.
 *
 * Política pura de transiciones de presencia para salas con un modo radar
 * explícito. Separa:
 *
 *   - entrada: una detección admisible no se publica como PRESENT hasta que se
 *     mantiene durante `entry_ms` (anti-parpadeo de una sola trama);
 *   - ausencia: un ABSENT auténtico no borra una presencia establecida hasta
 *     que se sostiene durante `absent_ms`.
 *
 * Las salas de almacén y TIMBRADO conservan su comportamiento anterior
 * (entrada inmediata y gracia configurada, por defecto 0) para no alterar
 * F114/F117. Una sala normal solo entra en esta política con un override
 * explícito en `rooms.presence_mode_override`.
 *
 * No hace I/O ni consulta configuración: recibe los valores ya resueltos.
 */
final class PresenceTransitionPolicy
{
    public const OUTCOME_APPLIED          = 'applied';
    public const OUTCOME_MAINTAINED       = 'maintained';
    public const OUTCOME_PENDING_PRESENT  = 'pending_present';
    public const OUTCOME_PENDING_ABSENT   = 'pending_absent';
    public const OUTCOME_BLOCKED          = 'blocked';
    public const OUTCOME_UNVERIFIED       = 'unverified';
    public const OUTCOME_OUT_OF_RANGE     = 'out_of_range';
    public const OUTCOME_NOOP             = 'noop';
    public const OUTCOME_DUPLICATE        = 'duplicate';
    public const OUTCOME_STALE            = 'stale';

    public const REASON_ENTRY_PENDING     = 'entry_pending';
    public const REASON_CANDIDATE_ABSENT  = 'candidate_absent';
    public const REASON_NO_CONTEXT        = 'no_context';
    public const REASON_SUPERSEDED        = 'superseded';

    public const DEFAULT_ENTRY_HOLD_SECONDS   = 1.5;
    public const DEFAULT_ABSENT_GRACE_SECONDS = 4.0;
    public const MAX_HOLD_SECONDS = 10.0;

    /**
     * @return array{entry_ms:int,absent_ms:int}
     */
    public static function holds(
        bool $explicitRadarOverride,
        bool $specialRoom,
        ?string $entryHoldRaw,
        ?string $absentGraceRaw
    ): array {
        if (!$explicitRadarOverride) {
            return ['entry_ms' => 0, 'absent_ms' => 0];
        }
        if ($specialRoom) {
            // Almacén/TIMBRADO: entrada inmediata; la gracia de ausencia es la
            // configurada (por defecto 0, comportamiento F114/F117).
            return [
                'entry_ms'  => 0,
                'absent_ms' => self::secondsToMs($absentGraceRaw, 0.0),
            ];
        }
        return [
            'entry_ms'  => self::secondsToMs($entryHoldRaw, self::DEFAULT_ENTRY_HOLD_SECONDS),
            'absent_ms' => self::secondsToMs($absentGraceRaw, self::DEFAULT_ABSENT_GRACE_SECONDS),
        ];
    }

    /**
     * Decide la transición pendiente con la evidencia ya validada.
     *
     * @param array{entry_ms:int,absent_ms:int} $holds
     * @return array{action:string,due_at_ms:?int,outcome:string,reason:?string,state:string}
     *         action ∈ ignore|apply|pending|maintain
     */
    public static function transition(
        string $value,
        string $currentPresence,
        ?string $pendingState,
        ?int $pendingDueMs,
        int $nowMs,
        array $holds
    ): array {
        $value = strtoupper(trim($value));
        $currentPresence = strtoupper(trim($currentPresence));
        $pendingState = $pendingState === null ? null : strtoupper(trim($pendingState));

        if ($holds['entry_ms'] <= 0 && $holds['absent_ms'] <= 0) {
            return self::transitionResult('ignore', null, self::OUTCOME_APPLIED, null, 'UNKNOWN');
        }

        if ($value === PresenceEvent::VALUE_PRESENT) {
            if ($pendingState === PresenceEvent::VALUE_PRESENT && $pendingDueMs !== null) {
                if ($nowMs >= $pendingDueMs) {
                    return self::transitionResult('apply', null, self::OUTCOME_APPLIED, null, 'PRESENT_CONFIRMED');
                }
                return self::transitionResult('pending', $pendingDueMs, self::OUTCOME_PENDING_PRESENT, self::REASON_ENTRY_PENDING, 'CANDIDATE_PRESENT');
            }
            if ($holds['entry_ms'] > 0 && $currentPresence !== 'PRESENT') {
                return self::transitionResult('pending', $nowMs + $holds['entry_ms'], self::OUTCOME_PENDING_PRESENT, self::REASON_ENTRY_PENDING, 'CANDIDATE_PRESENT');
            }
            return self::transitionResult('apply', null, self::OUTCOME_APPLIED, null, 'PRESENT_CONFIRMED');
        }

        if ($value === PresenceEvent::VALUE_ABSENT) {
            if ($currentPresence === 'ABSENT') {
                return self::transitionResult('maintain', null, self::OUTCOME_MAINTAINED, 'noop', 'ABSENT_CONFIRMED');
            }
            if ($holds['absent_ms'] <= 0) {
                return self::transitionResult('apply', null, self::OUTCOME_APPLIED, null, 'ABSENT_CONFIRMED');
            }
            if ($pendingState === PresenceEvent::VALUE_ABSENT && $pendingDueMs !== null) {
                if ($nowMs >= $pendingDueMs) {
                    return self::transitionResult('apply', null, self::OUTCOME_APPLIED, null, 'ABSENT_CONFIRMED');
                }
                return self::transitionResult('pending', $pendingDueMs, self::OUTCOME_PENDING_ABSENT, self::REASON_CANDIDATE_ABSENT, 'CANDIDATE_ABSENT');
            }
            return self::transitionResult('pending', $nowMs + $holds['absent_ms'], self::OUTCOME_PENDING_ABSENT, self::REASON_CANDIDATE_ABSENT, 'CANDIDATE_ABSENT');
        }

        return self::transitionResult('ignore', null, self::OUTCOME_MAINTAINED, null, 'UNKNOWN');
    }

    /** @return array{action:string,due_at_ms:?int,outcome:string,reason:?string,state:string} */
    private static function transitionResult(
        string $action,
        ?int $dueAtMs,
        string $outcome,
        ?string $reason,
        string $state
    ): array {
        return [
            'action' => $action,
            'due_at_ms' => $dueAtMs,
            'outcome' => $outcome,
            'reason' => $reason,
            'state' => $state,
        ];
    }

    /**
     * Clasificación del resultado final para el panel. No decide la física:
     * normaliza un motivo ya calculado por el dominio.
     */
    public static function outcomeFor(?string $discardReason, bool $applied, bool $alreadyInForce = false): string
    {
        if ($applied) {
            return self::OUTCOME_APPLIED;
        }
        $reason = strtolower(trim((string) $discardReason));
        return match ($reason) {
            self::REASON_CANDIDATE_ABSENT => self::OUTCOME_PENDING_ABSENT,
            self::REASON_ENTRY_PENDING    => self::OUTCOME_PENDING_PRESENT,
            self::REASON_NO_CONTEXT,
            'uncorroborated',
            'candidate'                   => self::OUTCOME_BLOCKED,
            'range_unverified'            => self::OUTCOME_UNVERIFIED,
            'range_exceeded'              => self::OUTCOME_OUT_OF_RANGE,
            'stale'                       => self::OUTCOME_STALE,
            'duplicate'                   => self::OUTCOME_DUPLICATE,
            'noop', 'refresh'             => self::OUTCOME_MAINTAINED,
            default                       => $alreadyInForce ? self::OUTCOME_MAINTAINED : self::OUTCOME_BLOCKED,
        };
    }

    private static function secondsToMs(?string $raw, float $default): int
    {
        $value = $default;
        if (is_string($raw) && trim($raw) !== '' && is_numeric(trim($raw))) {
            $value = (float) trim($raw);
        }
        $value = max(0.0, min(self::MAX_HOLD_SECONDS, $value));
        return (int) round($value * 1000);
    }
}
