<?php
declare(strict_types=1);

namespace App\Domain\Workers;

use App\Support\Errors\ConflictException;
use App\Support\Errors\NotFoundException;
use App\Support\Errors\UnprocessableException;

/**
 * WorkerRoleService: business rules for worker role mutations.
 *
 * Thin service: validates input, delegates to repositories, maps errors.
 *
 * See RF-W2, TSK-W05.
 */
final class WorkerRoleService
{
    private WorkerRoleRepositoryInterface $repo;
    private WorkerRepositoryInterface $workerRepo;

    public function __construct(
        WorkerRoleRepositoryInterface $repo,
        WorkerRepositoryInterface $workerRepo
    ) {
        $this->repo       = $repo;
        $this->workerRepo = $workerRepo;
    }

    /**
     * List all roles with room_types nested and workers_count.
     *
     * @return list<array<string,mixed>>
     */
    public function list(): array
    {
        $roles = $this->repo->findAll();
        return array_map(function (WorkerRole $role): array {
            $data = $role->toArray();
            $data['workers_count'] = $this->repo->countWorkersForRole($role->id);
            return $data;
        }, $roles);
    }

    /**
     * Get a single role by ID, or throw NotFoundException.
     */
    public function get(int $id): WorkerRole
    {
        $role = $this->repo->findById($id);
        if ($role === null) {
            throw new NotFoundException('Worker role not found', ['role_id' => $id]);
        }
        return $role;
    }

    /**
     * Create a new role with optional room_type_ids.
     *
     * @param array{name:string, description?:string|null, room_type_ids?:list<int>} $data
     * @return array<string,mixed>
     */
    public function create(array $data): array
    {
        $name        = trim((string) ($data['name'] ?? ''));
        $description = isset($data['description']) && $data['description'] !== ''
            ? trim((string) $data['description']) : null;
        $roomTypeIds = $data['room_type_ids'] ?? [];

        $this->validateName($name);

        $role = new WorkerRole(0, $name, $description, $roomTypeIds, '', '');
        $roleId = $this->repo->insert($role);

        if (!empty($roomTypeIds)) {
            $this->repo->assignRoomTypes($roleId, $roomTypeIds);
        }

        $created = $this->get($roleId);
        $result = $created->toArray();
        $result['workers_count'] = 0;
        return $result;
    }

    /**
     * Update a role (name, description) and optionally sync room_types.
     *
     * @param array{name?:string, description?:string|null, room_type_ids?:list<int>} $data
     * @return array<string,mixed>
     */
    public function update(int $id, array $data): array
    {
        $this->get($id); // throws 404 if not found

        $fields = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) ($data['name'] ?? ''));
            $this->validateName($name);
            $fields['name'] = $name;
        }

        if (array_key_exists('description', $data)) {
            $fields['description'] = $data['description'] !== '' && $data['description'] !== null
                ? trim((string) $data['description'])
                : null;
        }

        if (!empty($fields)) {
            $this->repo->update($id, $fields);
        }

        // Sync room_types only if explicitly provided
        if (array_key_exists('room_type_ids', $data) && is_array($data['room_type_ids'])) {
            /** @var list<int> $roomTypeIds */
            $roomTypeIds = array_map('intval', $data['room_type_ids']);
            $this->repo->assignRoomTypes($id, $roomTypeIds);
        }

        $updated = $this->get($id);
        $result = $updated->toArray();
        $result['workers_count'] = $this->repo->countWorkersForRole($id);
        return $result;
    }

    /**
     * Delete a role. Refused if workers are assigned.
     *
     * @return array{id:int, deleted:bool}
     */
    public function delete(int $id): array
    {
        $this->get($id); // throws 404 if not found

        $count = $this->workerRepo->countByRole($id);
        if ($count > 0) {
            throw new ConflictException(
                'role_in_use',
                "Cannot delete: {$count} worker(s) assigned to this role",
                ['role_id' => $id, 'workers_count' => $count]
            );
        }

        $this->repo->delete($id);
        return ['id' => $id, 'deleted' => true];
    }

    // -- private helpers -------------------------------------------------------

    private function validateName(string $name): void
    {
        if ($name === '') {
            throw new UnprocessableException('validation_error', 'name is required');
        }
        if (mb_strlen($name) > 50) {
            throw new UnprocessableException('validation_error', 'name must be <= 50 chars');
        }
    }
}
