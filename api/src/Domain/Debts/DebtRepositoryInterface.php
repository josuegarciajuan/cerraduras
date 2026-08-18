<?php
declare(strict_types=1);

namespace App\Domain\Debts;

interface DebtRepositoryInterface
{
    /**
     * Create a new debt in PENDING_SYNC status.
     */
    public function insert(int $stayId, int $roomId, int $excesoMinutos): int;

    public function findById(int $id): ?Debt;

    /**
     * Return the first active (non-SYNCED, non-FAILED) debt for a stay, if any.
     * Used for idempotency: we don't create a second debt if one already exists.
     */
    public function findActiveForStay(int $stayId): ?Debt;

    /**
     * @param array<string,mixed> $filters  Supported: status, stay_id, from, to
     * @return list<Debt>
     */
    public function listFiltered(array $filters, int $limit = 50, int $offset = 0): array;

    /**
     * Partial update for outbox worker and resync endpoint.
     *
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): int;

    /**
     * Find all OCCUPIED stays whose predicted end time + grace has passed.
     * Used by the overstay-scan job.
     *
     * @return list<array{stay_id:int, room_id:int, room_type_id:int, first_entry_at:string, duracion_minutos:int}>
     */
    public function findOverdueOccupied(): array;
}
