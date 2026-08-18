<?php
declare(strict_types=1);

namespace App\Domain\Rooms;

use App\Support\Errors\ConflictException;
use App\Support\Errors\UnprocessableException;

/**
 * RoomStateService: admin-initiated room status transitions.
 *
 * The operational lifecycle (RESERVED -> OCCUPIED -> EXITED ...) is driven
 * by QR issue/validation, presence events and stays lifecycle. Admins can
 * only trigger the transitions listed in ADMIN_ALLOWED below, which are the
 * ones that make sense outside of the normal flow:
 *
 *   FREE            -> CLEANING, OUT_OF_SERVICE
 *   EXITED          -> CLEANING, FREE
 *   CLEANING        -> FREE, OUT_OF_SERVICE
 *   OUT_OF_SERVICE  -> FREE
 *
 * Transitions from RESERVED / OCCUPIED / OVERSTAY are rejected here because
 * they require domain-level side effects (cancel the stay, force lock, ...).
 * Those will land in the stay lifecycle services (TSK-051 onwards).
 */
final class RoomStateService
{
    /** @var array<string, list<string>> */
    private const ADMIN_ALLOWED = [
        Room::STATUS_FREE            => [Room::STATUS_CLEANING, Room::STATUS_OUT_OF_SERVICE],
        Room::STATUS_EXITED          => [Room::STATUS_CLEANING, Room::STATUS_FREE],
        Room::STATUS_CLEANING        => [Room::STATUS_FREE, Room::STATUS_OUT_OF_SERVICE],
        Room::STATUS_OUT_OF_SERVICE  => [Room::STATUS_FREE],
    ];

    private RoomRepositoryInterface $rooms;

    public function __construct(RoomRepositoryInterface $rooms)
    {
        $this->rooms = $rooms;
    }

    /**
     * Apply an admin-requested transition. Throws if the source state doesn't
     * allow the target via the admin path.
     */
    public function transitionAdmin(Room $room, string $toStatus): Room
    {
        if (!in_array($toStatus, [
            Room::STATUS_FREE,
            Room::STATUS_CLEANING,
            Room::STATUS_OUT_OF_SERVICE,
        ], true)) {
            throw new UnprocessableException(
                'invalid_target_status',
                'Admin may only transition to FREE, CLEANING or OUT_OF_SERVICE'
            );
        }

        $allowed = self::ADMIN_ALLOWED[$room->status] ?? [];
        if (!in_array($toStatus, $allowed, true)) {
            throw new ConflictException(
                'stay_wrong_state',
                'Transition not allowed from current room status',
                ['from' => $room->status, 'to' => $toStatus]
            );
        }

        $this->rooms->update($room->id, ['status' => $toStatus]);
        $room->status = $toStatus;
        return $room;
    }
}
