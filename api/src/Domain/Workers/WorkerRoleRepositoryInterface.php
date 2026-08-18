<?php
declare(strict_types=1);

namespace App\Domain\Workers;

/**
 * WorkerRoleRepositoryInterface: persistence contract for worker roles.
 *
 * See RF-W2, TSK-W03.
 */
interface WorkerRoleRepositoryInterface
{
    public function findById(int $id): ?WorkerRole;

    /** @return list<WorkerRole> */
    public function findAll(): array;

    /**
     * Insert a new role. Returns the new ID.
     * Call assignRoomTypes() separately to set the pivot rows.
     */
    public function insert(WorkerRole $role): int;

    /**
     * Partial update (name, description). Does NOT touch room_type pivot.
     * Use assignRoomTypes() for that.
     *
     * @param array<string,mixed> $fields
     * @return int rowCount
     */
    public function update(int $id, array $fields): int;

    /**
     * Hard-delete a role. Returns rowCount.
     * Caller is responsible for checking that no workers are assigned.
     */
    public function delete(int $id): int;

    /**
     * Replace the set of room_types assigned to a role (sync-style: delete all, re-insert).
     *
     * @param list<int> $roomTypeIds
     */
    public function assignRoomTypes(int $roleId, array $roomTypeIds): void;

    /**
     * Get the list of room_type_ids assigned to a role.
     *
     * @return list<int>
     */
    public function getRoomTypesForRole(int $roleId): array;

    /**
     * Count workers assigned to a role.
     */
    public function countWorkersForRole(int $roleId): int;

    /**
     * Check if a role can access a specific room_type.
     */
    public function canAccessRoomType(int $roleId, int $roomTypeId): bool;
}
