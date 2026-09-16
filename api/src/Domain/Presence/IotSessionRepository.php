<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use PDO;
use RuntimeException;

/**
 * IotSessionRepository: PDO persistence for iot_sessions.
 *
 * Fase 41: the room state has a single authoritative writer. State writes
 * happen inside a transaction opened here (beginTransaction/commit/rollBack),
 * taking a row lock with lockByRoomId() and issuing column-scoped updates with
 * updateState(). markExitEvaluated() gives the exit rule real idempotency
 * across the webhook and N exit-scan instances.
 */
final class IotSessionRepository implements IotSessionRepositoryInterface
{
    /** Columns selected for hydration (state + F41 ordering marks). */
    private const STATE_COLUMNS =
        'id, room_id, stay_id, door_state, presence_state,
         last_open_at, last_close_at, last_absent_since, exit_evaluated_at, updated_at,
         last_door_event_at, last_presence_event_at, last_door_value, last_presence_value';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findByRoomId(int $roomId): ?IotSession
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::STATE_COLUMNS . ' FROM iot_sessions WHERE room_id = :rid LIMIT 1'
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function upsert(IotSession $session): void
    {
        $this->pdo->prepare(
            'INSERT INTO iot_sessions
                (room_id, stay_id, door_state, presence_state,
                 last_open_at, last_close_at, last_absent_since, exit_evaluated_at,
                 last_door_event_at, last_presence_event_at, last_door_value, last_presence_value)
             VALUES
                (:room, :stay, :door, :pres, :open, :close, :abs, :exit,
                 :ldea, :lpea, :ldv, :lpv)
             ON DUPLICATE KEY UPDATE
                stay_id                = VALUES(stay_id),
                door_state             = VALUES(door_state),
                presence_state         = VALUES(presence_state),
                last_open_at           = VALUES(last_open_at),
                last_close_at          = VALUES(last_close_at),
                last_absent_since      = VALUES(last_absent_since),
                exit_evaluated_at      = VALUES(exit_evaluated_at),
                last_door_event_at     = VALUES(last_door_event_at),
                last_presence_event_at = VALUES(last_presence_event_at),
                last_door_value        = VALUES(last_door_value),
                last_presence_value    = VALUES(last_presence_value)'
        )->execute([
            ':room'  => $session->roomId,
            ':stay'  => $session->stayId,
            ':door'  => $session->doorState,
            ':pres'  => $session->presenceState,
            ':open'  => $session->lastOpenAt,
            ':close' => $session->lastCloseAt,
            ':abs'   => $session->lastAbsentSince,
            ':exit'  => $session->exitEvaluatedAt,
            ':ldea'  => $session->lastDoorEventAt,
            ':lpea'  => $session->lastPresenceEventAt,
            ':ldv'   => $session->lastDoorValue,
            ':lpv'   => $session->lastPresenceValue,
        ]);
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function lockByRoomId(int $roomId): IotSession
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('lockByRoomId() requires an active transaction');
        }

        // Materialise the row (no-op on conflict) so the FOR UPDATE below locks
        // a real row even in READ COMMITTED.
        $this->pdo->prepare(
            "INSERT INTO iot_sessions (room_id, door_state, presence_state, updated_at)
             VALUES (:rid, 'UNKNOWN', 'UNKNOWN', UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE id = id"
        )->execute([':rid' => $roomId]);

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::STATE_COLUMNS . ' FROM iot_sessions WHERE room_id = :rid LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('iot_sessions row could not be locked for room ' . $roomId);
        }
        return $this->hydrate($row);
    }

    public function updateState(IotSession $session): void
    {
        $this->pdo->prepare(
            'UPDATE iot_sessions SET
                stay_id                = :stay,
                door_state             = :door,
                presence_state         = :pres,
                last_open_at           = :open,
                last_close_at          = :close,
                last_absent_since      = :abs,
                exit_evaluated_at      = :exit,
                last_door_event_at     = :ldea,
                last_presence_event_at = :lpea,
                last_door_value        = :ldv,
                last_presence_value    = :lpv,
                updated_at             = UTC_TIMESTAMP(3)
             WHERE id = :id'
        )->execute([
            ':id'    => $session->id,
            ':stay'  => $session->stayId,
            ':door'  => $session->doorState,
            ':pres'  => $session->presenceState,
            ':open'  => $session->lastOpenAt,
            ':close' => $session->lastCloseAt,
            ':abs'   => $session->lastAbsentSince,
            ':exit'  => $session->exitEvaluatedAt,
            ':ldea'  => $session->lastDoorEventAt,
            ':lpea'  => $session->lastPresenceEventAt,
            ':ldv'   => $session->lastDoorValue,
            ':lpv'   => $session->lastPresenceValue,
        ]);
    }

    public function markExitEvaluated(int $roomId, string $closeAtUtc): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE iot_sessions
                SET exit_evaluated_at = :now, updated_at = UTC_TIMESTAMP(3)
              WHERE room_id = :rid
                AND (exit_evaluated_at IS NULL OR exit_evaluated_at < :close)'
        );
        $stmt->execute([
            ':now'   => $closeAtUtc,
            ':rid'   => $roomId,
            ':close' => $closeAtUtc,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): IotSession
    {
        return new IotSession(
            (int)    $row['id'],
            (int)    $row['room_id'],
            $row['stay_id'] === null ? null : (int) $row['stay_id'],
            (string) $row['door_state'],
            (string) $row['presence_state'],
            $row['last_open_at']       === null ? null : (string) $row['last_open_at'],
            $row['last_close_at']      === null ? null : (string) $row['last_close_at'],
            $row['last_absent_since']  === null ? null : (string) $row['last_absent_since'],
            $row['exit_evaluated_at']  === null ? null : (string) $row['exit_evaluated_at'],
            (string) $row['updated_at'],
            $row['last_door_event_at']     === null ? null : (string) $row['last_door_event_at'],
            $row['last_presence_event_at'] === null ? null : (string) $row['last_presence_event_at'],
            $row['last_door_value']        === null ? null : (string) $row['last_door_value'],
            $row['last_presence_value']    === null ? null : (string) $row['last_presence_value']
        );
    }
}
