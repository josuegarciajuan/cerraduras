<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use PDO;

/**
 * IotSessionRepository: PDO persistence for iot_sessions.
 *
 * One row per room (UNIQUE on room_id). All writes go through upsert() so the
 * caller never needs to distinguish insert vs update.
 */
final class IotSessionRepository implements IotSessionRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findByRoomId(int $roomId): ?IotSession
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, room_id, stay_id, door_state, presence_state,
                    last_open_at, last_close_at, last_absent_since, exit_evaluated_at, updated_at
             FROM iot_sessions WHERE room_id = :rid LIMIT 1'
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
                 last_open_at, last_close_at, last_absent_since, exit_evaluated_at)
             VALUES
                (:room, :stay, :door, :pres, :open, :close, :abs, :exit)
             ON DUPLICATE KEY UPDATE
                stay_id            = VALUES(stay_id),
                door_state         = VALUES(door_state),
                presence_state     = VALUES(presence_state),
                last_open_at       = VALUES(last_open_at),
                last_close_at      = VALUES(last_close_at),
                last_absent_since  = VALUES(last_absent_since),
                exit_evaluated_at  = VALUES(exit_evaluated_at)'
        )->execute([
            ':room'  => $session->roomId,
            ':stay'  => $session->stayId,
            ':door'  => $session->doorState,
            ':pres'  => $session->presenceState,
            ':open'  => $session->lastOpenAt,
            ':close' => $session->lastCloseAt,
            ':abs'   => $session->lastAbsentSince,
            ':exit'  => $session->exitEvaluatedAt,
        ]);
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
            (string) $row['updated_at']
        );
    }
}
