<?php
declare(strict_types=1);

namespace App\Domain\Rooms;

/**
 * RoomTypeRepositoryInterface: read/write operations on room_types.
 */
interface RoomTypeRepositoryInterface
{
    /** @return list<RoomType> */
    public function listAll(): array;

    public function findById(int $id): ?RoomType;

    public function findByCode(string $code): ?RoomType;

    public function insert(
        string $code,
        string $name,
        int $graceMinutes,
        int $exitPresenceGapSeconds,
        int $reentryCooldownSeconds,
        int $qrUsageWindowMinutes
    ): int;

    /**
     * @param array<string,int|string> $fields
     */
    public function update(int $id, array $fields): int;
}
