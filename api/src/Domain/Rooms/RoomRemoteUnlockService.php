<?php
declare(strict_types=1);

namespace App\Domain\Rooms;

use App\Domain\Locks\LockService;
use App\Domain\Warehouse\WarehouseDoorOpenService;
use App\Support\Errors\NotFoundException;
use PDO;

/**
 * RoomRemoteUnlockService — F126/RF-363.
 *
 * Unifies "open the lock" for every room:
 *   - ALMACEN_BEBIDAS / TIMBRADO: remote ESP32 command queue
 *     (WarehouseDoorOpenService; the sensor is the physical confirmation).
 *   - Any other room: LockService, respecting the anti-reentry cooldown.
 *
 * The caller is responsible for authorising `override_cooldown`; the
 * dashboard facade never passes it as true.
 */
final class RoomRemoteUnlockService
{
    private PDO $pdo;
    private LockService $locks;
    private WarehouseDoorOpenService $warehouseDoor;

    public function __construct(
        PDO $pdo,
        LockService $locks,
        WarehouseDoorOpenService $warehouseDoor
    ) {
        $this->pdo = $pdo;
        $this->locks = $locks;
        $this->warehouseDoor = $warehouseDoor;
    }

    /**
     * @return array<string,mixed>
     *
     * @throws NotFoundException
     */
    public function open(
        int $roomId,
        string $reason,
        bool $overrideCooldown,
        string $correlationId,
        string $actor = 'panel'
    ): array {
        if ($this->isRemoteDoorRoom($roomId)) {
            $result = $this->warehouseDoor->request($roomId, $correlationId, $actor);
            return array_merge(
                ['room_id' => $roomId, 'mode' => 'remote', 'action' => 'unlock'],
                $result
            );
        }

        $result = $this->locks->open($roomId, $reason, $overrideCooldown, $correlationId);
        return [
            'room_id'  => $roomId,
            'mode'     => 'lock',
            'action'   => 'unlock',
            'result'   => $result['result'],
            'provider' => $result['provider'],
            'queued'   => false,
        ];
    }

    private function isRemoteDoorRoom(int $roomId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
             WHERE r.id = :rid AND rt.code IN ('ALMACEN_BEBIDAS','TIMBRADO') LIMIT 1"
        );
        $stmt->execute([':rid' => $roomId]);
        return (bool) $stmt->fetchColumn();
    }
}
