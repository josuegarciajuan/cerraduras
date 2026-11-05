<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Support\Config;
use PDO;

/**
 * TimbreStateController — snapshot vivo de la oficina TIMBRADO (F116).
 *
 * GET /timbre-api/state?room_id=N
 *
 * Solo devuelve la cámara INTERIOR (la que usa el video portero). La EXTERIOR
 * sigue en el pack pero no se expone en este panel.
 */
final class TimbreStateController
{
    public function __construct(private PDO $pdo)
    {
    }

    public function show(Request $request): Response
    {
        $roomId = (int) ($request->query['room_id'] ?? 0);
        return Response::json(200, $this->stateArray($roomId));
    }

    /** @return array<string,mixed> */
    public function stateArray(int $roomId = 0): array
    {
        $room = $this->resolveRoom($roomId);
        if ($room === null) {
            return [
                'room' => null,
                'live' => null,
                'camera' => null,
                'movement' => null,
                'last_movement_id' => null,
                'server_ts' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
        }
        $roomId = (int) $room['id'];

        $iot = $this->pdo->prepare(
            'SELECT door_state, presence_state, last_open_at, last_close_at,
                    last_door_event_at, last_presence_event_at
               FROM iot_sessions WHERE room_id = :r LIMIT 1'
        );
        $iot->execute([':r' => $roomId]);
        $s = $iot->fetch(PDO::FETCH_ASSOC) ?: [];

        $type = $this->pdo->prepare('SELECT presence_stale_seconds FROM room_types WHERE id = :id LIMIT 1');
        $type->execute([':id' => (int) $room['room_type_id']]);
        $staleSeconds = (int) ($type->fetchColumn() ?: 180);

        $now = time();
        $doorAge = $this->ageSeconds($s['last_door_event_at'] ?? null, $now);
        $presenceAge = $this->ageSeconds($s['last_presence_event_at'] ?? null, $now);
        $doorState = (string) ($s['door_state'] ?? 'UNKNOWN');
        $presenceState = (string) ($s['presence_state'] ?? 'UNKNOWN');

        $switch = $this->switchState($roomId);

        return [
            'room' => [
                'id' => $roomId,
                'code' => (string) $room['code'],
                'room_type_id' => (int) $room['room_type_id'],
            ],
            'live' => [
                'door_state' => $doorState,
                'presence_state' => $presenceState,
                'presence_stale' => $presenceState === 'PRESENT'
                    && ($presenceAge === null || $presenceAge > $staleSeconds),
                'door_age_seconds' => $doorAge,
                'presence_age_seconds' => $presenceAge,
                'last_open_at' => $s['last_open_at'] ?? null,
                'last_close_at' => $s['last_close_at'] ?? null,
                'switch_state' => $switch['state'],
                'switch_state_at' => $switch['at'],
            ],
            'camera' => $this->camera($roomId),
            'movement' => $this->activeMovement($roomId),
            'last_movement_id' => $this->lastMovementId($roomId),
            'server_ts' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @return array<string,mixed>|null */
    private function resolveRoom(int $roomId): ?array
    {
        if ($roomId > 0) {
            $stmt = $this->pdo->prepare(
                "SELECT r.id, r.code, r.room_type_id
                   FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
                  WHERE r.id = :id AND rt.code = 'TIMBRADO' LIMIT 1"
            );
            $stmt->execute([':id' => $roomId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        }
        $row = $this->pdo->query(
            "SELECT r.id, r.code, r.room_type_id
               FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
              WHERE rt.code = 'TIMBRADO' ORDER BY r.id ASC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array{state:?string,at:?string} */
    private function switchState(int $roomId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT d.meta_json FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
              WHERE r.id = :rid AND d.kind = 'SWITCH' ORDER BY d.id ASC LIMIT 1"
        );
        $stmt->execute([':rid' => $roomId]);
        $json = $stmt->fetchColumn();
        if ($json === false || $json === null) {
            return ['state' => null, 'at' => null];
        }
        $meta = json_decode((string) $json, true) ?: [];
        $state = strtoupper(trim((string) ($meta['switch_state'] ?? '')));
        return [
            'state' => in_array($state, ['ON', 'OFF'], true) ? $state : null,
            'at' => $meta['switch_state_at'] ?? null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function camera(int $roomId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT d.id, d.label, d.meta_json
               FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
              WHERE r.id = :rid AND d.kind = 'CAMERA' AND d.subtype = 'INTERIOR'
              ORDER BY d.id ASC LIMIT 1"
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $meta = json_decode((string) ($row['meta_json'] ?? ''), true) ?: [];
        $enabled = ($meta['enabled'] ?? true) !== false;
        $rec = $this->pdo->prepare(
            "SELECT COUNT(*) FROM camera_recordings
              WHERE room_id = :r AND position = 'INTERIOR' AND status = 'RECORDING'"
        );
        $rec->execute([':r' => $roomId]);
        return [
            'id' => (int) $row['id'],
            'label' => $row['label'] !== null ? (string) $row['label'] : null,
            'enabled' => $enabled,
            'recording' => (int) $rec->fetchColumn() > 0,
            'mjpeg_url' => $enabled ? $this->mjpegUrl((int) $row['id']) : null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function activeMovement(int $roomId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.direction, m.`trigger`, m.status, m.scan_at, m.door_opened_at, m.created_at
               FROM timbre_state s
               JOIN timbre_movements m ON m.id = s.current_movement_id
              WHERE s.room_id = :r LIMIT 1'
        );
        $stmt->execute([':r' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'direction' => (string) $row['direction'],
            'trigger' => (string) $row['trigger'],
            'status' => (string) $row['status'],
            'scan_at' => $row['scan_at'],
            'door_opened_at' => $row['door_opened_at'],
            'created_at' => $row['created_at'],
        ];
    }

    private function lastMovementId(int $roomId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT MAX(id) FROM timbre_movements WHERE room_id = :r');
        $stmt->execute([':r' => $roomId]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null) ? null : (int) $v;
    }

    private function mjpegUrl(int $deviceId): ?string
    {
        $base = trim((string) (Config::get('CAMERAS_LIVE_BASE_URL', '') ?? ''));
        if ($base === '') {
            return null;
        }
        return rtrim($base, '/') . '?id=' . $deviceId;
    }

    private function ageSeconds(?string $ts, int $now): ?int
    {
        if ($ts === null || $ts === '') {
            return null;
        }
        $t = strtotime($ts . ' UTC');
        return $t === false ? null : max(0, $now - $t);
    }
}
