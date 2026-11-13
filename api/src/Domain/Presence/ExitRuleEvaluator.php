<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use App\Domain\Rooms\Room;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Rooms\RoomTypeRepositoryInterface;
use App\Domain\Stays\Stay;
use App\Domain\Stays\StayRepositoryInterface;
use App\Support\Clock;
use App\Support\Config;

/**
 * ExitRuleEvaluator: decides whether the exit rule has fired for a room.
 *
 * Fase 41 (RF-47, contracts.md §3.2): the rule no longer depends on the
 * current door state. It requires a *credited door cycle* posterior to the
 * entry confirmation:
 *   a) stay.entry_confirmed_at is set (guest was confirmed inside);
 *   b) last_open_at >= entry_confirmed_at (an opening happened from inside).
 *      Non-strict because occurred_at has second granularity: a same-second
 *      entry-close / exit-open must still count as a credited cycle.
 *   c) last_close_at >= last_open_at (the door was closed);
 *   d) the close is not arbitrarily old (DOOR_CYCLE_MAX_S = 300 s);
 *   e) presence_state = ABSENT sustained >= exit guard seconds.
 *
 * F118.2 (salida en tiempo real): si el radar sigue afirmando PRESENT pero no
 * ha emitido NINGÚN reporte de presencia posterior al cierre durante
 * EXIT_NO_PRESENCE_AFTER_CLOSE_SECONDS (3 s por defecto), se considera salida.
 * Es el mismo heurístico que usa el monigote: evita esperar a que el radar
 * pierda un objetivo del pasillo que sigue dentro de su alcance.
 *
 * Bug 1 (desacoplamiento entrada/salida): la guarda de SALIDA ya NO es
 * `gap_seconds`. Se resuelve con resolveGuardSeconds():
 *   room.presence_check_seconds > EXIT_ABSENCE_GUARD_SECONDS > 1 s.
 * La ventana de ENTRADA es independiente y vive en
 * room_types.presence_entry_window_seconds (default 90 s), leída por
 * IotSessionService::resolveEntryWindowSeconds().
 *
 * Cancellation: a new PRESENT clears last_absent_since (done in
 * IotSessionService::mutate), so this evaluator returns false again.
 *
 * Two entry-points:
 *   evaluate(session, stay, exitGuardSeconds, nowTs) — pure, no I/O.
 *   shouldExit(roomId, nowTs)                        — loads session/stay/room from repos.
 *
 * See RF-47, F31, Fase 41, Bug 1.
 */
final class ExitRuleEvaluator
{
    /**
     * F41: maximum age (seconds) of the door cycle for it to still be a valid
     * exit precondition. Replaces DOOR_CLOSE_WINDOW_S=60; tolerates slow radars.
     */
    public const DOOR_CYCLE_MAX_S = 300;

    /**
     * Bug 1/F118.2: default sustained-absence guard (seconds) for the EXIT rule
     * when neither a per-room override nor the global env override is set.
     */
    public const DEFAULT_GUARD_SECONDS = 1;

    /**
     * F118.2: si no llega ningún reporte de presencia posterior al cierre en
     * este tiempo, la salida se considera confirmada aunque el radar siga
     * afirmando PRESENT (objetivo del pasillo dentro del alcance).
     */
    public const DEFAULT_NO_PRESENCE_AFTER_CLOSE_SECONDS = 3;

    /**
     * F118.3: máximo de verificación de salida desde el cierre. Si el radar
     * sigue en PRESENT (target del pasillo), la salida se confirma igualmente.
     * 0 desactiva el máximo. El usuario fijó 15 s.
     */
    public const DEFAULT_MAX_VERIFY_SECONDS = 15;

    private IotSessionRepositoryInterface $iotSessions;
    private RoomRepositoryInterface       $rooms;
    private RoomTypeRepositoryInterface   $roomTypes;
    private StayRepositoryInterface       $stays;

    public function __construct(
        IotSessionRepositoryInterface $iotSessions,
        RoomRepositoryInterface       $rooms,
        RoomTypeRepositoryInterface   $roomTypes,
        StayRepositoryInterface       $stays
    ) {
        $this->iotSessions = $iotSessions;
        $this->rooms       = $rooms;
        $this->roomTypes   = $roomTypes;
        $this->stays       = $stays;
    }

    /**
     * High-level entry point: loads the session, active stay and gap config and
     * delegates to evaluate(). Returns false if anything cannot be resolved.
     */
    public function shouldExit(int $roomId, ?int $nowTs = null): bool
    {
        $nowTs = $nowTs ?? Clock::nowUtc()->getTimestamp();

        $session = $this->iotSessions->findByRoomId($roomId);
        if ($session === null) {
            return false;
        }

        $room = $this->rooms->findById($roomId);
        if ($room === null) {
            return false;
        }

        $stay = $this->stays->findActiveForRoom($roomId);

        return $this->evaluate(
            $session,
            $stay,
            $this->resolveGapSeconds($room),
            $nowTs,
            $this->resolveNoPresenceAfterCloseSecondsValue(),
            $this->resolveMaxVerifySecondsValue()
        );
    }

    /**
     * Resolve the exit absence guard (Bug 1): per-room override > global env
     * override > DEFAULT_GUARD_SECONDS.
     *
     * Kept under the historic name `resolveGapSeconds` for wiring
     * compatibility (shouldExit / ExitActionService), but it now returns the
     * EXIT GUARD, not the legacy `gap_seconds` used by the UI.
     */
    public function resolveGapSeconds(Room $room): int
    {
        return self::resolveGuardSeconds(
            $room->presenceCheckSeconds,
            Config::getInt('EXIT_ABSENCE_GUARD_SECONDS', null)
        );
    }

    /**
     * Pure resolution of the exit guard (Bug 1, RF-47.2.4):
     *   - room override, when present and positive;
     *   - else the global override, when present and positive;
     *   - else DEFAULT_GUARD_SECONDS (3 s).
     *
     * A non-positive value is treated as "not set" so legacy `0` rows or an
     * empty env do not freeze the exit rule.
     */
    public static function resolveGuardSeconds(?int $roomOverride, ?int $globalOverride): int
    {
        if ($roomOverride !== null && $roomOverride > 0) {
            return $roomOverride;
        }
        if ($globalOverride !== null && $globalOverride > 0) {
            return $globalOverride;
        }
        return self::DEFAULT_GUARD_SECONDS;
    }

    /**
     * F118.2: ventana sin reportes de presencia posteriores al cierre.
     * 0 desactiva el heurístico (vuelve al comportamiento solo-ABSENT).
     */
    public static function resolveNoPresenceAfterCloseSeconds(?int $globalOverride): int
    {
        if ($globalOverride !== null && $globalOverride >= 0) {
            return $globalOverride;
        }
        return self::DEFAULT_NO_PRESENCE_AFTER_CLOSE_SECONDS;
    }

    public function resolveNoPresenceAfterCloseSecondsValue(): int
    {
        return self::resolveNoPresenceAfterCloseSeconds(
            Config::getInt('EXIT_NO_PRESENCE_AFTER_CLOSE_SECONDS', null)
        );
    }

    /**
     * F118.3: máximo de verificación de salida (segundos). 0 desactiva.
     */
    public static function resolveMaxVerifySeconds(?int $globalOverride): int
    {
        if ($globalOverride !== null && $globalOverride >= 0) {
            return $globalOverride;
        }
        return self::DEFAULT_MAX_VERIFY_SECONDS;
    }

    public function resolveMaxVerifySecondsValue(): int
    {
        return self::resolveMaxVerifySeconds(
            Config::getInt('EXIT_MAX_VERIFY_SECONDS', null)
        );
    }

    /**
     * Pure evaluation: no repository calls.
     *
     * @param Stay|null $stay Active stay (null when none; rule never fires).
     * @param int $exitGuardSeconds sustained-absence guard for the exit rule
     *                              (Bug 1: NOT the legacy gap_seconds).
     * @param int $nowTs            Unix timestamp for "now"
     */
    public function evaluate(
        IotSession $session,
        ?Stay      $stay,
        int        $exitGuardSeconds,
        int        $nowTs,
        int        $noPresenceAfterCloseSeconds = 0,
        int        $maxVerifySeconds = 0
    ): bool {
        // F138: en STANDARD el motor de ocupación es la autoridad. La regla
        // antigua solo puede cerrar cuando la sesión ya está VACANT_CONFIRMED.
        // En salas especiales occupancyState es NULL y se conserva el flujo.
        if ($session->occupancyState !== null
            && $session->occupancyState !== StandardOccupancyPolicy::STATE_VACANT_CONFIRMED
        ) {
            return false;
        }

        // (a) Entry must have been confirmed by the backend.
        if ($stay === null || $stay->entryConfirmedAt === null) {
            return false;
        }
        if ($session->lastOpenAt === null || $session->lastCloseAt === null) {
            return false;
        }

        // All DATETIME(3) values are stored as UTC strings; append ' UTC' so
        // strtotime() does not apply the local offset.
        $openTs  = strtotime($session->lastOpenAt . ' UTC');
        $closeTs = strtotime($session->lastCloseAt . ' UTC');
        $entryTs = strtotime($stay->entryConfirmedAt . ' UTC');
        if ($openTs === false || $closeTs === false || $entryTs === false) {
            return false;
        }

        // (b) Credited cycle: an opening at/after entry confirmation, then closed.
        // Non-strict comparison: occurred_at has second granularity, so a
        // same-second entry-close / exit-open is still a valid exit cycle.
        if (!($openTs >= $entryTs)) {
            return false;
        }
        if (!($closeTs >= $openTs)) {
            return false;
        }

        // (c) Safety bound: the cycle is not arbitrarily old.
        if (($nowTs - $closeTs) > self::DOOR_CYCLE_MAX_S) {
            return false;
        }

        // (c2) F118.3 — máximo de verificación desde el cierre. Si el radar
        // sigue viendo el pasillo, no se puede esperar indefinidamente.
        if ($maxVerifySeconds > 0 && ($nowTs - $closeTs) >= $maxVerifySeconds) {
            return true;
        }

        // (d) F118.2 — salida sin reportes de presencia posteriores al cierre.
        // El radar puede seguir viendo el pasillo dentro de su alcance y no
        // emitir ABSENT; si no llega ningún reporte PRESENT/ABSENT después del
        // cierre en la ventana, la salida se confirma.
        if ($session->presenceState === IotSession::PRESENCE_PRESENT
            && $noPresenceAfterCloseSeconds > 0
            && $session->lastPresenceEventAt !== null
        ) {
            $presenceTs = strtotime($session->lastPresenceEventAt . ' UTC');
            if ($presenceTs !== false
                && $presenceTs <= $closeTs
                && ($nowTs - $closeTs) >= $noPresenceAfterCloseSeconds
            ) {
                return true;
            }
            return false;
        }

        // (e) Sustained absence.
        if ($session->presenceState !== IotSession::PRESENCE_ABSENT) {
            return false;
        }
        if ($session->lastAbsentSince === null) {
            return false;
        }
        $absentTs = strtotime($session->lastAbsentSince . ' UTC');
        if ($absentTs === false) {
            return false;
        }

        return ($nowTs - $absentTs) >= $exitGuardSeconds;
    }
}
