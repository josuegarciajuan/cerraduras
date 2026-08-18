<?php
declare(strict_types=1);

namespace App\Domain\Presence;

interface IotSessionRepositoryInterface
{
    public function findByRoomId(int $roomId): ?IotSession;

    /**
     * Insert or update the session row for the given room.
     * Uses INSERT … ON DUPLICATE KEY UPDATE internally.
     */
    public function upsert(IotSession $session): void;
}
