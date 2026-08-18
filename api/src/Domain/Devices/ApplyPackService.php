<?php declare(strict_types=1);

namespace App\Domain\Devices;

use App\Domain\Rooms\RoomRepository;
use App\Support\Errors\ApiException;
use PDO;

final class ApplyPackService
{
    private RoomRepository $roomRepo;
    private DeviceRepository $deviceRepo;
    private DevicePackRepository $packRepo;
    private PDO $pdo;

    public function __construct(
        DevicePackRepository $packRepo,
        DeviceRepository $deviceRepo,
        RoomRepository $roomRepo,
        PDO $pdo
    ) {
        $this->packRepo    = $packRepo;
        $this->deviceRepo  = $deviceRepo;
        $this->roomRepo    = $roomRepo;
        $this->pdo         = $pdo;
    }

    /**
     * Assigns or removes a pack on a room.
     *
     * @param int $packId 0 to remove the pack from the room.
     */
    public function apply(int $roomId, int $packId, string $mode = 'replace'): array
    {
        $room = $this->roomRepo->findById($roomId);
        if (!$room) {
            throw new ApiException(404, 'not_found', 'Habitación no encontrada');
        }

        // Run all mutations inside a transaction so the pack assignment is
        // atomic: either the old rooms are cleared AND the new room gets the
        // pack, or nothing changes (Fase 2 — T2.3).
        $this->pdo->beginTransaction();

        try {
            // pack_id = 0 means "remove pack"
            if ($packId === 0) {
                $stmt = $this->pdo->prepare('UPDATE rooms SET pack_id = NULL WHERE id = :rid');
                $stmt->bindValue(':rid', $roomId, PDO::PARAM_INT);
                $stmt->execute();

                // RF-21: reset status to FREE + close stays if RESERVED/OCCUPIED
                $this->roomRepo->resetAfterPackRemoval($roomId);

                $this->pdo->commit();

                return [
                    'room_id'   => $roomId,
                    'pack_id'   => null,
                    'message'   => 'Pack quitado de la habitación',
                ];
            }

            $pack = $this->packRepo->findById($packId);
            if (!$pack) {
                throw new ApiException(404, 'not_found', 'Pack no encontrado');
            }

            // When replacing, clear the pack from any other room that currently holds it
            if ($mode === 'replace') {
                // RF-21: capture old rooms before unassigning, to reset their status
                $oldRooms = $this->pdo->prepare(
                    'SELECT id, status FROM rooms WHERE pack_id = :pid AND id != :rid'
                );
                $oldRooms->execute([':pid' => $packId, ':rid' => $roomId]);
                $oldRoomIds = $oldRooms->fetchAll(PDO::FETCH_ASSOC);

                $stmt = $this->pdo->prepare('UPDATE rooms SET pack_id = NULL WHERE pack_id = :pid AND id != :rid');
                $stmt->bindValue(':pid', $packId, PDO::PARAM_INT);
                $stmt->bindValue(':rid', $roomId, PDO::PARAM_INT);
                $stmt->execute();

                foreach ($oldRoomIds as $old) {
                    $this->roomRepo->resetAfterPackRemoval((int) $old['id']);
                }
            }

            // Set the pack on the target room
            $stmt = $this->pdo->prepare('UPDATE rooms SET pack_id = :pid WHERE id = :rid');
            $stmt->bindValue(':pid', $packId, PDO::PARAM_INT);
            $stmt->bindValue(':rid', $roomId, PDO::PARAM_INT);
            $stmt->execute();

            $this->pdo->commit();

            return [
                'room_id' => $roomId,
                'pack_id' => $packId,
                'pack_name' => $pack->name,
                'room_code' => $room->code,
                'message' => 'Pack asignado a la habitación',
            ];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
