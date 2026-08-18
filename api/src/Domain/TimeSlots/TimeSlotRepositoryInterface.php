<?php
declare(strict_types=1);

namespace App\Domain\TimeSlots;

/**
 * TimeSlotRepositoryInterface: abstracts persistence from the domain service,
 * which allows unit tests to provide a deterministic implementation without
 * hitting the database.
 */
interface TimeSlotRepositoryInterface
{
    /** @return list<TimeSlot> */
    public function listForRoomType(int $roomTypeId): array;

    /**
     * @param list<TimeSlot> $slots
     */
    public function replaceAll(int $roomTypeId, array $slots): void;
}
