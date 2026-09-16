<?php
declare(strict_types=1);

namespace App\Domain\Presence;

interface IotSessionRepositoryInterface
{
    public function findByRoomId(int $roomId): ?IotSession;

    /**
     * Insert or update the session row for the given room.
     * Uses INSERT … ON DUPLICATE KEY UPDATE internally.
     *
     * @deprecated F41: state writes go through lockByRoomId() + updateState()
     *             inside a transaction. Kept for internal compatibility.
     */
    public function upsert(IotSession $session): void;

    // ---------------------------------------------------------------------
    // F41 (RF-43): single-writer primitives. Transaction control lives on
    // this repository so callers never touch PDO directly (deviation from
    // design.md §1.2, which showed pdo->beginTransaction(); the write gateway
    // owning the transaction keeps the lock/update atomic and testable).
    // ---------------------------------------------------------------------

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    /**
     * SELECT ... FOR UPDATE inside an already-open transaction. Materialises
     * the row with a no-op INSERT first so a missing row still gets a real
     * lock (design.md §1.2).
     */
    public function lockByRoomId(int $roomId): IotSession;

    /** UPDATE by id of the state columns only (never rewrites other columns). */
    public function updateState(IotSession $session): void;

    /**
     * Conditional update of exit_evaluated_at; returns false when another
     * worker already marked this door cycle (design.md §3.3).
     */
    public function markExitEvaluated(int $roomId, string $closeAtUtc): bool;
}
