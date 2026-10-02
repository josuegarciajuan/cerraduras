<?php
declare(strict_types=1);

namespace App\Domain\Warehouse;

use App\Support\Clock;
use PDO;

/**
 * WarehouseRecordingService: persists the decisions of
 * WarehouseRecordingDecision for a room whose type is the warehouse
 * (F63/RF-70/71).
 *
 * It is the single writer for `warehouse_state`, `warehouse_visits` and
 * `camera_recordings`. The recorder daemon (F64) only reads camera_recordings
 * and manages the ffmpeg processes.
 *
 * The service never touches rooms that are not the warehouse type, so guest
 * flows are unaffected.
 */
final class WarehouseRecordingService implements WarehouseRecordingServiceInterface
{
    private PDO $pdo;
    private string $warehouseTypeCode;

    public function __construct(PDO $pdo, string $warehouseTypeCode = 'ALMACEN_BEBIDAS')
    {
        $this->pdo = $pdo;
        $this->warehouseTypeCode = $warehouseTypeCode;
    }

    public function isWarehouseRoom(int $roomId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
             WHERE r.id = :rid AND rt.code = :code LIMIT 1'
        );
        $stmt->execute([':rid' => $roomId, ':code' => $this->warehouseTypeCode]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * F76 (RF-92): ancla de "dentro" para la credibilidad de presencia. Solo
     * cuentan las visitas ENTRADA con trigger de puerta o QR; una visita creada
     * únicamente por presencia no ancla (evita que un falso positivo del 24G se
     * auto-justifique).
     */
    public function activeEnteredVisitAt(int $roomId): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT v.entered_at
             FROM warehouse_state ws
             JOIN warehouse_visits v ON v.id = ws.current_visit_id
             WHERE ws.room_id = :r
               AND v.outcome = 'ENTERED'
               AND v.entry_trigger IN ('DOOR','QR')
               AND v.entered_at IS NOT NULL
             LIMIT 1"
        );
        $stmt->execute([':r' => $roomId]);
        $v = $stmt->fetchColumn();
        return ($v !== false && $v !== null && $v !== '') ? (string) $v : null;
    }

    public function onSignal(int $roomId, string $event, array $meta = []): void
    {
        if (!$this->isWarehouseRoom($roomId)) {
            return;
        }
        $config = $this->roomConfig($roomId);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT IGNORE INTO warehouse_state (room_id) VALUES (:r)')
                ->execute([':r' => $roomId]);

            $stmt = $this->pdo->prepare(
                'SELECT state, current_visit_id, entry_trigger, deadline_x, deadline_m, presence_confirmed
                 FROM warehouse_state WHERE room_id = :r FOR UPDATE'
            );
            $stmt->execute([':r' => $roomId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
                'state' => WarehouseRecordingDecision::STATE_IDLE,
                'current_visit_id' => null,
                'entry_trigger' => null,
                'deadline_x' => null,
                'deadline_m' => null,
                'presence_confirmed' => 0,
            ];

            $preState = (string) $row['state'];
            $state = [
                'state' => $preState,
                'entry_trigger' => $row['entry_trigger'] !== null ? (string) $row['entry_trigger'] : null,
                'presence_confirmed' => (bool) $row['presence_confirmed'],
            ];
            $result = WarehouseRecordingDecision::decide($state, $event);

            $visitId = $row['current_visit_id'] !== null ? (int) $row['current_visit_id'] : null;
            $deadlineX = $row['deadline_x'] !== null ? (string) $row['deadline_x'] : null;
            $deadlineM = $row['deadline_m'] !== null ? (string) $row['deadline_m'] : null;
            $now = Clock::nowUtc();

            foreach ($result['actions'] as $action) {
                switch ($action) {
                    case WarehouseRecordingDecision::A_CREATE_VISIT:
                        $visitId = $this->createVisit($roomId, $result['entry_trigger'], $meta, $now);
                        break;
                    case WarehouseRecordingDecision::A_CONFIRM_ENTRY:
                        if ($visitId !== null) {
                            $this->pdo->prepare(
                                "UPDATE warehouse_visits
                                 SET outcome='ENTERED', entered_at=COALESCE(entered_at, :n)
                                 WHERE id=:id"
                            )->execute([':n' => $now->format('Y-m-d H:i:s.v'), ':id' => $visitId]);
                        }
                        break;
                    case WarehouseRecordingDecision::A_MARK_EXIT:
                        if ($visitId !== null) {
                            $this->pdo->prepare('UPDATE warehouse_visits SET exited_at=:n WHERE id=:id')
                                ->execute([':n' => $now->format('Y-m-d H:i:s.v'), ':id' => $visitId]);
                        }
                        break;
                    case WarehouseRecordingDecision::A_VISIT_NO_SHOW:
                        if ($visitId !== null) {
                            $this->pdo->prepare("UPDATE warehouse_visits SET outcome='NO_SHOW' WHERE id=:id")
                                ->execute([':id' => $visitId]);
                        }
                        break;
                    case WarehouseRecordingDecision::A_START_EXT:
                        $this->startRecordings($roomId, $visitId, 'EXTERIOR', $this->episodeFor($preState), $result['entry_trigger'], $now);
                        break;
                    case WarehouseRecordingDecision::A_START_INT:
                        $this->startRecordings($roomId, $visitId, 'INTERIOR', $this->episodeFor($preState), $result['entry_trigger'], $now);
                        break;
                    case WarehouseRecordingDecision::A_STOP_EXT:
                        $this->requestStop($roomId, $visitId, 'EXTERIOR');
                        break;
                    case WarehouseRecordingDecision::A_STOP_INT:
                        $this->requestStop($roomId, $visitId, 'INTERIOR');
                        break;
                    case WarehouseRecordingDecision::A_DISCARD_EXT:
                        $this->requestDiscard($roomId, $visitId, 'EXTERIOR');
                        break;
                    case WarehouseRecordingDecision::A_DISCARD_INT:
                        $this->requestDiscard($roomId, $visitId, 'INTERIOR');
                        break;
                    case WarehouseRecordingDecision::A_SET_DEADLINE_X:
                        $deadlineX = $now->modify('+' . $config['x'] . ' seconds')->format('Y-m-d H:i:s.v');
                        break;
                    case WarehouseRecordingDecision::A_SET_DEADLINE_M:
                        $deadlineM = $now->modify('+' . $config['m'] . ' seconds')->format('Y-m-d H:i:s.v');
                        break;
                    case WarehouseRecordingDecision::A_CLEAR_DEADLINES:
                        $deadlineX = null;
                        $deadlineM = null;
                        break;
                }
            }

            // Once the cycle returns to IDLE, drop the visit link.
            if ($result['state'] === WarehouseRecordingDecision::STATE_IDLE) {
                $visitId = null;
            }

            $this->pdo->prepare(
                'UPDATE warehouse_state
                 SET state=:st, current_visit_id=:vid, entry_trigger=:tr,
                     deadline_x=:dx, deadline_m=:dm, presence_confirmed=:pc
                 WHERE room_id=:r'
            )->execute([
                ':st' => $result['state'],
                ':vid' => $visitId,
                ':tr' => $result['entry_trigger'],
                ':dx' => $deadlineX,
                ':dm' => $deadlineM,
                ':pc' => $result['presence_confirmed'] ? 1 : 0,
                ':r' => $roomId,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function tickDeadlines(): int
    {
        $fired = 0;

        $stmt = $this->pdo->query(
            "SELECT room_id FROM warehouse_state
             WHERE state='QR_PENDING' AND deadline_x IS NOT NULL AND deadline_x <= UTC_TIMESTAMP(3)"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rid) {
            $this->onSignal((int) $rid, WarehouseRecordingDecision::EV_X_EXPIRED);
            $fired++;
        }

        $stmt = $this->pdo->query(
            "SELECT room_id FROM warehouse_state
             WHERE state IN ('EXTERIOR_ONLY','EXIT_PENDING') AND deadline_m IS NOT NULL AND deadline_m <= UTC_TIMESTAMP(3)"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rid) {
            $this->onSignal((int) $rid, WarehouseRecordingDecision::EV_M_EXPIRED);
            $fired++;
        }

        return $fired;
    }

    /**
     * F73/RF-85: acota la duración de las grabaciones en curso.
     *
     * Marca `stop_requested=1` en toda grabación `RECORDING` cuya `started_at`
     * supere `$maxSeconds`. El recorder finaliza esas filas como `SAVED` en el
     * mismo tick (renombrado + póster + duration_s). Con `$maxSeconds <= 0` es
     * un no-op (producción = sin límite).
     *
     * @return int número de grabaciones marcadas para parar
     */
    public function enforceRecordingCap(int $maxSeconds): int
    {
        if ($maxSeconds <= 0) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE camera_recordings SET stop_requested=1
             WHERE status='RECORDING' AND stop_requested=0
               AND started_at IS NOT NULL
               AND started_at <= (UTC_TIMESTAMP(3) - INTERVAL :s SECOND)"
        );
        $stmt->execute([':s' => $maxSeconds]);
        return $stmt->rowCount();
    }

    // -- helpers ---------------------------------------------------------------

    /** @return array{x:int,m:int} */
    private function roomConfig(int $roomId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rt.warehouse_confirm_seconds AS x, rt.warehouse_exterior_margin_seconds AS m
             FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id WHERE r.id = :rid LIMIT 1'
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['x' => 40, 'm' => 5];
        return ['x' => (int) $row['x'], 'm' => (int) $row['m']];
    }

    /** @param array<string,mixed> $meta */
    private function createVisit(int $roomId, ?string $trigger, array $meta, \DateTimeImmutable $now): int
    {
        $trigger = $trigger ?? 'PRESENCE';
        $stmt = $this->pdo->prepare(
            'INSERT INTO warehouse_visits
                (room_id, worker_id, worker_session_id, entry_trigger, outcome, qr_at)
             VALUES (:r, :w, :ws, :tr, :out, :qr)'
        );
        $stmt->execute([
            ':r' => $roomId,
            ':w' => isset($meta['worker_id']) ? (int) $meta['worker_id'] : null,
            ':ws' => isset($meta['worker_session_id']) ? (int) $meta['worker_session_id'] : null,
            ':tr' => $trigger,
            ':out' => 'NO_SHOW',
            ':qr' => $trigger === 'QR' ? $now->format('Y-m-d H:i:s.v') : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function episodeFor(string $preState): string
    {
        return in_array($preState, [
            WarehouseRecordingDecision::STATE_RECORDING_INSIDE,
            WarehouseRecordingDecision::STATE_EXIT_PENDING,
        ], true) ? 'EXIT' : 'ENTRY';
    }

    private function startRecordings(int $roomId, ?int $visitId, string $position, string $episode, ?string $trigger, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT d.id FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
             WHERE r.id = :rid AND d.kind = 'CAMERA' AND d.subtype = :pos"
        );
        $stmt->execute([':rid' => $roomId, ':pos' => $position]);
        $deviceIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (empty($deviceIds)) {
            return;
        }

        $ins = $this->pdo->prepare(
            // `trigger` es palabra reservada en MariaDB → entrecomillar.
            'INSERT INTO camera_recordings
                (visit_id, room_id, device_id, position, episode, `trigger`, status, requested_at)
             VALUES (:vid, :r, :dev, :pos, :ep, :tr, :st, :req)'
        );
        foreach ($deviceIds as $deviceId) {
            $ins->execute([
                ':vid' => $visitId,
                ':r' => $roomId,
                ':dev' => (int) $deviceId,
                ':pos' => $position,
                ':ep' => $episode,
                ':tr' => $trigger ?? 'PRESENCE',
                ':st' => 'PENDING',
                ':req' => $now->format('Y-m-d H:i:s.v'),
            ]);
        }
    }

    private function requestStop(int $roomId, ?int $visitId, string $position): void
    {
        $sql = "UPDATE camera_recordings SET stop_requested=1
                WHERE room_id=:r AND position=:pos AND status IN ('PENDING','RECORDING') AND stop_requested=0";
        $params = [':r' => $roomId, ':pos' => $position];
        if ($visitId !== null) {
            $sql .= ' AND visit_id=:vid';
            $params[':vid'] = $visitId;
        }
        $this->pdo->prepare($sql)->execute($params);
    }

    private function requestDiscard(int $roomId, ?int $visitId, string $position): void
    {
        $sql = "UPDATE camera_recordings SET discard_requested=1, stop_requested=1
                WHERE room_id=:r AND position=:pos AND status IN ('PENDING','RECORDING')";
        $params = [':r' => $roomId, ':pos' => $position];
        if ($visitId !== null) {
            $sql .= ' AND visit_id=:vid';
            $params[':vid'] = $visitId;
        }
        $this->pdo->prepare($sql)->execute($params);
    }
}
