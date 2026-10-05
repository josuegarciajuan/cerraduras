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
                // F70/RF-80.4: directo MJPEG (aditivo). null si no hay base configurada.
                'mjpeg_url' => $enabled ? $this->mjpegUrl((int) $c['id']) : null,
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
        $staleSeconds = $this->doorStaleSeconds();
        $live = [
            'door_state' => 'UNKNOWN',
            'presence_state' => 'UNKNOWN',
            'switch_state' => 'UNKNOWN',
            'last_open_at' => null,
            'last_close_at' => null,
            'last_absent_since' => null,
            // F77.5: frescura HONESTA desde el último evento APLICADO (transición
            // real). Antes se usaba MAX(received_at), que los no-ops del resync
            // refrescaban cada 15 min → una puerta atascada parecía "fresca".
            'door_age_seconds' => null,
            'presence_age_seconds' => null,
            'door_stale_seconds' => $staleSeconds,
            'door_stale' => true,
            // F77.7: estado de luz INFERIDO del último comando (no es estado real
            // por push; se etiqueta distinto para no confundir).
            'switch_state_inferred' => null,
            // F80/RF-103.3: últimos eventos de presencia APLICADOS, para que el
            // croquis anime el monigote por fases en vivo (mismo enfoque que el
            // dashboard operativo). Aditivo: no cambia la forma de los campos previos.
            'recent_presence' => [],
        ];
        $ls = $this->pdo->prepare(
            'SELECT door_state, presence_state, last_open_at, last_close_at, last_absent_since,
                    last_door_event_at, last_presence_event_at
             FROM iot_sessions WHERE room_id = :r LIMIT 1'
        );
        $ls->execute([':r' => $roomId]);
        $lsRow = $ls->fetch(PDO::FETCH_ASSOC);
        $nowTs = time();
        if ($lsRow !== false) {
            $live['door_state'] = (string) ($lsRow['door_state'] ?? 'UNKNOWN');
            $live['presence_state'] = (string) ($lsRow['presence_state'] ?? 'UNKNOWN');
            $live['last_open_at'] = $lsRow['last_open_at'] ?? null;
            $live['last_close_at'] = $lsRow['last_close_at'] ?? null;
            $live['last_absent_since'] = $lsRow['last_absent_since'] ?? null;
            $live['door_age_seconds'] = $this->ageSeconds($lsRow['last_door_event_at'] ?? null, $nowTs);
            $live['presence_age_seconds'] = $this->ageSeconds($lsRow['last_presence_event_at'] ?? null, $nowTs);
        }
        // F77.5: la puerta es "stale" si no hay estado conocido o el último
        // evento aplicado supera el umbral (sensor mudo/cacheado).
        $live['door_stale'] = ($live['door_state'] === 'UNKNOWN')
            || ($live['door_age_seconds'] === null)
            || ($live['door_age_seconds'] > $staleSeconds);
        $swStmt = $this->pdo->prepare(
            "SELECT d.meta_json FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
             WHERE r.id = :rid AND d.kind='SWITCH' LIMIT 1"
        );
        $swStmt->execute([':rid' => $roomId]);
        $swMeta = json_decode((string) ($swStmt->fetchColumn() ?: ''), true) ?: [];
        if (isset($swMeta['switch_state']) && is_string($swMeta['switch_state']) && $swMeta['switch_state'] !== '') {
            $live['switch_state'] = $swMeta['switch_state'];
        } else {
            // F77.7: sin push del SWITCH (regla de mensajes Tuya pendiente), se
            // ofrece el último comando conocido como estimación honesta.
            $cmd = strtoupper((string) ($swMeta['last_command'] ?? ''));
            if ($cmd === 'ON' || $cmd === 'OFF') {
                $live['switch_state_inferred'] = $cmd;
            }
        }

        // F80/RF-103.3: últimos eventos de presencia aplicados (auditoría F41/F44
        // marca `applied=1`). Alimenta las fases del monigote en el croquis.
        $rp = $this->pdo->prepare(
            "SELECT sensor, value, occurred_at
             FROM presence_events
             WHERE room_id = :r AND applied = 1
             ORDER BY occurred_at DESC, id DESC
             LIMIT 10"
        );
        $rp->execute([':r' => $roomId]);
        foreach ($rp->fetchAll(PDO::FETCH_ASSOC) ?: [] as $e) {
            $live['recent_presence'][] = [
                'sensor'      => (string) $e['sensor'],
                'value'       => (string) $e['value'],
                'occurred_at' => (string) $e['occurred_at'],
            ];
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

    /**
     * F70/RF-80.4: URL del directo MJPEG para un dispositivo cámara.
     * Se construye desde `CAMERAS_LIVE_BASE_URL` (p. ej.
     * `https://cerraduras.josue.ink/almacen-live`); null si no está configurada.
     */
    private function mjpegUrl(int $deviceId): ?string
    {
        $base = trim((string) (\App\Support\Config::get('CAMERAS_LIVE_BASE_URL', '') ?? ''));
        if ($base === '') {
            return null;
        }
        return rtrim($base, '/') . '?id=' . $deviceId;
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
        // F77.1/F77.8: la raíz de la API es `api/` (3 niveles desde src/Http/Controllers).
        // Antes apuntaba a `api/src/data/cameras` (inexistente) → disk_used_pct null.
        $dir = dirname(__DIR__, 3) . '/data/cameras';
        $free = @disk_free_space($dir);
        $total = @disk_total_space($dir);
        $pct = ($free !== false && $total !== false && $total > 0)
            ? (int) round((($total - $free) / $total) * 100)
            : null;
        return ['days' => $days, 'auto' => $days > 0, 'disk_used_pct' => $pct];
    }

    /**
     * F77.5: umbral (segundos) a partir del cual el estado de puerta se considera
     * viejo/no fiable. Configurable por `DOOR_STALE_SECONDS` (default 300 s).
     */
    private function doorStaleSeconds(): int
    {
        $v = (int) (\App\Support\Config::getInt('DOOR_STALE_SECONDS', 300) ?? 300);
        return $v > 0 ? $v : 300;
    }

    /** F77.5: antigüedad (s) de un timestamp MySQL UTC nullable; null si no hay. */
    private function ageSeconds(?string $ts, int $nowTs): ?int
    {
        if ($ts === null || $ts === '') {
            return null;
        }
        $parsed = strtotime($ts . ' UTC');
        return $parsed === false ? null : max(0, $nowTs - $parsed);
    }
}
