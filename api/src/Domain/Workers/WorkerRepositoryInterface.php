<?php
declare(strict_types=1);

namespace App\Domain\Workers;

/**
 * WorkerRepositoryInterface: persistence contract for workers.
 *
 * See RF-W1, TSK-W03.
 */
interface WorkerRepositoryInterface
{
    public function findById(int $id): ?Worker;

    /**
     * Find a worker by their QR token JTI (unique identifier inside the JWT).
     * Used during QR validation to look up the worker.
     */
    public function findByJti(string $jti): ?Worker;

    /**
     * List workers with optional filters.
     *
     * @param array{role_id?:int, active?:bool} $filters
     * @return list<Worker>
     */
    public function findAll(array $filters = []): array;

    /**
     * Insert a new worker. Returns the new ID.
     */
    public function insert(Worker $worker): int;

    /**
     * Partial update of worker fields (name, role_id, notes, qr_token_hash, qr_jti).
     *
     * @param array<string,mixed> $fields
     * @return int rowCount
     */
    public function update(int $id, array $fields): int;

    /**
     * Soft-delete (active=false, qr_token_hash='').
     * Returns rowCount.
     */
    public function softDelete(int $id): int;

    /**
     * Count workers assigned to a given role.
     */
    public function countByRole(int $roleId): int;
}
