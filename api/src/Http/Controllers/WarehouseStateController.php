<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Warehouse\Go2rtcClient;
use App\Http\Request;
use App\Http\Response;
use PDO;

/**
 * WarehouseStateController: live snapshot for the /almacen panel
 * (F65/RF-74.2).
 *
 *   GET /almacen-api/state?room_id=
 *
 * Returns warehouse state, current visit, cameras (recording + live URL),
 * active recordings and retention info. The SSE controller reuses stateArray().
 */
final class WarehouseStateController
{
    private PDO $pdo;
    private Go2rtcClient $go2rtc;

    public function __construct(PDO $pdo, Go2rtcClient $go2rtc)
    {
        $this->pdo = $pdo;
        $this->go2rtc = $go2rtc;
    }

    public function show(Request $request): Response
    {
        $roomId = (int) ($request->query['room_id'] ?? 0);
        return Response::json(200, $this->stateArray($roomId));
    }

    /** @return array<string,mixed> */
    public function stateArray(int $roomId = 0): array
    {
        $room = null;
        if ($roomId > 0) {
            $stmt = $this->pdo->prepare(
                "SELECT r.id, r.code, r.room_type_id FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
                 WHERE r.id = :id AND rt.code = 'ALMACEN_BEBIDAS' LIMIT 1"
            );
            $stmt->execute([':id' => $roomId]);
            $room = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($room === null) {
            $room = $this->pdo->query(
                "SELECT r.id, r.code, r.room_type_id FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
                 WHERE rt.code = 'ALMACEN_BEBIDAS' ORDER BY r.id ASC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($room === null) {
            return [
                'room' => null,
                'warehouse' => null,
                'live' => null,
                'cameras' => [],
                'recordings_active' => [],
                'retention' => $this->retentionInfo(),
                'server_ts' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
        }
        $roomId = (int) $room['id'];

        $ws = $this->pdo->prepare('SELECT state, current_visit_id FROM warehouse_state WHERE room_id = :r LIMIT 1');
        $ws->execute([':r' => $roomId]);
        $wsRow = $ws->fetch(PDO::FETCH_ASSOC) ?: ['state' => 'IDLE', 'current_visit_id' => null];

        $visit = null;
        if ($wsRow['current_visit_id'] !== null) {
            $vs = $this->pdo->prepare(
                'SELECT v.id, v.worker_id, w.name AS worker_name, v.entry_trigger, v.outcome, v.entered_at
                 FROM warehouse_visits v LEFT JOIN workers w ON w.id = v.worker_id WHERE v.id = :id LIMIT 1'
            );
            $vs->execute([':id' => (int) $wsRow['current_visit_id']]);
            $v = $vs->fetch(PDO::FETCH_ASSOC);
            if ($v !== false) {
                $entered = $v['entered_at'] ?? null;
                $visit = [
                    'id' => (int) $v['id'],
                    'worker' => $v['worker_id'] !== null ? ['id' => (int) $v['worker_id'], 'name' => (string) ($v['worker_name'] ?? '')] : null,
                    'entry_trigger' => (string) $v['entry_trigger'],
                    'outcome' => (string) $v['outcome'],
                    'entered_at' => $entered,
                    'seconds_inside' => $entered !== null ? max(0, time() - strtotime((string) $entered . ' UTC')) : null,
                ];
            }
        }

        $cams = [];
        $cstmt = $this->pdo->prepare(
            "SELECT d.id, d.subtype, d.label, d.meta_json
             FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
             WHERE r.id = :rid AND d.kind='CAMERA'
             ORDER BY FIELD(d.subtype,'EXTERIOR','INTERIOR'), d.id"
        );
        $cstmt->execute([':rid' => $roomId]);
        $activeRecs = [];
        foreach ($cstmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
            $meta = json_decode((string) ($c['meta_json'] ?? ''), true) ?: [];
            $position = (string) $c['subtype'];
            $enabled = ($meta['enabled'] ?? true) !== false;
            $rec = $this->pdo->prepare("SELECT COUNT(*) FROM camera_recordings WHERE room_id=:r AND position=:p AND status='RECORDING'");
            $rec->execute([':r' => $roomId, ':p' => $position]);
            $cams[] = [
                'id' => (int) $c['id'],
                'position' => $position,
                'label' => $c['label'] !== null ? (string) $c['label'] : null,
                'enabled' => $enabled,
                'recording' => (int) $rec->fetchColumn() > 0,
                'online' => null,
                'stream' => Go2rtcClient::streamName($roomId, $position),
                'live_url' => $enabled ? $this->go2rtc->liveUrl($roomId, $position) : null,
            ];
        }

        $ar = $this->pdo->prepare(
            "SELECT position, episode, status, started_at FROM camera_recordings
             WHERE room_id=:r AND status IN ('PENDING','RECORDING') ORDER BY id ASC"
        );
        $ar->execute([':r' => $roomId]);
        foreach ($ar->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $activeRecs[] = [
                'position' => (string) $r['position'],
                'episode' => (string) $r['episode'],
                'status' => (string) $r['status'],
                'started_at' => $r['started_at'],
            ];
        }

        // F67/RF-77.3: bloque `live` aditivo para el croquis del panel. Lee el
        // estado IoT de la sala (puerta/presencia) y el estado real del SWITCH
        // por push (F50). Sin llamadas a Tuya: solo BD.
        $live = [
            'door_state' => 'UNKNOWN',
            'presence_state' => 'UNKNOWN',
            'switch_state' => 'UNKNOWN',
            'last_open_at' => null,
            'last_close_at' => null,
            'last_absent_since' => null,
        ];
        $ls = $this->pdo->prepare(
            'SELECT door_state, presence_state, last_open_at, last_close_at, last_absent_since
             FROM iot_sessions WHERE room_id = :r LIMIT 1'
        );
        $ls->execute([':r' => $roomId]);
        $lsRow = $ls->fetch(PDO::FETCH_ASSOC);
        if ($lsRow !== false) {
            $live['door_state'] = (string) ($lsRow['door_state'] ?? 'UNKNOWN');
            $live['presence_state'] = (string) ($lsRow['presence_state'] ?? 'UNKNOWN');
            $live['last_open_at'] = $lsRow['last_open_at'] ?? null;
            $live['last_close_at'] = $lsRow['last_close_at'] ?? null;
            $live['last_absent_since'] = $lsRow['last_absent_since'] ?? null;
        }
        $swStmt = $this->pdo->prepare(
            "SELECT d.meta_json FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
             WHERE r.id = :rid AND d.kind='SWITCH' LIMIT 1"
        );
        $swStmt->execute([':rid' => $roomId]);
        $swMeta = json_decode((string) ($swStmt->fetchColumn() ?: ''), true) ?: [];
        if (isset($swMeta['switch_state']) && is_string($swMeta['switch_state']) && $swMeta['switch_state'] !== '') {
            $live['switch_state'] = $swMeta['switch_state'];
        }

        return [
            'room' => ['id' => $roomId, 'code' => (string) $room['code'], 'room_type_id' => (int) $room['room_type_id']],
            'warehouse' => [
                'state' => (string) $wsRow['state'],
                'occupied' => $visit !== null && ($visit['outcome'] ?? '') === 'ENTERED',
                'current_visit' => $visit,
            ],
            'live' => $live,
            'cameras' => $cams,
            'recordings_active' => $activeRecs,
            'retention' => $this->retentionInfo(),
            'server_ts' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @return array{days:int,auto:bool,disk_used_pct:?int} */
    private function retentionInfo(): array
    {
        $days = 1;
        $stmt = $this->pdo->prepare("SELECT value FROM system_settings WHERE service='api' AND setting_key='warehouse.retention_days' LIMIT 1");
        $stmt->execute();
        $v = $stmt->fetchColumn();
        if ($v !== false && $v !== null && $v !== '') {
            $days = (int) $v;
        }
        $dir = dirname(__DIR__, 2) . '/data/cameras';
        $free = @disk_free_space($dir);
        $total = @disk_total_space($dir);
        $pct = ($free !== false && $total !== false && $total > 0)
            ? (int) round((($total - $free) / $total) * 100)
            : null;
        return ['days' => $days, 'auto' => $days > 0, 'disk_used_pct' => $pct];
    }
}
