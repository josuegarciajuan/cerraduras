<?php
declare(strict_types=1);

namespace App\Domain\Presence;

/**
 * IotSession: derived state for one room, maintained in iot_sessions (one row
 * per room, updated on each sensor event).
 *
 * door_state:     OPEN | CLOSED | UNKNOWN
 * presence_state: PRESENT | ABSENT | UNKNOWN
 * last_open_at:         UTC DATETIME(3) of the most recent PROXIMITY=OPEN event.
 * last_close_at:        UTC DATETIME(3) of the most recent PROXIMITY=CLOSED event (F31).
 * last_absent_since:    UTC DATETIME(3) when presence first transitioned to ABSENT.
 * exit_evaluated_at:    UTC DATETIME(3) when the exit rule last fired.
 * last_door_event_at:      F41: occurred_at of the last applied door event (ordering).
 * last_presence_event_at:  F41: occurred_at of the last applied presence event.
 * last_door_value:         F41: last applied door value (OPEN|CLOSED).
 * last_presence_value:     F41: last applied presence value (PRESENT|ABSENT).
 *
 * See design.md §4.10, §8.2 and Fase 41 §2.2.
 */
final class IotSession
{
    public const DOOR_OPEN    = 'OPEN';
    public const DOOR_CLOSED  = 'CLOSED';
    public const DOOR_UNKNOWN = 'UNKNOWN';

    public const PRESENCE_PRESENT = 'PRESENT';
    public const PRESENCE_ABSENT  = 'ABSENT';
    public const PRESENCE_UNKNOWN = 'UNKNOWN';

    public int $id;
    public int $roomId;
    public ?int $stayId;
    public string $doorState;
    public string $presenceState;
    public ?string $lastOpenAt;
    public ?string $lastCloseAt;       // F31: anchored to CLOSED event
    public ?string $lastAbsentSince;
    public ?string $exitEvaluatedAt;
    public string $updatedAt;

    // F41 ordering marks (nullable; NULL on legacy rows).
    public ?string $lastDoorEventAt;
    public ?string $lastPresenceEventAt;
    public ?string $lastDoorValue;
    public ?string $lastPresenceValue;

    public function __construct(
        int     $id,
        int     $roomId,
        ?int    $stayId,
        string  $doorState,
        string  $presenceState,
        ?string $lastOpenAt,
        ?string $lastCloseAt,
        ?string $lastAbsentSince,
        ?string $exitEvaluatedAt,
        string  $updatedAt,
        ?string $lastDoorEventAt = null,
        ?string $lastPresenceEventAt = null,
        ?string $lastDoorValue = null,
        ?string $lastPresenceValue = null
    ) {
        $this->id                  = $id;
        $this->roomId              = $roomId;
        $this->stayId              = $stayId;
        $this->doorState           = $doorState;
        $this->presenceState       = $presenceState;
        $this->lastOpenAt          = $lastOpenAt;
        $this->lastCloseAt         = $lastCloseAt;
        $this->lastAbsentSince     = $lastAbsentSince;
        $this->exitEvaluatedAt     = $exitEvaluatedAt;
        $this->updatedAt           = $updatedAt;
        $this->lastDoorEventAt     = $lastDoorEventAt;
        $this->lastPresenceEventAt = $lastPresenceEventAt;
        $this->lastDoorValue       = $lastDoorValue;
        $this->lastPresenceValue   = $lastPresenceValue;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'door_state'       => $this->doorState,
            'presence_state'   => $this->presenceState,
            'last_open_at'     => $this->lastOpenAt,
            'last_close_at'    => $this->lastCloseAt,
            'last_absent_since'=> $this->lastAbsentSince,
            'last_door_event_at'     => $this->lastDoorEventAt,
            'last_presence_event_at' => $this->lastPresenceEventAt,
            'last_door_value'        => $this->lastDoorValue,
            'last_presence_value'    => $this->lastPresenceValue,
        ];
    }
}
