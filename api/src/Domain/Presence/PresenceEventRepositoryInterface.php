<?php
declare(strict_types=1);

namespace App\Domain\Presence;

interface PresenceEventRepositoryInterface
{
    /**
     * Persist a presence event.
     *
     * Returns the new row ID on success.
     * Returns null if source_event_id already exists (idempotent duplicate).
     *
     * @param array<string,mixed>|null $meta
     */
    public function insert(
        int     $roomId,
        string  $sensor,
        string  $value,
        string  $provider,
        string  $occurredAt,
        ?string $sourceEventId,
        ?array  $meta
    ): ?int;

    /**
     * Return the latest N events for a room, newest first.
     *
     * @return list<PresenceEvent>
     */
    public function listForRoom(int $roomId, int $limit = 20): array;
}
