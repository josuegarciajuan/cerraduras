<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use App\Domain\Rooms\Room;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Rooms\RoomType;
use App\Domain\Rooms\RoomTypeRepositoryInterface;
use App\Domain\Stays\Stay;
use App\Domain\Stays\StayRepositoryInterface;
use App\Support\Clock;

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
 *   e) presence_state = ABSENT sustained >= gap seconds.
 *
 * Cancellation: a new PRESENT clears last_absent_since (done in
 * IotSessionService::mutate), so this evaluator returns false again.
 *
 * Two entry-points:
 *   evaluate(session, stay, gapSeconds, nowTs) — pure, no I/O.
 *   shouldExit(roomId, nowTs)                  — loads session/stay/room from repos.
 *
 * See RF-47, F31, Fase 41.
 */
final class ExitRuleEvaluator
{
    /**
     * F41: maximum age (seconds) of the door cycle for it to still be a valid
     * exit precondition. Replaces DOOR_CLOSE_WINDOW_S=60; tolerates slow radars.
     */
    public const DOOR_CYCLE_MAX_S = 300;

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

        return $this->evaluate($session, $stay, $this->resolveGapSeconds($room), $nowTs);
    }

    /**
     * Resolve the absence gap: room override > room_type > 15 s (contracts §3.3).
     */
    public function resolveGapSeconds(Room $room): int
    {
        if ($room->presenceCheckSeconds !== null) {
            return (int) $room->presenceCheckSeconds;
        }
        $roomType = $this->roomTypes->findById($room->roomTypeId);
        return $roomType instanceof RoomType ? (int) $roomType->exitPresenceGapSeconds : 15;
    }

    /**
     * Pure evaluation: no repository calls.
     *
     * @param Stay|null $stay Active stay (null when none; rule never fires).
     * @param int $exitPresenceGapSeconds room_type.exit_presence_gap_seconds
     * @param int $nowTs                  Unix timestamp for "now"
     */
    public function evaluate(
        IotSession $session,
        ?Stay      $stay,
        int        $exitPresenceGapSeconds,
        int        $nowTs
    ): bool {
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

        // (d) Sustained absence.
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

        return ($nowTs - $absentTs) >= $exitPresenceGapSeconds;
    }
}
