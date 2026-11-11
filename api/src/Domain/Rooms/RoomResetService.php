<?php
declare(strict_types=1);

namespace App\Domain\Rooms;

use App\Domain\Devices\SwitchService;
use App\Support\Errors\NotFoundException;
use PDO;

/**
 * RoomResetService — F124/RF-348.
 *
 * Shared hard-reset used by the dashboard reset button, the panel room list
 * and the canonical v1 API. It is intentionally identical to the historical
 * `/dashboard-api/rooms/reset` behaviour:
 *
 *   1. revoke every active QR of the room,
 *   2. close active stays,
 *   3. return the room to FREE,
 *   4. clear the IoT cycle and anti-reentry cooldown,
 *   5. remove debts and their pending outbox messages,
 *   6. best-effort turn the light off.
 *
 * It never creates a new QR and never touches worker history.
 */
final class RoomResetService
{
    private PDO $pdo;
    private RoomCycleResetterInterface $cycleResetter;
    private ?SwitchService $switchService;

    public function __construct(
        PDO $pdo,
        ?RoomCycleResetterInterface $cycleResetter = null,
        ?SwitchService $switchService = null
    ) {
        $this->pdo = $pdo;
        $this->cycleResetter = $cycleResetter ?? new RoomCycleResetter($pdo);
        $this->switchService = $switchService;
    }

    /**
     * @return array{
     *   ok:bool,room_id:int,status:string,
     *   summary:array{qr_revoked:int,stays_closed:int,debts_deleted:int,iot_cycle_reset:bool,light_off:bool}
     * }
     *
     * @throws NotFoundException
     */
    public function reset(int $roomId): array
    {
        $room = $this->pdo->prepare('SELECT id FROM rooms WHERE id = :rid LIMIT 1');
        $room->execute([':rid' => $roomId]);
        if ($room->fetchColumn() === false) {
            throw new NotFoundException('Room not found', ['room_id' => $roomId]);
        }

        $qrRevoked = $this->count(
            "SELECT COUNT(*) FROM qr_credentials qc
             JOIN stays s ON s.id = qc.stay_id
             WHERE s.room_id = :rid AND qc.revoked_at IS NULL AND qc.consumed_at IS NULL",
            [':rid' => $roomId]
        );
        $this->pdo->prepare(
            "UPDATE qr_credentials qc
             JOIN stays s ON s.id = qc.stay_id
             SET qc.revoked_at = UTC_TIMESTAMP(3), qc.updated_at = UTC_TIMESTAMP(3)
             WHERE s.room_id = :rid AND qc.revoked_at IS NULL AND qc.consumed_at IS NULL"
        )->execute([':rid' => $roomId]);

        $staysClosed = $this->count(
            "SELECT COUNT(*) FROM stays
             WHERE room_id = :rid AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')",
            [':rid' => $roomId]
        );
        $this->pdo->prepare(
            "UPDATE stays SET status = 'CLOSED', closed_at = UTC_TIMESTAMP(3)
             WHERE room_id = :rid AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')"
        )->execute([':rid' => $roomId]);

        $this->pdo->prepare("UPDATE rooms SET status = 'FREE' WHERE id = :rid")
            ->execute([':rid' => $roomId]);

        $this->cycleResetter->reset($roomId);

        $debtIds = $this->pdo->prepare('SELECT id FROM debts WHERE room_id = :rid');
        $debtIds->execute([':rid' => $roomId]);
        $ids = array_map('intval', $debtIds->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($ids !== []) {
            $in = implode(',', $ids);
            try {
                $this->pdo->exec(
                    "DELETE FROM outbox_vb6
                     WHERE topic = 'debt.created'
                       AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.debt_id')) IN ($in)"
                );
            } catch (\Throwable $e) {
                error_log('[RoomResetService] cleanup outbox_vb6: ' . $e->getMessage());
            }
        }
        $debtsDeleted = 0;
        try {
            $stmt = $this->pdo->prepare('DELETE FROM debts WHERE room_id = :rid');
            $stmt->execute([':rid' => $roomId]);
            $debtsDeleted = $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log('[RoomResetService] cleanup debts: ' . $e->getMessage());
        }

        $lightOff = false;
        if ($this->switchService !== null) {
            try {
                $result = $this->switchService->turnOff($roomId);
                $lightOff = ($result['ok'] ?? false) === true;
            } catch (\Throwable $e) {
                error_log('[RoomResetService] turnOff: ' . $e->getMessage());
            }
        }

        return [
            'ok'      => true,
            'room_id' => $roomId,
            'status'  => 'FREE',
            'summary' => [
                'qr_revoked'      => $qrRevoked,
                'stays_closed'    => $staysClosed,
                'debts_deleted'   => $debtsDeleted,
                'iot_cycle_reset' => true,
                'light_off'       => $lightOff,
            ],
        ];
    }

    /** @param array<string,mixed> $params */
    private function count(string $sql, array $params): int
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[RoomResetService] count: ' . $e->getMessage());
            return 0;
        }
    }
}
