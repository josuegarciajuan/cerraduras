<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AccessKeys\AccessKeyService;
use App\Domain\Locks\LockService;
use App\Domain\Timbre\TimbreMovementRules;
use App\Domain\Warehouse\WarehouseRecordingDecision;
use App\Domain\Warehouse\WarehouseRecordingServiceInterface;
use App\Http\Request;
use App\Http\Response;
use PDO;

/**
 * TimbreController — API del video portero de oficina (F116).
 *
 * Rutas públicas LAN:
 *   GET  /timbre-api/openings?room_id=N&limit=50
 *   POST /timbre-api/door/open
 *   POST /timbre-api/keys
 */
final class TimbreController
{
    public function __construct(
        private PDO $pdo,
        private LockService $lockService,
        private AccessKeyService $accessKeys,
        private WarehouseRecordingServiceInterface $recorder
    ) {
    }

    public function openings(Request $request): Response
    {
        $roomId = $this->resolveRoomId((int) ($request->query['room_id'] ?? 0));
        if ($roomId <= 0) {
            return Response::json(404, ['error' => 'timbre_room_not_found']);
        }
        $limit = (int) ($request->query['limit'] ?? 50);
        $limit = max(1, min(100, $limit));

        $stmt = $this->pdo->prepare(
            "SELECT m.*, k.key_type, k.label AS key_label,
                    w.name AS worker_name, r.name AS role_name
               FROM timbre_movements m
               LEFT JOIN access_keys k ON k.id = m.key_id
               LEFT JOIN workers w ON w.id = COALESCE(m.worker_id, k.worker_id)
               LEFT JOIN worker_roles r ON r.id = k.role_id
              WHERE m.room_id = :room
              ORDER BY m.id DESC
              LIMIT {$limit}"
        );
        $stmt->execute([':room' => $roomId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $recordings = $this->recordingsByMovement($ids);

        $items = [];
        foreach ($rows as $row) {
            $movementId = (int) $row['id'];
            $owner = 'Sin llave';
            if (!empty($row['worker_name'])) {
                $owner = (string) $row['worker_name'];
            } elseif (!empty($row['role_name'])) {
                $owner = (string) $row['role_name'];
            } elseif (!empty($row['key_label'])) {
                $owner = (string) $row['key_label'];
            }

            $items[] = [
                'id' => $movementId,
                'occurred_at' => $row['created_at'],
                'type' => TimbreMovementRules::typeFor(
                    $row['credential_type'] !== null ? (string) $row['credential_type'] : null,
                    (string) $row['trigger']
                ),
                'trigger' => strtolower((string) $row['trigger']),
                'direction' => strtolower((string) $row['direction']),
                'status' => strtolower((string) $row['status']),
                'owner' => $owner,
                'credential_type' => $row['credential_type'],
                'presence_before' => (bool) $row['presence_before'],
                'door_left_open' => (bool) $row['door_left_open'],
                'door_open_seconds' => $row['door_open_seconds'] !== null ? (int) $row['door_open_seconds'] : null,
                'scan_at' => $row['scan_at'],
                'door_opened_at' => $row['door_opened_at'],
                'door_closed_at' => $row['door_closed_at'],
                'ended_at' => $row['ended_at'],
                'recordings' => $recordings[$movementId] ?? [],
            ];
        }

        return Response::json(200, [
            'room_id' => $roomId,
            'openings' => $items,
        ]);
    }

    public function openDoor(Request $request): Response
    {
        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        $roomId = (int) ($body['room_id'] ?? 0);
        if ($roomId <= 0) {
            return Response::json(400, ['error' => 'room_id_required']);
        }
        if (!$this->isTimbradoRoom($roomId)) {
            return Response::json(404, ['error' => 'timbre_room_not_found']);
        }

        $correlationId = (string) ($request->attr('correlation_id', '') ?? '');
        $before = $this->lastMovementId($roomId);
        try {
            $result = $this->lockService->open($roomId, 'manual_timbre', true, $correlationId);
            $this->recorder->onSignal($roomId, WarehouseRecordingDecision::EV_DOOR_OPEN, [
                'provider' => 'PANEL',
                'source' => 'panel',
            ]);
            $after = $this->lastMovementId($roomId);
            return Response::json(200, array_merge(
                ['ok' => true, 'movement_started' => $after !== null && ($before === null || $after > $before)],
                is_array($result) ? $result : []
            ));
        } catch (\Throwable $e) {
            return Response::json(409, ['error' => 'door_error', 'message' => $e->getMessage()]);
        }
    }

    public function createKey(Request $request): Response
    {
        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        $roomId = (int) ($body['room_id'] ?? 0);
        if ($roomId <= 0) {
            return Response::json(400, ['error' => 'room_id_required']);
        }
        $room = $this->roomRow($roomId);
        if ($room === null) {
            return Response::json(404, ['error' => 'timbre_room_not_found']);
        }
        $roomTypeId = (int) $room['room_type_id'];

        $validUntil = trim((string) ($body['valid_until'] ?? ''));
        if ($validUntil === '') {
            return Response::json(422, ['error' => 'validation_error', 'message' => 'valid_until is required']);
        }
        $notes = isset($body['notes']) && trim((string) $body['notes']) !== ''
            ? trim((string) $body['notes']) : null;
        $ownerType = strtoupper(trim((string) ($body['owner_type'] ?? '')));
        $label = isset($body['label']) ? trim((string) $body['label']) : '';

        try {
            if ($ownerType === 'FREE') {
                $ownerName = trim((string) ($body['owner_name'] ?? ''));
                if ($ownerName === '') {
                    return Response::json(422, ['error' => 'validation_error', 'message' => 'owner_name is required']);
                }
                $label = $label !== '' ? $label : $ownerName;
                $created = $this->accessKeys->createNamedQrKey($label, $roomTypeId, $validUntil, $notes);
                return Response::json(201, ['data' => $created]);
            }

            if ($ownerType === 'WORKER') {
                $workerId = (int) ($body['worker_id'] ?? 0);
                $worker = $this->workerRow($workerId);
                if ($worker === null || (int) $worker['active'] !== 1) {
                    return Response::json(404, ['error' => 'worker_not_found']);
                }
                if (!$this->workerCanAccess((int) $worker['id'], (int) $worker['role_id'], $roomTypeId)) {
                    return Response::json(422, ['error' => 'owner_room_denied', 'message' => 'El empleado no tiene acceso a esta sala']);
                }
                $label = $label !== '' ? $label : (string) $worker['name'];
                $created = $this->accessKeys->create([
                    'label' => $label,
                    'owner_type' => 'WORKER',
                    'worker_id' => (int) $worker['id'],
                    'key_type' => 'QR',
                    'valid_until' => $validUntil,
                    'all_room_types' => false,
                    'room_type_ids' => [$roomTypeId],
                    'notes' => $notes,
                ]);
                return Response::json(201, ['data' => $created]);
            }

            if ($ownerType === 'ROLE') {
                $roleId = (int) ($body['role_id'] ?? 0);
                $role = $this->roleRow($roleId);
                if ($role === null) {
                    return Response::json(404, ['error' => 'role_not_found']);
                }
                if (!$this->roleCanAccess($roleId, $roomTypeId)) {
                    return Response::json(422, ['error' => 'owner_room_denied', 'message' => 'El rol no tiene acceso a esta sala']);
                }
                $label = $label !== '' ? $label : (string) $role['name'];
                $created = $this->accessKeys->create([
                    'label' => $label,
                    'owner_type' => 'ROLE',
                    'role_id' => $roleId,
                    'key_type' => 'QR',
                    'valid_until' => $validUntil,
                    'all_room_types' => false,
                    'room_type_ids' => [$roomTypeId],
                    'notes' => $notes,
                ]);
                return Response::json(201, ['data' => $created]);
            }

            return Response::json(422, ['error' => 'validation_error', 'message' => 'owner_type must be WORKER, ROLE or FREE']);
        } catch (\Throwable $e) {
            return Response::json(422, ['error' => 'validation_error', 'message' => $e->getMessage()]);
        }
    }

    // ---------------------------------------------------------------------

    private function resolveRoomId(int $roomId): int
    {
        if ($roomId > 0) {
            return $this->isTimbradoRoom($roomId) ? $roomId : 0;
        }
        $v = $this->pdo->query(
            "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
              WHERE rt.code = 'TIMBRADO' ORDER BY r.id ASC LIMIT 1"
        )->fetchColumn();
        return (int) ($v ?: 0);
    }

    private function isTimbradoRoom(int $roomId): bool
    {
        return $this->roomRow($roomId) !== null;
    }

    /** @return array<string,mixed>|null */
    private function roomRow(int $roomId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.code, r.room_type_id
               FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
              WHERE r.id = :id AND rt.code = 'TIMBRADO' LIMIT 1"
        );
        $stmt->execute([':id' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    private function workerRow(int $workerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, role_id, active FROM workers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $workerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    private function roleRow(int $roleId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name FROM worker_roles WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $roleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function roleCanAccess(int $roleId, int $roomTypeId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM worker_role_room_types WHERE role_id = :r AND room_type_id = :t LIMIT 1'
        );
        $stmt->execute([':r' => $roleId, ':t' => $roomTypeId]);
        return (bool) $stmt->fetchColumn();
    }

    private function workerCanAccess(int $workerId, int $roleId, int $roomTypeId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT effect FROM worker_room_overrides WHERE worker_id = :w AND room_type_id = :t LIMIT 1'
        );
        $stmt->execute([':w' => $workerId, ':t' => $roomTypeId]);
        $effect = strtoupper((string) ($stmt->fetchColumn() ?: ''));
        if ($effect === 'ALLOW') {
            return true;
        }
        if ($effect === 'DENY') {
            return false;
        }
        return $this->roleCanAccess($roleId, $roomTypeId);
    }

    /**
     * @param list<int> $ids
     * @return array<int,list<array<string,mixed>>>
     */
    private function recordingsByMovement(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, timbre_movement_id, status, stop_requested, started_at, stopped_at, duration_s, size_bytes
               FROM camera_recordings
              WHERE timbre_movement_id IN ({$placeholders})
              ORDER BY id ASC"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $mid = (int) $row['timbre_movement_id'];
            $saved = (string) $row['status'] === 'SAVED';
            $out[$mid][] = [
                'id' => (int) $row['id'],
                'status' => (string) $row['status'],
                'stop_requested' => (int) $row['stop_requested'] === 1,
                'started_at' => $row['started_at'],
                'stopped_at' => $row['stopped_at'],
                'duration_s' => $row['duration_s'] !== null ? (int) $row['duration_s'] : null,
                'size_bytes' => $row['size_bytes'] !== null ? (int) $row['size_bytes'] : null,
                'video_url' => $saved ? '/almacen-api/recordings/' . (int) $row['id'] . '/video' : null,
                'poster_url' => $saved ? '/almacen-api/recordings/' . (int) $row['id'] . '/poster' : null,
            ];
        }
        return $out;
    }

    private function lastMovementId(int $roomId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT MAX(id) FROM timbre_movements WHERE room_id = :r');
        $stmt->execute([':r' => $roomId]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null) ? null : (int) $v;
    }
}
