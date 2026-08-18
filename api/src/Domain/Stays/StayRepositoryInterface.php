<?php
declare(strict_types=1);

namespace App\Domain\Stays;

/**
 * StayRepositoryInterface: abstraction over stays persistence. Introduced to
 * let unit tests substitute a deterministic in-memory implementation without
 * a real PDO connection.
 */
interface StayRepositoryInterface
{
    /**
     * @param array<string,mixed> $vb6Refs
     */
    public function insertReserved(int $roomId, int $duracionMinutos, array $vb6Refs): int;

    public function findById(int $id): ?Stay;

    public function findActiveForRoom(int $roomId): ?Stay;

    /**
     * @param array<string,mixed> $filters
     * @return list<Stay>
     */
    public function listFiltered(array $filters, int $limit = 50, int $offset = 0): array;

    /**
     * @param array<string,mixed> $fields
     * @param string|null $expectedStatus If provided, the UPDATE includes
     *        AND status = :expected_status as an optimistic concurrency guard.
     */
    public function update(int $id, array $fields, ?string $expectedStatus = null): int;
}
