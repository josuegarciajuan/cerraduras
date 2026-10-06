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
     * F80 (RF-103.2): ancla de "dentro" del almacén. Cuenta cualquier visita ENTRADA
     * activa (`DOOR`, `QR` o `PRESENCE`): una visita iniciada por presencia es real y
     * debe anclar. F80 ya **no** usa el ancla para vetar presencia (deroga el veto de
     * F76/RF-92.1); se conserva como contexto informativo.
     */
    public function activeEnteredVisitAt(int $roomId): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT v.entered_at
             FROM warehouse_state ws
             JOIN warehouse_visits v ON v.id = ws.current_visit_id
             WHERE ws.room_id = :r
               AND v.outcome = 'ENTERED'
               AND v.entry_trigger IN ('DOOR','QR','PRESENCE')
               AND v.entered_at IS NOT NULL
             LIMIT 1"
        );
        $stmt->execute([':r' => $roomId]);
        $v = $stmt->fetchColumn();
        return ($v !== false && $v !== null && $v !== '') ? (string) $v : null;
    }

    public function onSignal(int $roomId, string $event, array $meta = []): void
    {
        // F79 (RF-102.1/102.2): el motor solo reacciona a ciclos reales. Las
        // señales simuladas (`/sim/*`, provider=SIMULATED) y los reenvíos del
        // resync REST (`meta.source='resync'`) son reconciliación de estado, no
        // transiciones físicas: no deben crear visitas ni grabaciones. El estado
        // IoT (`iot_sessions`) se sigue actualizando en IotSessionService.
        $provider = strtoupper((string) ($meta['provider'] ?? ''));
        $origin   = strtolower((string) ($meta['source'] ?? ''));
        if ($provider === 'SIMULATED' || $origin === 'resync' || $origin === 'sim') {
            return;
        }

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
            $visitId = $row['current_visit_id'] !== null ? (int) $row['current_visit_id'] : null;
            $now = Clock::nowUtc();

            // F84 (RF-107.2/107.3): al cerrar un episodio iniciado SOLO por
            // presencia, se evalúa la evidencia del propio radar para separar una
            // visita real de un falso positivo. `null` = no aplica (QR/DOOR).
            $presenceEvidenceOk = null;
            if (($event === WarehouseRecordingDecision::EV_ABSENT
                    || $event === WarehouseRecordingDecision::EV_DOOR_CLOSE_ABSENT)
                && $preState === WarehouseRecordingDecision::STATE_RECORDING_INSIDE
                && $state['entry_trigger'] === 'PRESENCE'
                && $visitId !== null
            ) {
                $presenceEvidenceOk = $this->presenceEvidenceConfirmed($roomId, $visitId, $config, $now);
            }

            $result = WarehouseRecordingDecision::decide($state, $event, $presenceEvidenceOk);

            $visitId = $row['current_visit_id'] !== null ? (int) $row['current_visit_id'] : null;
            $deadlineX = $row['deadline_x'] !== null ? (string) $row['deadline_x'] : null;
            $deadlineM = $row['deadline_m'] !== null ? (string) $row['deadline_m'] : null;

            // F85 (RF-109.4): al crear una visita nueva en este lote (p. ej. la
            // re-entrada desde EXIT_PENDING) sus clips son de ENTRADA, no del
            // `preState` anterior (que sería EXIT).
            $createdNewVisit = false;
            foreach ($result['actions'] as $action) {
                switch ($action) {
                    case WarehouseRecordingDecision::A_CREATE_VISIT:
                        $visitId = $this->createVisit($roomId, $result['entry_trigger'], $meta, $now);
                        $createdNewVisit = true;
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
                    case WarehouseRecordingDecision::A_MARK_NOISE:
                        // F84 (RF-107.3): falso positivo del radar. Se conserva la
                        // fila como auditoría (oculta en el listado por defecto) y
                        // se marca la salida para no dejarla "abierta".
                        if ($visitId !== null) {
                            $this->pdo->prepare(
                                "UPDATE warehouse_visits
                                    SET outcome='NOISE', exited_at=COALESCE(exited_at, :n)
                                  WHERE id=:id"
                            )->execute([':n' => $now->format('Y-m-d H:i:s.v'), ':id' => $visitId]);
                        }
                        break;
                    case WarehouseRecordingDecision::A_START_EXT:
                        $this->startRecordings($roomId, $visitId, 'EXTERIOR', $createdNewVisit ? 'ENTRY' : $this->episodeFor($preState), $result['entry_trigger'], $now);
                        break;
                    case WarehouseRecordingDecision::A_START_INT:
                        $this->startRecordings($roomId, $visitId, 'INTERIOR', $createdNewVisit ? 'ENTRY' : $this->episodeFor($preState), $result['entry_trigger'], $now);
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

    /**
     * @return array{x:int,m:int,min_moves:int,min_events:int,static_seconds:int,reentry_seconds:int}
     */
    private function roomConfig(int $roomId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rt.warehouse_confirm_seconds AS x, rt.warehouse_exterior_margin_seconds AS m,
                    rt.warehouse_presence_min_moves AS min_moves,
                    rt.warehouse_presence_min_events AS min_events,
                    rt.warehouse_presence_static_seconds AS static_seconds
             FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id WHERE r.id = :rid LIMIT 1'
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['x' => 40, 'm' => 5];
        $evidence = PresenceEvidence::configFromRow([
            'warehouse_presence_min_moves'      => $row['min_moves'] ?? null,
            'warehouse_presence_min_events'     => $row['min_events'] ?? null,
            'warehouse_presence_static_seconds' => $row['static_seconds'] ?? null,
        ]);
        return [
            'x' => (int) $row['x'],
            'm' => (int) $row['m'],
            'min_moves' => $evidence['min_moves'],
            'min_events' => $evidence['min_events'],
            'static_seconds' => $evidence['static_seconds'],
            // F85/RF-112.3: ventana de contexto de re-entrada (def. 300 s).
            'reentry_seconds' => $this->reentryWindow($roomId),
        ];
    }

    /**
     * F85/RF-112.3: ventana (s) de contexto de re-entrada. Tolerante a que la
     * migración `0123` aún no esté aplicada (default 300).
     */
    private function reentryWindow(int $roomId): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT rt.warehouse_reentry_context_seconds
                   FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
                  WHERE r.id = :rid LIMIT 1'
            );
            $stmt->execute([':rid' => $roomId]);
            $v = $stmt->fetchColumn();
            if ($v !== false && $v !== null) {
                return max(0, (int) $v);
            }
        } catch (\Throwable $e) {
            // Columna no migrada todavía: se usa el valor por defecto.
        }
        return 300;
    }

    /**
     * F84 (RF-107.2): cuenta la evidencia de un episodio iniciado solo por
     * presencia y decide si es una visita real o un falso positivo del radar.
     * Solo BD local (push ya persistido): no consume cuota Tuya.
     *
     * @param array{x:int,m:int,min_moves:int,min_events:int,static_seconds:int,reentry_seconds:int} $config
     */
    private function presenceEvidenceConfirmed(
        int $roomId,
        int $visitId,
        array $config,
        \DateTimeImmutable $now
    ): bool {
        $vStmt = $this->pdo->prepare('SELECT created_at FROM warehouse_visits WHERE id=:id LIMIT 1');
        $vStmt->execute([':id' => $visitId]);
        $createdAt = $vStmt->fetchColumn();
        if ($createdAt === false || $createdAt === null || $createdAt === '') {
            return false;
        }
        // Ventana con 2 s de margen previo para incluir el PRESENT que disparó la
        // visita (su `occurred_at` es ligeramente anterior a `created_at`).
        $startTs = strtotime((string) $createdAt . ' UTC');
        if ($startTs === false) {
            return false;
        }
        $from = gmdate('Y-m-d H:i:s', $startTs - 2) . '.000';
        $to   = $now->format('Y-m-d H:i:s.v');

        $q = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT COALESCE(event_fingerprint, CONCAT('id-', id))) AS total,
                    COUNT(DISTINCT CASE
                        WHEN LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.tuya_raw_val'))) = 'move'
                        THEN COALESCE(event_fingerprint, CONCAT('id-', id)) END) AS moves
               FROM presence_events
              WHERE room_id = :r AND sensor = 'PRESENCE' AND value = 'PRESENT'
                AND provider = 'TUYA' AND occurred_at BETWEEN :a AND :b"
        );
        $q->execute([':r' => $roomId, ':a' => $from, ':b' => $to]);
        $row = $q->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'moves' => 0];
        $events = (int) $row['total'];
        $moves  = (int) $row['moves'];

        $dStmt = $this->pdo->prepare(
            "SELECT 1 FROM presence_events
              WHERE room_id = :r AND sensor = 'PROXIMITY' AND applied = 1
                AND occurred_at BETWEEN :a AND :b LIMIT 1"
        );
        $dStmt->execute([':r' => $roomId, ':a' => $from, ':b' => $to]);
        $doorEvent = $dStmt->fetchColumn() !== false;

        $seconds = max(0, $now->getTimestamp() - $startTs);

        // F86/RF-115.4: la re-entrada solo se confirma con CONTEXTO DE PUERTA
        // (puerta OPEN en el estado IoT). Un fantasma del radar con la puerta
        // cerrada NO se confirma por el mero hecho de haber una visita real
        // reciente. Solo BD local (sin cuota).
        $doorOpen = false;
        try {
            $ds = $this->pdo->prepare('SELECT door_state FROM iot_sessions WHERE room_id = :r LIMIT 1');
            $ds->execute([':r' => $roomId]);
            $doorOpen = ((string) ($ds->fetchColumn() ?: '')) === 'OPEN';
        } catch (\Throwable $e) {
            $doorOpen = false;
        }

        // F85/RF-112.1: contexto de re-entrada. Si hay una visita REAL (DOOR/QR,
        // ENTERED) cerrada en la misma sala dentro de la ventana configurada, este
        // episodio de presencia es una re-entrada legítima aunque el radar solo
        // haya emitido un `move`. Solo BD local (sin cuota).
        $window = (int) ($config['reentry_seconds'] ?? 300);
        if ($doorOpen && $window > 0) {
            $rq = $this->pdo->prepare(
                "SELECT 1 FROM warehouse_visits
                  WHERE room_id = :r AND id <> :id
                    AND entry_trigger IN ('DOOR','QR')
                    AND outcome = 'ENTERED'
                    AND exited_at IS NOT NULL
                    AND exited_at >= (UTC_TIMESTAMP(3) - INTERVAL :s SECOND)
                  LIMIT 1"
            );
            $rq->bindValue(':r', $roomId, PDO::PARAM_INT);
            $rq->bindValue(':id', $visitId, PDO::PARAM_INT);
            $rq->bindValue(':s', $window, PDO::PARAM_INT);
            $rq->execute();
            if ($rq->fetchColumn() !== false) {
                return true;
            }
        }

        return PresenceEvidence::isConfirmed($moves, $events, (float) $seconds, $doorEvent, $config);
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
