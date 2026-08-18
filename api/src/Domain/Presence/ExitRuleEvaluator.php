<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Rooms\RoomType;
use App\Domain\Rooms\RoomTypeRepositoryInterface;
use App\Support\Clock;

/**
 * ExitRuleEvaluator: decides whether the exit rule has fired for a room.
 *
 * Rule (design.md §8.2, F31):
 *   a) door_state transitioned to CLOSED within the last DOOR_CLOSE_WINDOW_S seconds.
 *      Anchored to last_close_at (F31). Fallback to last_open_at for legacy rows.
 *   b) door_state must be CLOSED at the time of evaluation.
 *   c) presence_state=ABSENT sustained >= room_type.exit_presence_gap_seconds.
 *
 * All three conditions must hold simultaneously.
 *
 * Two entry-points:
 *   evaluate(session, gapSeconds, nowTs)  — pure, no I/O, used by IotSessionService
 *                                           and directly by unit tests.
 *   shouldExit(roomId, nowTs)             — loads IotSession + RoomType from repos;
 *                                           used by exit-scan.php and processEvent.
 *
 * See RF-7, TSK-F31-4.
 */
final class ExitRuleEvaluator
{
    /**
     * Seconds after a door-close event during which we still consider the door
     * "recently closed" for exit-rule purposes (F31: anchored to close, not open).
     * Tolerates long-open doors and presence radars with slow retention clearing.
     */
    public const DOOR_CLOSE_WINDOW_S = 60;

    private IotSessionRepositoryInterface $iotSessions;
    private RoomRepositoryInterface       $rooms;
    private RoomTypeRepositoryInterface   $roomTypes;

    public function __construct(
        IotSessionRepositoryInterface $iotSessions,
        RoomRepositoryInterface       $rooms,
        RoomTypeRepositoryInterface   $roomTypes
    ) {
        $this->iotSessions = $iotSessions;
        $this->rooms       = $rooms;
        $this->roomTypes   = $roomTypes;
    }

    /**
     * High-level entry point: loads the session and room-type from repositories
     * and delegates to evaluate().
     *
     * Returns false if the room or its session/config cannot be resolved.
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

        // RF-30: room-level override takes precedence over room_type config
        if ($room->presenceCheckSeconds !== null) {
            $gapSeconds = $room->presenceCheckSeconds;
        } else {
            $roomType   = $this->roomTypes->findById($room->roomTypeId);
            $gapSeconds = $roomType instanceof RoomType
                ? $roomType->exitPresenceGapSeconds
                : 15; // conservative default when room_type is missing
        }

        return $this->evaluate($session, $gapSeconds, $nowTs);
    }

    /**
     * Pure evaluation: no repository calls.
     * Accepts pre-loaded IotSession and the gap threshold in seconds.
     *
     * @param int $exitPresenceGapSeconds room_type.exit_presence_gap_seconds
     * @param int $nowTs                  Unix timestamp for "now"
     */
    public function evaluate(
        IotSession $session,
        int        $exitPresenceGapSeconds,
        int        $nowTs
    ): bool {
        // Condition a (F31): a door-close event must have occurred recently.
        // Anchored to last_close_at; fallback to last_open_at for legacy rows
        // created before migration 0038.
        $closeAt = $session->lastCloseAt ?? $session->lastOpenAt;
        if ($closeAt === null) {
            return false;
        }
        // All DATETIME(3) values are stored as UTC strings; append ' UTC' so
        // strtotime() does not apply the local (Europe/Madrid) offset.
        $closeTs = strtotime($closeAt . ' UTC');
        if ($closeTs === false) {
            return false;
        }
        if (($nowTs - $closeTs) > self::DOOR_CLOSE_WINDOW_S) {
            return false; // door-close event is too stale
        }

        // Condition b (F28): the door must currently be CLOSED.
        // The rule fires when the door was opened, then closed, and the
        // guest has been absent for the required gap. If the door is still
        // open, the guest might still be entering/exiting.
        if ($session->doorState !== IotSession::DOOR_CLOSED) {
            return false;
        }

        // Condition c: presence must be ABSENT and sustained long enough.
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
