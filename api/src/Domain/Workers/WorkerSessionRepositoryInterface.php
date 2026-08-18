<?php
declare(strict_types=1);

namespace App\Domain\Workers;

/**
 * WorkerSessionRepositoryInterface: persistence contract for worker sessions.
 *
 * See RF-W5, TSK-W03.
 */
interface WorkerSessionRepositoryInterface
{
    public function findById(int $id): ?WorkerSession;

    /**
     * Find all active (exited_at IS NULL) worker sessions for a room.
     *
     * @return list<WorkerSession>
     */
    public function findActiveForRoom(int $roomId): array;

    /**
     * Find all active sessions for a specific worker.
     *
     * @return list<WorkerSession>
     */
    public function findActiveForWorker(int $workerId): array;

    /**
     * Find sessions for a worker with optional date/room filters.
     *
     * @param array{from?:string, to?:string, room_id?:int, limit?:int} $filters
     * @return list<WorkerSession>
     */
    public function findForWorker(int $workerId, array $filters = []): array;

    /**
     * Find sessions for a room with optional date filters.
     *
     * @param array{from?:string, to?:string} $filters
     * @return list<WorkerSession>
     */
    public function findForRoom(int $roomId, array $filters = []): array;

    /**
     * Insert a new session. Returns the new ID.
     */
    public function insert(WorkerSession $session): int;

    /**
     * Close a session (SET exited_at + exit_kind). Returns rowCount.
     */
    public function close(int $id, string $exitKind, string $exitedAt): int;

    /**
     * Count active sessions for a room.
     */
    public function countActiveForRoom(int $roomId): int;

    /**
     * Get all workers currently inside any room (active sessions with worker+room info).
     *
     * @return list<array{worker_session:WorkerSession, worker:Worker, room_id:int, room_code:string}>
     */
    public function findWorkersInside(): array;
}
