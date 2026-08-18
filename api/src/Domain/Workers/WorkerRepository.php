<?php
declare(strict_types=1);

namespace App\Domain\Workers;

use PDO;

/**
 * WorkerRepository: PDO persistence for workers.
 *
 * Pattern matches StayRepository: private hydrate(), private baseSelect(),
 * insert() returns lastInsertId, update() returns rowCount.
 */
final class WorkerRepository implements WorkerRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findById(int $id): ?Worker
    {
        $stmt = $this->pdo->prepare($this->baseSelect() . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByJti(string $jti): ?Worker
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . ' WHERE qr_jti = :jti LIMIT 1'
        );
        $stmt->execute([':jti' => $jti]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array{role_id?:int, active?:bool} $filters
     * @return list<Worker>
     */
    public function findAll(array $filters = []): array
    {
        $wheres = [];
        $params = [];

        if (isset($filters['role_id']) && $filters['role_id'] !== '') {
            $wheres[]        = 'role_id = :rid';
            $params[':rid']  = (int) $filters['role_id'];
        }
        if (isset($filters['active'])) {
            $wheres[]        = 'active = :act';
            $params[':act']  = $filters['active'] ? 1 : 0;
        }

        $sql = $this->baseSelect();
        if (!empty($wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }
        $sql .= ' ORDER BY name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function insert(Worker $worker): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO workers
                (name, role_id, qr_token_hash, qr_jti, active, notes)
             VALUES
                (:name, :role_id, :qr_hash, :qr_jti, :active, :notes)'
        );
        $stmt->execute([
            ':name'    => $worker->name,
            ':role_id' => $worker->roleId,
            ':qr_hash' => $worker->qrTokenHash,
            ':qr_jti'  => $worker->qrJti,
            ':active'  => $worker->active ? 1 : 0,
            ':notes'   => $worker->notes,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Partial update of worker fields.
     *
     * Allowed columns: name, role_id, notes, qr_token_hash, qr_jti, active.
     *
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): int
    {
        if (empty($fields)) {
            return 0;
        }

        $allowed = [
            'name'          => ':name',
            'role_id'       => ':role_id',
            'notes'         => ':notes',
            'qr_token_hash' => ':qr_hash',
            'qr_jti'        => ':qr_jti',
            'active'        => ':active',
        ];

        $sets   = [];
        $params = [':id' => $id];

        foreach ($fields as $col => $value) {
            if (!isset($allowed[$col])) {
                continue;
            }
            $sets[] = "`{$col}` = {$allowed[$col]}";
            $params[$allowed[$col]] = $col === 'active' ? ($value ? 1 : 0) : $value;
        }

        if (empty($sets)) {
            return 0;
        }

        $sql = 'UPDATE workers SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function softDelete(int $id): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE workers SET active = 0, qr_token_hash = '' WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount();
    }

    public function countByRole(int $roleId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM workers WHERE role_id = :rid AND active = 1'
        );
        $stmt->execute([':rid' => $roleId]);
        return (int) $stmt->fetchColumn();
    }

    // -- private helpers -------------------------------------------------------

    private function baseSelect(): string
    {
        return 'SELECT id, name, role_id, qr_token_hash, qr_jti, active, notes,
                       created_at, updated_at
                FROM workers';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): Worker
    {
        return new Worker(
            (int) $row['id'],
            (string) $row['name'],
            (int) $row['role_id'],
            (string) $row['qr_token_hash'],
            (string) $row['qr_jti'],
            (bool) $row['active'],
            self::nullableStr($row['notes']),
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
