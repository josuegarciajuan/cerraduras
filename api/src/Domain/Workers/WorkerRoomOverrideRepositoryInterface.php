<?php
declare(strict_types=1);

namespace App\Domain\Workers;

/**
 * WorkerRoomOverrideRepositoryInterface: per-worker ALLOW/DENY exception over a
 * room type (F62/RF-69.2). The role grant remains the base decision.
 */
interface WorkerRoomOverrideRepositoryInterface
{
    /** Returns 'ALLOW' | 'DENY' | null for one (worker, room_type). */
    public function getEffect(int $workerId, int $roomTypeId): ?string;

    /** Upsert the exception. */
    public function setEffect(int $workerId, int $roomTypeId, string $effect): void;

    /** Remove the exception (falls back to the role). */
    public function deleteEffect(int $workerId, int $roomTypeId): void;

    /**
     * @return array<int,string> room_type_id => effect
     */
    public function listForWorker(int $workerId): array;
}
