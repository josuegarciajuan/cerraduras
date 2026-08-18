<?php
declare(strict_types=1);

namespace App\Domain\Workers;

use PDO;

/**
 * WorkerRoleRepository: PDO persistence for worker_roles + pivot table.
 *
 * Manages worker_roles and worker_role_room_types as a single aggregate.
 */
final class WorkerRoleRepository implements WorkerRoleRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findById(int $id): ?WorkerRole
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, description, created_at, updated_at
             FROM worker_roles WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /** @return list<WorkerRole> */
    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, description, created_at, updated_at
             FROM worker_roles ORDER BY name ASC'
        );
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function insert(WorkerRole $role): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO worker_roles (name, description) VALUES (:name, :desc)'
        );
        $stmt->execute([
            ':name' => $role->name,
            ':desc' => $role->description,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Partial update (name, description only).
     *
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): int
    {
        if (empty($fields)) {
            return 0;
        }

        $allowed = [
            'name'        => ':name',
            'description' => ':desc',
        ];

        $sets   = [];
        $params = [':id' => $id];

        foreach ($fields as $col => $value) {
            if (!isset($allowed[$col])) {
                continue;
            }
            $sets[] = "`{$col}` = {$allowed[$col]}";
            $params[$allowed[$col]] = $value;
        }

        if (empty($sets)) {
            return 0;
        }

        $sql = 'UPDATE worker_roles SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function delete(int $id): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM worker_roles WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount();
    }

    /**
     * Sync room_types for a role: delete all existing rows, then insert the new set.
     *
     * @param list<int> $roomTypeIds
     */
    public function assignRoomTypes(int $roleId, array $roomTypeIds): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'DELETE FROM worker_role_room_types WHERE role_id = :rid'
            )->execute([':rid' => $roleId]);

            if (!empty($roomTypeIds)) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO worker_role_room_types (role_id, room_type_id) VALUES (:rid, :rt)'
                );
                foreach ($roomTypeIds as $rtId) {
                    $stmt->execute([':rid' => $roleId, ':rt' => (int) $rtId]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<int> */
    public function getRoomTypesForRole(int $roleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT room_type_id FROM worker_role_room_types WHERE role_id = :rid ORDER BY room_type_id'
        );
        $stmt->execute([':rid' => $roleId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function countWorkersForRole(int $roleId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM workers WHERE role_id = :rid AND active = 1'
        );
        $stmt->execute([':rid' => $roleId]);
        return (int) $stmt->fetchColumn();
    }

    public function canAccessRoomType(int $roleId, int $roomTypeId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM worker_role_room_types WHERE role_id = :rid AND room_type_id = :rt LIMIT 1'
        );
        $stmt->execute([':rid' => $roleId, ':rt' => $roomTypeId]);
        return (bool) $stmt->fetchColumn();
    }

    // -- private helpers -------------------------------------------------------

    /**
     * Hydrate a WorkerRole from a DB row, including roomTypeIds from pivot.
     *
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): WorkerRole
    {
        $id = (int) $row['id'];
        return new WorkerRole(
            $id,
            (string) $row['name'],
            self::nullableStr($row['description']),
            $this->getRoomTypesForRole($id),
            (string) $row['created_at'],
            (string) $row['updated_at']
        );
    }

    /** @param mixed $v */
    private static function nullableStr($v): ?string
    {
        return $v === null ? null : (string) $v;
    }
}
