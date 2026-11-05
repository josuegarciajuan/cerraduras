<?php
declare(strict_types=1);

namespace App\Domain\Timbre;

use App\Domain\Devices\SwitchService;
use App\Domain\Presence\RoomAutomationPauseRepository;
use App\Domain\Warehouse\WarehouseRecordingDecision;
use App\Support\Clock;
use PDO;

/**
 * TimbreRecordingService — motor de movimientos y grabación del timbrado (F116).
 *
 * Una fila `timbre_movements` por apertura/cruce. La grabación usa la cámara
 * INTERIOR del pack y `camera_recordings.timbre_movement_id`.
 *
 * Reglas:
 *  - SCAN empieza a grabar; si la puerta no abre en X, termina como NO_DOOR.
 *  - DOOR_OPEN crea un movimiento si no había escaneo pendiente.
 *  - PRESENT con la puerta abierta y sin movimiento crea un cruce (CROSSING).
 *  - ENTRY termina 3 s después de que desaparezca la presencia.
 *  - EXIT termina 3 s después de cerrar la puerta.
 *  - Topes: 2 min general y 1 min con la puerta abierta.
 *
 * Todo es local: BD + push. No consulta Tuya.
 */
final class TimbreRecordingService
{
    private PDO $pdo;
    private ?SwitchService $switchService;
    private string $typeCode;
    private ?RoomAutomationPauseRepository $pause;

    public function __construct(
        PDO $pdo,
        ?SwitchService $switchService = null,
        string $typeCode = 'TIMBRADO',
        ?RoomAutomationPauseRepository $pause = null
    ) {
        $this->pdo = $pdo;
        $this->switchService = $switchService;
        $this->typeCode = $typeCode;
        $this->pause = $pause;
    }

    public function isTimbradoRoom(int $roomId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
             WHERE r.id = :rid AND rt.code = :code LIMIT 1'
        );
        $stmt->execute([':rid' => $roomId, ':code' => $this->typeCode]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Punto de entrada único para el coordinador.
     *
     * Eventos: QR_OK/SCAN, DOOR_OPEN, DOOR_CLOSE, DOOR_CLOSE_ABSENT,
     * PRESENT, ABSENT, SCAN_TIMEOUT, STOP_DEADLINE, MAX_DEADLINE.
     *
     * @param array<string,mixed> $meta
     */
    public function onSignal(int $roomId, string $event, array $meta = []): void
    {
        if (!$this->isTimbradoRoom($roomId)) {
            return;
        }
        $provider = strtoupper((string) ($meta['provider'] ?? ''));
        $source   = strtolower((string) ($meta['source'] ?? ''));
        if ($provider === 'SIMULATED' || in_array($source, ['resync', 'sim'], true)) {
            return;
        }
        if ($this->pause !== null && $this->pause->isPaused($roomId)) {
            return;
        }

        $event = strtoupper($event);
        if ($event === 'SCAN') {
            $event = 'QR_OK';
        }
        if ($event === 'STOP_DEADLINE' || $event === 'MAX_DEADLINE' || $event === 'SCAN_TIMEOUT') {
            $this->handleDeadline($roomId, $event);
            return;
        }

        $config = $this->roomConfig($roomId);
        $now = Clock::nowUtc();
        $nowSql = $now->format('Y-m-d H:i:s.v');
        $lightAction = null;

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT IGNORE INTO timbre_state (room_id) VALUES (:r)')
                ->execute([':r' => $roomId]);
            $state = $this->lockState($roomId);
            $iot = $this->iotState($roomId);
            $doorOpen = ((string) ($iot['door_state'] ?? '')) === 'OPEN';
            $presence = ((string) ($iot['presence_state'] ?? '')) === 'PRESENT';
            $movement = $state['current_movement_id'] !== null
                ? $this->findMovement((int) $state['current_movement_id'])
                : null;

            if ($event === 'QR_OK') {
                [$before, $beforeAt] = $this->presenceBefore($iot, $config, $now);
                if ($state['state'] === 'IDLE') {
                    $direction = $doorOpen
                        ? TimbreMovementRules::directionFromPresence($before)
                        : TimbreMovementRules::DIRECTION_UNKNOWN;
                    $movementId = $this->createMovement($roomId, [
                        'trigger' => TimbreMovementRules::TRIGGER_SCAN,
                        'credential_type' => $this->credentialType($meta),
                        'key_id' => $meta['key_id'] ?? null,
                        'worker_id' => $meta['worker_id'] ?? null,
                        'worker_session_id' => $meta['worker_session_id'] ?? null,
                        'direction' => $direction,
                        'presence_before' => $before ? 1 : 0,
                        'presence_before_at' => $beforeAt,
                        'scan_at' => $nowSql,
                        'door_opened_at' => $doorOpen ? $nowSql : null,
                        'meta_json' => $this->encodeMeta($meta),
                    ]);
                    $this->startRecording($roomId, $movementId, $direction, 'QR');
                    if ($doorOpen) {
                        $this->setRecordingState($roomId, $movementId, $now, $config);
                        if ($direction === TimbreMovementRules::DIRECTION_ENTRY) {
                            $lightAction = $this->lightOn($roomId);
                        }
                    } else {
                        $this->updateState($roomId, [
                            'state' => 'SCAN_WAIT_DOOR',
                            'current_movement_id' => $movementId,
                            'scan_deadline' => $now->modify('+' . $config['scan_timeout'] . ' seconds')->format('Y-m-d H:i:s.v'),
                        ]);
                    }
                }
            } elseif ($event === WarehouseRecordingDecision::EV_DOOR_OPEN) {
                [$before, $beforeAt] = $this->presenceBefore($iot, $config, $now);
                if ($state['state'] === 'SCAN_WAIT_DOOR' && $movement !== null) {
                    $direction = TimbreMovementRules::directionFromPresence($before);
                    $this->updateMovement((int) $movement['id'], [
                        'direction' => $direction,
                        'presence_before' => $before ? 1 : 0,
                        'presence_before_at' => $beforeAt,
                        'door_opened_at' => $nowSql,
                        'status' => TimbreMovementRules::STATUS_RECORDING,
                    ]);
                    $this->setRecordingState($roomId, (int) $movement['id'], $now, $config, false);
                    if ($direction === TimbreMovementRules::DIRECTION_ENTRY) {
                        $lightAction = $this->lightOn($roomId);
                    }
                } elseif ($state['state'] === 'IDLE') {
                    $direction = TimbreMovementRules::directionFromPresence($before);
                    $movementId = $this->createMovement($roomId, [
                        'trigger' => TimbreMovementRules::TRIGGER_DOOR,
                        'credential_type' => null,
                        'direction' => $direction,
                        'presence_before' => $before ? 1 : 0,
                        'presence_before_at' => $beforeAt,
                        'door_opened_at' => $nowSql,
                        'meta_json' => $this->encodeMeta($meta),
                    ]);
                    $this->startRecording($roomId, $movementId, $direction, 'DOOR');
                    $this->setRecordingState($roomId, $movementId, $now, $config);
                    if ($direction === TimbreMovementRules::DIRECTION_ENTRY) {
                        $lightAction = $this->lightOn($roomId);
                    }
                } elseif ($state['state'] === 'STOP_GRACE' && $movement !== null) {
                    // La puerta vuelve a abrir durante la gracia: seguir grabando.
                    $this->updateState($roomId, [
                        'state' => 'RECORDING',
                        'stop_deadline' => null,
                    ]);
                }
            } elseif ($event === WarehouseRecordingDecision::EV_PRESENT) {
                if ($state['state'] === 'IDLE') {
                    if ($doorOpen) {
                        $movementId = $this->createMovement($roomId, [
                            'trigger' => TimbreMovementRules::TRIGGER_RADAR,
                            'direction' => TimbreMovementRules::DIRECTION_CROSSING,
                            'presence_before' => 0,
                            'presence_before_at' => null,
                            'presence_seen' => 1,
                            'door_opened_at' => $nowSql,
                            'meta_json' => $this->encodeMeta($meta),
                        ]);
                        $this->startRecording($roomId, $movementId, TimbreMovementRules::DIRECTION_CROSSING, 'PRESENCE');
                        $this->setRecordingState($roomId, $movementId, $now, $config);
                        $lightAction = $this->lightOn($roomId);
                    }
                } elseif ($movement !== null) {
                    $this->updateMovement((int) $movement['id'], ['presence_seen' => 1]);
                    if (in_array($state['state'], ['RECORDING', 'STOP_GRACE'], true)) {
                        $this->updateState($roomId, [
                            'state' => 'RECORDING',
                            'stop_deadline' => null,
                        ]);
                        $direction = (string) ($movement['direction'] ?? '');
                        if ($direction !== TimbreMovementRules::DIRECTION_EXIT) {
                            $lightAction = $this->lightOn($roomId);
                        }
                    }
                }
            } elseif ($event === WarehouseRecordingDecision::EV_ABSENT) {
                if ($movement !== null
                    && in_array($state['state'], ['RECORDING', 'STOP_GRACE'], true)
                    && TimbreMovementRules::stopsOnPresenceEnd((string) $movement['direction'])
                ) {
                    $this->updateState($roomId, [
                        'state' => 'STOP_GRACE',
                        'stop_deadline' => $now->modify('+' . $config['presence_grace'] . ' seconds')->format('Y-m-d H:i:s.v'),
                    ]);
                }
                $lightAction = $this->scheduleLightOff($roomId, $now, $config);
            } elseif ($event === WarehouseRecordingDecision::EV_DOOR_CLOSE
                || $event === WarehouseRecordingDecision::EV_DOOR_CLOSE_ABSENT
            ) {
                // La puerta ya no está abierta: el tope de 1 min deja de aplicar.
                $this->updateState($roomId, ['door_open_deadline' => null]);
                if ($movement !== null) {
                    $this->updateMovement((int) $movement['id'], [
                        'door_closed_at' => $nowSql,
                        'door_open_seconds' => $this->secondsBetween((string) ($movement['door_opened_at'] ?? ''), $nowSql),
                    ]);
                    $direction = (string) ($movement['direction'] ?? '');
                    if ($state['state'] === 'RECORDING' || $state['state'] === 'STOP_GRACE') {
                        if (TimbreMovementRules::stopsOnDoorClose($direction)) {
                            $this->updateState($roomId, [
                                'state' => 'STOP_GRACE',
                                'stop_deadline' => $now->modify('+' . $config['close_grace'] . ' seconds')->format('Y-m-d H:i:s.v'),
                            ]);
                        } elseif (TimbreMovementRules::stopsOnPresenceEnd($direction)
                            && ((string) ($iot['presence_state'] ?? '')) !== 'PRESENT'
                        ) {
                            $this->updateState($roomId, [
                                'state' => 'STOP_GRACE',
                                'stop_deadline' => $now->modify('+' . $config['presence_grace'] . ' seconds')->format('Y-m-d H:i:s.v'),
                            ]);
                        }
                    }
                } else {
                    $this->markLastDoorClosed($roomId, $nowSql);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->applyLightAction($roomId, $lightAction);
    }

    /**
     * Procesa vencimientos de escaneo, parada, topes y luz.
     * @return int señales procesadas
     */
    public function tickDeadlines(): int
    {
        $fired = 0;
        $rows = $this->pdo->query(
            "SELECT room_id, scan_deadline, stop_deadline, max_deadline, door_open_deadline
               FROM timbre_state
              WHERE (scan_deadline IS NOT NULL AND scan_deadline <= UTC_TIMESTAMP(3))
                 OR (stop_deadline IS NOT NULL AND stop_deadline <= UTC_TIMESTAMP(3))
                 OR (max_deadline IS NOT NULL AND max_deadline <= UTC_TIMESTAMP(3))
                 OR (door_open_deadline IS NOT NULL AND door_open_deadline <= UTC_TIMESTAMP(3))"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $roomId = (int) $row['room_id'];
            if ($row['scan_deadline'] !== null) {
                $this->onSignal($roomId, 'SCAN_TIMEOUT');
                $fired++;
                continue;
            }
            if ($row['stop_deadline'] !== null) {
                $this->onSignal($roomId, 'STOP_DEADLINE');
                $fired++;
                continue;
            }
            if ($row['max_deadline'] !== null || $row['door_open_deadline'] !== null) {
                $this->onSignal($roomId, 'MAX_DEADLINE');
                $fired++;
            }
        }

        $fired += $this->tickLights();
        return $fired;
    }

    // ---------------------------------------------------------------------

    /** @param array<string,mixed> $row */
    private function updateState(int $roomId, array $row): void
    {
        $allowed = ['state', 'current_movement_id', 'scan_deadline', 'stop_deadline',
            'max_deadline', 'door_open_deadline', 'door_open_since', 'light_off_at', 'light_on'];
        $sets = [];
        $params = [':r' => $roomId];
        foreach ($row as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            $sets[] = "`{$k}` = :{$k}";
            $params[':' . $k] = $v;
        }
        if ($sets === []) {
            return;
        }
        $this->pdo->prepare('UPDATE timbre_state SET ' . implode(', ', $sets) . ' WHERE room_id = :r')
            ->execute($params);
    }

    /** @return array<string,mixed> */
    private function lockState(int $roomId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM timbre_state WHERE room_id = :r FOR UPDATE');
        $stmt->execute([':r' => $roomId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'room_id' => $roomId,
            'state' => 'IDLE',
            'current_movement_id' => null,
            'scan_deadline' => null,
            'stop_deadline' => null,
            'max_deadline' => null,
            'door_open_deadline' => null,
            'door_open_since' => null,
            'light_off_at' => null,
            'light_on' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function iotState(int $roomId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT door_state, presence_state, last_open_at, last_close_at,
                    last_door_event_at, last_presence_event_at
               FROM iot_sessions WHERE room_id = :r LIMIT 1'
        );
        $stmt->execute([':r' => $roomId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{0:bool,1:?string} */
    private function presenceBefore(array $iot, array $config, \DateTimeImmutable $now): array
    {
        if ((string) ($iot['presence_state'] ?? '') !== 'PRESENT') {
            return [false, null];
        }
        $at = $iot['last_presence_event_at'] ?? null;
        if ($at === null || $at === '') {
            return [false, null];
        }
        $ts = strtotime((string) $at . ' UTC');
        if ($ts === false || ($now->getTimestamp() - $ts) > $config['pre_open']) {
            return [false, null];
        }
        return [true, (string) $at];
    }

    /** @param array<string,mixed> $data */
    private function createMovement(int $roomId, array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO timbre_movements
                (room_id, status, `trigger`, credential_type, key_id, worker_id, worker_session_id,
                 direction, presence_before, presence_before_at, presence_seen,
                 scan_at, door_opened_at, ended_at, meta_json)
             VALUES
                (:room, :status, :trg, :cred, :kid, :wid, :wsid,
                 :dir, :pb, :pbat, :ps,
                 :scan, :doorop, NULL, :meta)'
        );
        $stmt->execute([
            ':room' => $roomId,
            ':status' => TimbreMovementRules::STATUS_RECORDING,
            ':trg' => $data['trigger'],
            ':cred' => $data['credential_type'] ?? null,
            ':kid' => $data['key_id'] ?? null,
            ':wid' => $data['worker_id'] ?? null,
            ':wsid' => $data['worker_session_id'] ?? null,
            ':dir' => $data['direction'] ?? TimbreMovementRules::DIRECTION_UNKNOWN,
            ':pb' => !empty($data['presence_before']) ? 1 : 0,
            ':pbat' => $data['presence_before_at'] ?? null,
            ':ps' => !empty($data['presence_seen']) ? 1 : 0,
            ':scan' => $data['scan_at'] ?? null,
            ':doorop' => $data['door_opened_at'] ?? null,
            ':meta' => $data['meta_json'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $fields */
    private function updateMovement(int $id, array $fields): void
    {
        $allowed = ['status', 'direction', 'presence_before', 'presence_before_at', 'presence_seen',
            'scan_at', 'door_opened_at', 'door_closed_at', 'ended_at', 'door_left_open',
            'door_open_seconds', 'meta_json'];
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            $sets[] = "`{$k}` = :{$k}";
            $params[':' . $k] = $v;
        }
        if ($sets === []) {
            return;
        }
        $this->pdo->prepare('UPDATE timbre_movements SET ' . implode(', ', $sets) . ' WHERE id = :id')
            ->execute($params);
    }

    /** @return array<string,mixed>|null */
    private function findMovement(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM timbre_movements WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function setRecordingState(int $roomId, int $movementId, \DateTimeImmutable $now, array $config, bool $setDoorOpen = false): void
    {
        $doorOpenDeadline = null;
        if ($setDoorOpen) {
            $doorOpenDeadline = $now->modify('+' . $config['door_open_max'] . ' seconds')->format('Y-m-d H:i:s.v');
        } else {
            $movement = $this->findMovement($movementId);
            $opened = $movement['door_opened_at'] ?? null;
            if ($opened !== null && $opened !== '') {
                $doorOpenDeadline = (new \DateTimeImmutable((string) $opened, new \DateTimeZone('UTC')))
                    ->modify('+' . $config['door_open_max'] . ' seconds')
                    ->format('Y-m-d H:i:s.v');
            }
        }
        $this->updateState($roomId, [
            'state' => 'RECORDING',
            'current_movement_id' => $movementId,
            'scan_deadline' => null,
            'stop_deadline' => null,
            'max_deadline' => $now->modify('+' . $config['max_recording'] . ' seconds')->format('Y-m-d H:i:s.v'),
            'door_open_deadline' => $doorOpenDeadline,
        ]);
    }

    private function startRecording(int $roomId, int $movementId, string $direction, string $trigger): void
    {
        $existing = $this->pdo->prepare(
            "SELECT COUNT(*) FROM camera_recordings
              WHERE timbre_movement_id = :m AND status IN ('PENDING','RECORDING')"
        );
        $existing->execute([':m' => $movementId]);
        if ((int) $existing->fetchColumn() > 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "SELECT d.id FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
              WHERE r.id = :rid AND d.kind = 'CAMERA' AND d.subtype = 'INTERIOR'
              ORDER BY d.id ASC LIMIT 1"
        );
        $stmt->execute([':rid' => $roomId]);
        $deviceId = (int) ($stmt->fetchColumn() ?: 0);
        if ($deviceId <= 0) {
            return;
        }

        $episode = $direction === TimbreMovementRules::DIRECTION_EXIT
            ? 'EXIT'
            : ($direction === TimbreMovementRules::DIRECTION_ENTRY ? 'ENTRY' : 'PRESENCE');

        $this->pdo->prepare(
            'INSERT INTO camera_recordings
                (visit_id, timbre_movement_id, room_id, device_id, position, episode, `trigger`, status, requested_at)
             VALUES (NULL, :movement, :room, :device, \'INTERIOR\', :episode, :trg, \'PENDING\', UTC_TIMESTAMP(3))'
        )->execute([
            ':movement' => $movementId,
            ':room' => $roomId,
            ':device' => $deviceId,
            ':episode' => $episode,
            ':trg' => $trigger,
        ]);
    }

    private function stopRecording(int $movementId): void
    {
        $this->pdo->prepare(
            "UPDATE camera_recordings SET stop_requested = 1
              WHERE timbre_movement_id = :m AND status IN ('PENDING','RECORDING') AND stop_requested = 0"
        )->execute([':m' => $movementId]);
    }

    private function handleDeadline(int $roomId, string $event): void
    {
        $config = $this->roomConfig($roomId);
        $now = Clock::nowUtc();
        $nowSql = $now->format('Y-m-d H:i:s.v');

        $this->pdo->beginTransaction();
        try {
            $state = $this->lockState($roomId);
            $movement = $state['current_movement_id'] !== null
                ? $this->findMovement((int) $state['current_movement_id'])
                : null;
            if ($movement === null) {
                $this->updateState($roomId, [
                    'state' => 'IDLE',
                    'current_movement_id' => null,
                    'scan_deadline' => null,
                    'stop_deadline' => null,
                    'max_deadline' => null,
                    'door_open_deadline' => null,
                ]);
                $this->pdo->commit();
                return;
            }

            $movementId = (int) $movement['id'];
            $iot = $this->iotState($roomId);
            $doorOpen = ((string) ($iot['door_state'] ?? '')) === 'OPEN';

            if ($event === 'SCAN_TIMEOUT' && $state['state'] === 'SCAN_WAIT_DOOR') {
                $this->stopRecording($movementId);
                $this->finishMovement($movementId, TimbreMovementRules::STATUS_NO_DOOR, $nowSql, false, false);
            } elseif ($event === 'STOP_DEADLINE') {
                $this->stopRecording($movementId);
                $status = TimbreMovementRules::stopStatus(
                    (string) $movement['direction'],
                    $doorOpen,
                    (bool) $movement['presence_seen'],
                    false
                );
                $this->finishMovement($movementId, $status, $nowSql, $doorOpen, false);
            } else {
                $this->stopRecording($movementId);
                $status = TimbreMovementRules::stopStatus(
                    (string) $movement['direction'],
                    $doorOpen,
                    (bool) $movement['presence_seen'],
                    true
                );
                $this->finishMovement($movementId, $status, $nowSql, $doorOpen, true);
            }

            $this->updateState($roomId, [
                'state' => 'IDLE',
                'current_movement_id' => null,
                'scan_deadline' => null,
                'stop_deadline' => null,
                'max_deadline' => null,
                'door_open_deadline' => null,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function finishMovement(int $id, string $status, string $nowSql, bool $doorOpen, bool $maxReached): void
    {
        $movement = $this->findMovement($id);
        if ($movement === null) {
            return;
        }
        $openedAt = (string) ($movement['door_opened_at'] ?? '');
        $closedAt = (string) ($movement['door_closed_at'] ?? '');
        $doorOpenSeconds = null;
        if ($openedAt !== '' && $closedAt !== '') {
            $doorOpenSeconds = $this->secondsBetween($openedAt, $closedAt);
        } elseif ($openedAt !== '') {
            $doorOpenSeconds = $this->secondsBetween($openedAt, $nowSql);
        }
        $this->updateMovement($id, [
            'status' => $status,
            'ended_at' => $nowSql,
            'door_left_open' => $doorOpen ? 1 : 0,
            'door_open_seconds' => $doorOpenSeconds,
        ]);
    }

    private function markLastDoorClosed(int $roomId, string $nowSql): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, door_opened_at FROM timbre_movements
              WHERE room_id = :r AND door_left_open = 1 AND door_closed_at IS NULL
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':r' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return;
        }
        $this->updateMovement((int) $row['id'], [
            'door_closed_at' => $nowSql,
            'door_open_seconds' => $this->secondsBetween((string) $row['door_opened_at'], $nowSql),
        ]);
    }

    private function scheduleLightOff(int $roomId, \DateTimeImmutable $now, array $config): ?string
    {
        $state = $this->lockState($roomId);
        if ((int) ($state['light_on'] ?? 0) !== 1) {
            return null;
        }
        $this->updateState($roomId, [
            'light_off_at' => $now->modify('+' . $config['light_off_delay'] . ' seconds')->format('Y-m-d H:i:s.v'),
        ]);
        return null;
    }

    private function lightOn(int $roomId): ?string
    {
        $state = $this->lockState($roomId);
        if ((int) ($state['light_on'] ?? 0) === 1) {
            return null;
        }
        $this->updateState($roomId, ['light_on' => 1, 'light_off_at' => null]);
        return 'ON';
    }

    private function applyLightAction(int $roomId, ?string $action): void
    {
        if ($this->switchService === null || $action === null) {
            return;
        }
        try {
            if ($action === 'ON') {
                $this->switchService->turnOn($roomId);
            } elseif ($action === 'OFF') {
                $this->switchService->turnOff($roomId);
            }
        } catch (\Throwable $e) {
            error_log('[TimbreRecordingService] light ' . $action . ' failed: ' . $e->getMessage());
        }
    }

    private function tickLights(): int
    {
        if ($this->switchService === null) {
            return 0;
        }
        $rows = $this->pdo->query(
            'SELECT s.room_id, i.presence_state
               FROM timbre_state s
               LEFT JOIN iot_sessions i ON i.room_id = s.room_id
              WHERE s.light_off_at IS NOT NULL AND s.light_off_at <= UTC_TIMESTAMP(3)'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $n = 0;
        foreach ($rows as $row) {
            $roomId = (int) $row['room_id'];
            if ((string) ($row['presence_state'] ?? '') === 'PRESENT') {
                $this->pdo->prepare('UPDATE timbre_state SET light_off_at = NULL WHERE room_id = :r')
                    ->execute([':r' => $roomId]);
                continue;
            }
            try {
                $this->switchService->turnOff($roomId);
            } catch (\Throwable $e) {
                error_log('[TimbreRecordingService] light OFF failed: ' . $e->getMessage());
            }
            $this->pdo->prepare('UPDATE timbre_state SET light_off_at = NULL, light_on = 0 WHERE room_id = :r')
                ->execute([':r' => $roomId]);
            $n++;
        }
        return $n;
    }

    /** @return array<string,int> */
    private function roomConfig(int $roomId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rt.timbre_pre_open_presence_seconds AS pre_open,
                    rt.timbre_scan_open_timeout_seconds AS scan_timeout,
                    rt.timbre_presence_end_grace_seconds AS presence_grace,
                    rt.timbre_door_close_grace_seconds AS close_grace,
                    rt.timbre_max_recording_seconds AS max_recording,
                    rt.timbre_door_open_max_seconds AS door_open_max,
                    rt.timbre_light_off_delay_seconds AS light_off_delay
               FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
              WHERE r.id = :rid LIMIT 1'
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'pre_open' => max(0, (int) ($row['pre_open'] ?? 10)),
            'scan_timeout' => max(1, (int) ($row['scan_timeout'] ?? 20)),
            'presence_grace' => max(0, (int) ($row['presence_grace'] ?? 3)),
            'close_grace' => max(0, (int) ($row['close_grace'] ?? 3)),
            'max_recording' => max(1, (int) ($row['max_recording'] ?? 120)),
            'door_open_max' => max(1, (int) ($row['door_open_max'] ?? 60)),
            'light_off_delay' => max(0, (int) ($row['light_off_delay'] ?? 3)),
        ];
    }

    private function secondsBetween(string $from, string $to): ?int
    {
        if ($from === '' || $to === '') {
            return null;
        }
        $a = strtotime($from . ' UTC');
        $b = strtotime($to . ' UTC');
        if ($a === false || $b === false) {
            return null;
        }
        return max(0, $b - $a);
    }

    private function credentialType(array $meta): ?string
    {
        $value = strtoupper(trim((string) ($meta['credential_type'] ?? '')));
        return in_array($value, ['QR', 'NFC'], true) ? $value : null;
    }

    private function encodeMeta(array $meta): ?string
    {
        $data = [
            'provider' => $meta['provider'] ?? null,
            'source' => $meta['source'] ?? null,
            'label' => $meta['label'] ?? null,
        ];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }
}
