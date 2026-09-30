<?php
declare(strict_types=1);

namespace App\Domain\Workers;

/**
 * WarehouseAccessPolicyInterface: resolves whether a worker may access a room
 * type, combining the role grant with an optional per-worker exception
 * (F62/RF-69).
 */
interface WarehouseAccessPolicyInterface
{
    public function canAccess(int $workerId, int $roleId, int $roomTypeId): bool;
}
