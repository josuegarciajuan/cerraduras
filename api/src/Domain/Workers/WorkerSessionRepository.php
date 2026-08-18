<?php
declare(strict_types=1);

namespace App\Domain\Workers;

use PDO;

/**
 * WorkerSessionRepository: PDO persistence for worker_sessions.
 *
 * Tracks worker entry/exit events per room. Exit is detected by sensors,
 * so exited_at may be null for sessions still in progress.
 */
final class WorkerSessionRepository implements WorkerSessionRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findById(int $id): ?WorkerSession
    {
        $stmt = $this->pdo->prepare($this->baseSelect() . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /** @return list<WorkerSession> */
    public function findActiveForRoom(int $roomId): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() .
            ' WHERE room_id = :rid AND exited_at IS NULL ORDER BY entered_at ASC'
        );
        $stmt->execute([':rid' => $roomId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<WorkerSession> */
    public function findActiveForWorker(int $workerId): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() .
            ' WHERE worker_id = :wid AND exited_at IS NULL ORDER BY entered_at ASC'
        );
        $stmt->execute([':wid' => $workerId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param array{from?:string, to?:string, room_id?:int, limit?:int} $filters
     * @return list<WorkerSession>
     */
    public function findForWorker(int $workerId, array $filters = []): array
    {
        $wheres = ['worker_id = :wid'];
        $params = [':wid' => $workerId];

        if (!empty($filters['from'])) {
            $wheres[] = 'entered_at >= :from';
            $params[':from'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $wheres[] = 'entered_at < :to';
            $params[':to'] = (string) $filters['to'];
        }
        if (!empty($filters['room_id'])) {
            $wheres[] = 'room_id = :rid';
            $params[':rid'] = (int) $filters['room_id'];
        }

        $limit = min((int) ($filters['limit'] ?? 50), 200);

        $sql = $this->baseSelect() . ' WHERE ' . implode(' AND ', $wheres)
             . ' ORDER BY entered_at DESC LIMIT :lim';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param array{from?:string, to?:string} $filters
     * @return list<WorkerSession>
     */
    public function findForRoom(int $roomId, array $filters = []): array
    {
        $wheres = ['room_id = :rid'];
        $params = [':rid' => $roomId];

        if (!empty($filters['from'])) {
            $wheres[] = 'entered_at >= :from';
            $params[':from'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $wheres[] = 'entered_at < :to';
            $params[':to'] = (string) $filters['to'];
        }

        $sql = $this->baseSelect() . ' WHERE ' . implode(' AND ', $wheres)
             . ' ORDER BY entered_at DESC LIMIT 50';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function insert(WorkerSession $session): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO worker_sessions
                (worker_id, room_id, entered_at, correlation_id)
             VALUES
                (:wid, :rid, :entered, :corr)'
        );
        $stmt->execute([
            ':wid'     => $session->workerId,
            ':rid'     => $session->roomId,
            ':entered' => $session->enteredAt,
            ':corr'    => $session->correlationId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function close(int $id, string $exitKind, string $exitedAt): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE worker_sessions
             SET exited_at = :exited, exit_kind = :kind
             WHERE id = :id AND exited_at IS NULL'
        );
        $stmt->execute([
            ':id'     => $id,
            ':kind'   => $exitKind,
            ':exited' => $exitedAt,
        ]);
        return $stmt->rowCount();
    }

    public function countActiveForRoom(int $roomId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM worker_sessions WHERE room_id = :rid AND exited_at IS NULL'
        );
        $stmt->execute([':rid' => $roomId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array{worker_session:WorkerSession, worker:Worker, room_id:int, room_code:string}>
     */
    public function findWorkersInside(): array
    {
        $sql = 'SELECT ws.id, ws.worker_id, ws.room_id, ws.entered_at, ws.exited_at,
                       ws.exit_kind, ws.correlation_id, ws.created_at, ws.updated_at,
                       w.name AS worker_name, w.role_id, w.active, w.notes AS worker_notes,
                       w.created_at AS worker_created_at, w.updated_at AS worker_updated_at,
                       r.code AS room_code
                FROM worker_sessions ws
                JOIN workers w ON w.id = ws.worker_id
                JOIN rooms r ON r.id = ws.room_id
                WHERE ws.exited_at IS NULL
                ORDER BY ws.entered_at ASC';

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $results = [];
        foreach ($rows as $row) {
            $ws = $this->hydrate($row);

            $worker = new Worker(
                (int) $row['worker_id'],
                (string) $row['worker_name'],
                (int) $row['role_id'],
                '', // qr_token_hash not fetched
                '', // qr_jti not fetched
                (bool) $row['active'],
                self::nullableStr($row['worker_notes']),
                (string) $row['worker_created_at'],
                (string) $row['worker_updated_at']
            );

            $results[] = [
                'worker_session' => $ws,
                'worker'         => $worker,
                'room_id'        => (int) $row['room_id'],
                'room_code'      => (string) $row['room_code'],
            ];
        }

        return $results;
    }

    // -- private helpers -------------------------------------------------------

    private function baseSelect(): string
    {
        return 'SELECT id, worker_id, room_id, entered_at, exited_at,
                       exit_kind, correlation_id, created_at, updated_at
                FROM worker_sessions';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): WorkerSession
    {
        return new WorkerSession(
            (int) $row['id'],
            (int) $row['worker_id'],
            (int) $row['room_id'],
            (string) $row['entered_at'],
            self::nullableStr($row['exited_at']),
            self::nullableStr($row['exit_kind']),
            self::nullableStr($row['correlation_id']),
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
