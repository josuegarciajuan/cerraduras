<?php
declare(strict_types=1);

namespace App\Domain\Rooms;

/**
 * RoomCycleResetter: implementación PDO de la limpieza de ciclo (F59/RF-66).
 *
 * - `rooms.cooldown_until = NULL` (anti-reentrada del ciclo anterior).
 * - `iot_sessions` → `door_state`/`presence_state = UNKNOWN` y todas las marcas
 *   del ciclo anterior a NULL (upsert idempotente, mismo SQL que el reset del
 *   panel).
 *
 * NO toca `rooms.status`, estancias, credenciales ni deudas: cada flujo
 * conserva sus propias guardas y pasos.
 */
final class RoomCycleResetter implements RoomCycleResetterInterface
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function reset(int $roomId): void
    {
        $this->pdo->prepare('UPDATE rooms SET cooldown_until = NULL WHERE id = :rid')
            ->execute([':rid' => $roomId]);

        $this->pdo->prepare(
            "INSERT INTO iot_sessions (room_id, door_state, presence_state, updated_at)
             VALUES (:rid, 'UNKNOWN', 'UNKNOWN', UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE door_state = 'UNKNOWN', presence_state = 'UNKNOWN',
                     last_open_at = NULL, last_close_at = NULL, last_absent_since = NULL,
                     exit_evaluated_at = NULL, last_door_event_at = NULL, last_presence_event_at = NULL,
                     last_door_value = NULL, last_presence_value = NULL, updated_at = UTC_TIMESTAMP(3)"
        )->execute([':rid' => $roomId]);
    }
}
