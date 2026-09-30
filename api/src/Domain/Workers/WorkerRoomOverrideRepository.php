<?php
declare(strict_types=1);

namespace App\Domain\Workers;

use PDO;

/**
 * WorkerRoomOverrideRepository: PDO persistence for `worker_room_overrides`
 * (F62/RF-69.2).
 */
final class WorkerRoomOverrideRepository implements WorkerRoomOverrideRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getEffect(int $workerId, int $roomTypeId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT effect FROM worker_room_overrides
             WHERE worker_id = :w AND room_type_id = :rt LIMIT 1'
        );
        $stmt->execute([':w' => $workerId, ':rt' => $roomTypeId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function setEffect(int $workerId, int $roomTypeId, string $effect): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO worker_room_overrides (worker_id, room_type_id, effect)
             VALUES (:w, :rt, :e)
             ON DUPLICATE KEY UPDATE effect = VALUES(effect)'
        );
        $stmt->execute([':w' => $workerId, ':rt' => $roomTypeId, ':e' => $effect]);
    }

    public function deleteEffect(int $workerId, int $roomTypeId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM worker_room_overrides WHERE worker_id = :w AND room_type_id = :rt'
        );
        $stmt->execute([':w' => $workerId, ':rt' => $roomTypeId]);
    }

    /** @return array<int,string> */
    public function listForWorker(int $workerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT room_type_id, effect FROM worker_room_overrides WHERE worker_id = :w'
        );
        $stmt->execute([':w' => $workerId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['room_type_id']] = (string) $row['effect'];
        }
        return $out;
    }
}
