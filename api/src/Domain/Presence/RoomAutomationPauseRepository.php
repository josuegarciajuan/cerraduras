<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use PDO;

/**
 * RoomAutomationPauseRepository — F109.
 *
 * Pausa manual e indefinida de las automatizaciones de una sala. No caduca:
 * solo se reanuda cuando el operador pulsa «Reanudar».
 */
final class RoomAutomationPauseRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{paused:bool,run_id:?string,paused_at:?string,resumed_at:?string} */
    public function status(int $roomId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT paused, run_id, paused_at, resumed_at FROM room_automation_pause WHERE room_id = :r LIMIT 1'
        );
        $stmt->execute([':r' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['paused' => false, 'run_id' => null, 'paused_at' => null, 'resumed_at' => null];
        }
        return [
            'paused' => (bool) $row['paused'],
            'run_id' => $row['run_id'] !== null ? (string) $row['run_id'] : null,
            'paused_at' => $row['paused_at'] !== null ? (string) $row['paused_at'] : null,
            'resumed_at' => $row['resumed_at'] !== null ? (string) $row['resumed_at'] : null,
        ];
    }

    public function isPaused(int $roomId): bool
    {
        return $this->status($roomId)['paused'];
    }

    /** @return list<int> */
    public function pausedRoomIds(): array
    {
        $rows = $this->pdo->query('SELECT room_id FROM room_automation_pause WHERE paused = 1')
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_map('intval', $rows);
    }

    public function pause(int $roomId, ?string $runId = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO room_automation_pause (room_id, paused, run_id, paused_at, resumed_at)
             VALUES (:r, 1, :run, UTC_TIMESTAMP(3), NULL)
             ON DUPLICATE KEY UPDATE paused = 1, run_id = VALUES(run_id),
                 paused_at = UTC_TIMESTAMP(3), resumed_at = NULL'
        );
        $stmt->execute([':r' => $roomId, ':run' => $runId]);
    }

    public function resume(int $roomId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO room_automation_pause (room_id, paused, run_id, paused_at, resumed_at)
             VALUES (:r, 0, NULL, NULL, UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE paused = 0, run_id = NULL, resumed_at = UTC_TIMESTAMP(3)'
        );
        $stmt->execute([':r' => $roomId]);
    }
}
