<?php
declare(strict_types=1);

namespace App\Domain\Rooms;

/**
 * RoomRepositoryInterface: read/write operations on rooms.
 *
 * Introduced to allow domain services (QrIssueService and beyond) to be
 * unit-tested with deterministic in-memory implementations.
 */
interface RoomRepositoryInterface
{
    /**
     * @param array<string,mixed> $filters
     * @return list<Room>
     */
    public function listFiltered(array $filters, int $limit = 50, int $offset = 0): array;

    public function findById(int $id): ?Room;

    public function findByCode(string $code): ?Room;

    public function insert(string $code, int $roomTypeId, ?bool $simulatedOverride): int;

    /**
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): int;

    /**
     * After removing a pack from a room, reset status to FREE
     * and close active stays if the room was RESERVED or OCCUPIED.
     * No-op for any other status.
     */
    public function resetAfterPackRemoval(int $roomId): void;

    /**
     * Find a room by its assigned pack_id. Returns null if no room
     * currently has this pack assigned.
     */
    public function findByPackId(int $packId): ?Room;
}
