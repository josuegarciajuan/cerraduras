<?php
declare(strict_types=1);

namespace App\Domain\Rooms;

use App\Domain\Devices\Device;
use App\Domain\Warehouse\RemoteDoorOpenPolicy;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Errors\NotFoundException;
use App\Support\Qr\QrWindows;
use PDO;

/**
 * RoomControlStatusService — F124/RF-344/RF-345.
 *
 * Single local, read-only source for the remote room control panels.
 * It intentionally performs no network calls: devices, sensors, QR, debt,
 * firmware and warehouse state all come from the local database.
 */
final class RoomControlStatusService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Compact rows used by the panel room listing.
     *
     * @return list<array<string,mixed>>
     */
    public function controls(): array
    {
        $rows = $this->rows(
            "SELECT r.id, r.code, r.status, r.pack_id,
                    rt.code AS room_type_code, rt.name AS room_type,
                    p.name AS pack_name,
                    i.door_state, i.presence_state, i.last_door_event_at, i.last_presence_event_at,
                    (SELECT s.status FROM stays s
                      WHERE s.room_id = r.id AND s.status IN ('RESERVED','OCCUPIED','OVERSTAY')
                      ORDER BY s.id DESC LIMIT 1) AS stay_status,
                    (SELECT s.first_entry_at FROM stays s
                      WHERE s.room_id = r.id AND s.status IN ('RESERVED','OCCUPIED','OVERSTAY')
                      ORDER BY s.id DESC LIMIT 1) AS stay_first_entry_at,
                    (SELECT s.entry_confirmed_at FROM stays s
                      WHERE s.room_id = r.id AND s.status IN ('RESERVED','OCCUPIED','OVERSTAY')
                      ORDER BY s.id DESC LIMIT 1) AS stay_entry_confirmed_at,
                    (SELECT COUNT(*) FROM worker_sessions ws
                      WHERE ws.room_id = r.id AND ws.exited_at IS NULL) AS workers_inside,
                    (SELECT COUNT(*) FROM anomalies a
                      WHERE a.room_id = r.id AND a.status = 'OPEN') AS anomalies_open,
                    (SELECT COUNT(*) FROM devices d
                      WHERE d.pack_id = r.pack_id AND d.kind = 'SWITCH') AS light_installed,
                    (SELECT JSON_UNQUOTE(JSON_EXTRACT(d.meta_json, '$.switch_state'))
                       FROM devices d WHERE d.pack_id = r.pack_id AND d.kind = 'SWITCH'
                       ORDER BY d.id LIMIT 1) AS light_state,
                    (SELECT d.meta_json FROM devices d
                      WHERE d.pack_id = r.pack_id AND d.kind = 'SWITCH' ORDER BY d.id LIMIT 1) AS light_meta,
                    (SELECT COUNT(*) FROM devices d
                      WHERE d.pack_id = r.pack_id AND d.kind IN ('LOCK','RPI')) AS lock_installed
             FROM rooms r
             LEFT JOIN room_types rt ON rt.id = r.room_type_id
             LEFT JOIN device_packs p ON p.id = r.pack_id
             LEFT JOIN iot_sessions i ON i.room_id = r.id
             ORDER BY r.code ASC"
        );

        $now = time();
        $doorStaleSeconds = max(1, (int) (Config::getInt('DOOR_STALE_SECONDS', 300) ?? 300));
        $presenceStaleSeconds = max(1, (int) (Config::getInt('PRESENCE_STALE_SECONDS', 180) ?? 180));
        $items = [];
        foreach ($rows as $r) {
            $doorAge = $this->ageSeconds($r['last_door_event_at'] ?? null, $now);
            $presenceAge = $this->ageSeconds($r['last_presence_event_at'] ?? null, $now);
            $presenceState = (string) ($r['presence_state'] ?? 'UNKNOWN');
            $presenceStale = $presenceState === 'PRESENT'
                && ($presenceAge === null || $presenceAge > $presenceStaleSeconds);
            $started = ($r['stay_first_entry_at'] ?? null) !== null
                || ($r['stay_entry_confirmed_at'] ?? null) !== null;
            $lightMeta = $this->decodeJson($r['light_meta'] ?? null);
            $inside = ($presenceState === 'PRESENT' && !$presenceStale)
                || (($r['stay_entry_confirmed_at'] ?? null) !== null);
            $items[] = [
                'room_id'      => (int) $r['id'],
                'code'         => (string) $r['code'],
                'status'       => (string) $r['status'],
                'room_type'    => $r['room_type'] !== null ? (string) $r['room_type'] : null,
                'room_type_code' => $r['room_type_code'] !== null ? (string) $r['room_type_code'] : null,
                'pack_name'    => $r['pack_name'] !== null ? (string) $r['pack_name'] : null,
                'reservation'  => [
                    'active'  => $r['stay_status'] !== null,
                    'status'  => $r['stay_status'] !== null ? (string) $r['stay_status'] : null,
                    'started' => $started,
                ],
                'occupancy'    => [
                    'guest_inside'   => $inside,
                    'workers_inside' => (int) ($r['workers_inside'] ?? 0),
                    'presence_state' => $presenceState,
                    'presence_stale' => $presenceStale,
                ],
                'door'         => [
                    'state' => (string) ($r['door_state'] ?? 'UNKNOWN'),
                    'age_seconds' => $doorAge,
                    'stale' => ($r['door_state'] ?? 'UNKNOWN') === 'UNKNOWN'
                        || $doorAge === null
                        || $doorAge > $doorStaleSeconds,
                ],
                'light'        => [
                    'installed' => (int) ($r['light_installed'] ?? 0) > 0,
                    'state'     => $this->normalizeSwitchState($lightMeta['switch_state'] ?? null),
                    'state_at'  => $lightMeta['switch_state_at'] ?? null,
                ],
                'anomalies_open' => (int) ($r['anomalies_open'] ?? 0),
                'capabilities' => [
                    'light'  => (int) ($r['light_installed'] ?? 0) > 0,
                    'unlock' => (int) ($r['lock_installed'] ?? 0) > 0,
                    'reset'  => true,
                    'warehouse' => in_array((string) ($r['room_type_code'] ?? ''), ['ALMACEN_BEBIDAS', 'TIMBRADO'], true),
                ],
            ];
        }
        return $items;
    }

    /**
     * Full remote status for one room.
     *
     * @return array<string,mixed>
     *
     * @throws NotFoundException
     */
    public function status(int $roomId): array
    {
        $room = $this->row(
            "SELECT r.id, r.code, r.status, r.pack_id, r.room_type_id, r.notes,
                    rt.code AS room_type_code, rt.name AS room_type,
                    p.name AS pack_name, p.code AS pack_code
             FROM rooms r
             LEFT JOIN room_types rt ON rt.id = r.room_type_id
             LEFT JOIN device_packs p ON p.id = r.pack_id
             WHERE r.id = :rid LIMIT 1",
            [':rid' => $roomId]
        );
        if ($room === null) {
            throw new NotFoundException('Room not found', ['room_id' => $roomId]);
        }

        $now = time();
        $iot = $this->row(
            'SELECT door_state, presence_state, last_open_at, last_close_at, last_absent_since,
                    last_door_event_at, last_presence_event_at
             FROM iot_sessions WHERE room_id = :rid LIMIT 1',
            [':rid' => $roomId]
        ) ?? [];
        $doorStaleSeconds = max(1, (int) (Config::getInt('DOOR_STALE_SECONDS', 300) ?? 300));
        $presenceStaleSeconds = max(1, (int) (Config::getInt('PRESENCE_STALE_SECONDS', 180) ?? 180));
        $doorAge = $this->ageSeconds($iot['last_door_event_at'] ?? null, $now);
        $presenceAge = $this->ageSeconds($iot['last_presence_event_at'] ?? null, $now);
        $presenceState = (string) ($iot['presence_state'] ?? 'UNKNOWN');
        $presenceStale = $presenceState === 'PRESENT'
            && ($presenceAge === null || $presenceAge > $presenceStaleSeconds);
        $doorState = (string) ($iot['door_state'] ?? 'UNKNOWN');

        $stay = $this->row(
            "SELECT id, status, duracion_minutos, reserved_at, first_entry_at,
                    entry_confirmed_at, exit_detected_at, closed_at
             FROM stays
             WHERE room_id = :rid AND status IN ('RESERVED','OCCUPIED','OVERSTAY')
             ORDER BY id DESC LIMIT 1",
            [':rid' => $roomId]
        );
        $reservation = $this->reservationBlock($stay, $now);

        $workers = $this->rows(
            "SELECT ws.id, ws.worker_id, ws.entered_at,
                    w.name AS worker_name, wr.name AS role,
                    TIMESTAMPDIFF(MINUTE, ws.entered_at, UTC_TIMESTAMP()) AS duration_minutes
             FROM worker_sessions ws
             JOIN workers w ON w.id = ws.worker_id
             LEFT JOIN worker_roles wr ON wr.id = w.role_id
             WHERE ws.room_id = :rid AND ws.exited_at IS NULL
             ORDER BY ws.entered_at ASC",
            [':rid' => $roomId]
        );
        $workers = array_map(static function (array $w): array {
            return [
                'session_id' => (int) $w['id'],
                'worker_id' => (int) $w['worker_id'],
                'worker_name' => (string) $w['worker_name'],
                'role' => $w['role'] !== null ? (string) $w['role'] : null,
                'entered_at' => (string) $w['entered_at'],
                'duration_minutes' => (int) ($w['duration_minutes'] ?? 0),
            ];
        }, $workers);
        $guestInside = ($presenceState === 'PRESENT' && !$presenceStale)
            || ($reservation['entry_confirmed_at'] !== null && $reservation['exit_detected_at'] === null);

        $devices = $this->devicesForRoom($roomId);
        $light = $this->lightBlock($devices);
        $lock = $this->lockBlock($room, $devices, $now);

        $qr = $this->qrStatus($roomId);
        $anomalies = $this->rows(
            "SELECT id, anomaly_type, severity, status, detected_at
             FROM anomalies WHERE room_id = :rid AND status = 'OPEN'
             ORDER BY detected_at DESC LIMIT 20",
            [':rid' => $roomId]
        );
        $debt = $this->row(
            "SELECT COUNT(*) AS count, COALESCE(SUM(exceso_minutos), 0) AS exceso_minutos
             FROM debts WHERE room_id = :rid AND status <> 'SYNCED'",
            [':rid' => $roomId]
        ) ?? ['count' => 0, 'exceso_minutos' => 0];

        $firmware = $this->firmwareBlock($devices);
        $isWarehouse = in_array((string) ($room['room_type_code'] ?? ''), ['ALMACEN_BEBIDAS', 'TIMBRADO'], true);
        $warehouse = $isWarehouse ? $this->warehouseBlock($roomId) : null;

        $publicDevices = array_map(function (array $d): array {
            return [
                'id' => (int) $d['id'],
                'kind' => (string) $d['kind'],
                'subtype' => $d['subtype'] !== null ? (string) $d['subtype'] : null,
                'label' => $d['label'] !== null ? (string) $d['label'] : null,
                'external_id' => (string) $d['external_id'],
                'transport' => $d['transport'] !== null ? (string) $d['transport'] : null,
                'online_state' => $d['online_state'],
                'online_probed_at' => $d['online_probed_at'],
                'battery_pct' => $d['battery_pct'] !== null ? (int) $d['battery_pct'] : null,
                'firmware_variant' => $d['firmware_variant'] !== null ? (string) $d['firmware_variant'] : null,
                'firmware_version' => $d['firmware_version'] !== null ? (string) $d['firmware_version'] : null,
            ];
        }, $devices);

        return [
            'room' => [
                'id' => (int) $room['id'],
                'code' => (string) $room['code'],
                'status' => (string) $room['status'],
                'room_type_id' => (int) $room['room_type_id'],
                'room_type' => $room['room_type'] !== null ? (string) $room['room_type'] : null,
                'room_type_code' => $room['room_type_code'] !== null ? (string) $room['room_type_code'] : null,
                'pack_id' => $room['pack_id'] !== null ? (int) $room['pack_id'] : null,
                'pack_name' => $room['pack_name'] !== null ? (string) $room['pack_name'] : null,
                'notes' => $room['notes'] !== null ? (string) $room['notes'] : null,
            ],
            'reservation' => $reservation,
            'occupancy' => [
                'guest_inside' => $guestInside,
                'workers_inside' => $workers,
                'workers_inside_count' => count($workers),
                'any_inside' => $guestInside || count($workers) > 0,
                'presence_state' => $presenceState,
                'presence_age_seconds' => $presenceAge,
                'presence_stale' => $presenceStale,
                'presence_stale_seconds' => $presenceStaleSeconds,
            ],
            'door' => [
                'state' => $doorState,
                'age_seconds' => $doorAge,
                'stale' => $doorState === 'UNKNOWN' || $doorAge === null || $doorAge > $doorStaleSeconds,
                'stale_seconds' => $doorStaleSeconds,
                'last_open_at' => $iot['last_open_at'] ?? null,
                'last_close_at' => $iot['last_close_at'] ?? null,
                'last_absent_since' => $iot['last_absent_since'] ?? null,
                'open_too_long' => $doorState === 'OPEN'
                    && $doorAge !== null
                    && $doorAge > 300,
            ],
            'light' => $light,
            'lock' => $lock,
            'qr' => $qr,
            'devices' => $publicDevices,
            'firmware' => $firmware,
            'warehouse' => $warehouse,
            'anomalies' => array_map(static function (array $a): array {
                return [
                    'id' => (int) $a['id'],
                    'type' => (string) $a['anomaly_type'],
                    'severity' => (string) $a['severity'],
                    'status' => (string) $a['status'],
                    'detected_at' => (string) $a['detected_at'],
                ];
            }, $anomalies),
            'debt' => [
                'open_count' => (int) ($debt['count'] ?? 0),
                'overstay_minutes' => (int) ($debt['exceso_minutos'] ?? 0),
            ],
            'capabilities' => [
                'light' => $light['installed'],
                'unlock' => $lock['installed'],
                'reset' => true,
                'warehouse' => $isWarehouse,
            ],
            'server_ts' => Clock::nowIsoUtcMillis(),
        ];
    }

    /**
     * Light-only read used by GET /rooms/{id}/light.
     *
     * @return array<string,mixed>
     *
     * @throws NotFoundException
     */
    public function lightState(int $roomId): array
    {
        $exists = $this->row('SELECT id FROM rooms WHERE id = :rid LIMIT 1', [':rid' => $roomId]);
        if ($exists === null) {
            throw new NotFoundException('Room not found', ['room_id' => $roomId]);
        }
        return ['room_id' => $roomId, 'light' => $this->lightBlock($this->devicesForRoom($roomId))];
    }

    /** @param array<string,mixed>|null $stay */
    private function reservationBlock(?array $stay, int $now): array
    {
        if ($stay === null) {
            return [
                'active' => false, 'id' => null, 'status' => null,
                'duracion_minutos' => null, 'reserved_at' => null,
                'first_entry_at' => null, 'entry_confirmed_at' => null,
                'exit_detected_at' => null, 'closed_at' => null,
                'started' => false, 'elapsed_minutes' => null,
                'remaining_minutes' => null, 'overstay_minutes' => 0,
            ];
        }
        $firstEntry = $stay['first_entry_at'] ?? null;
        $entryConfirmed = $stay['entry_confirmed_at'] ?? null;
        $duration = (int) ($stay['duracion_minutos'] ?? 0);
        $started = $firstEntry !== null || $entryConfirmed !== null;
        $startTs = $this->sqlEpoch($firstEntry ?? $entryConfirmed ?? null);
        $elapsed = $started && $startTs !== null ? max(0, (int) floor(($now - $startTs) / 60)) : null;
        $remaining = $elapsed !== null && $duration > 0 ? max(0, $duration - $elapsed) : null;
        $overstay = $elapsed !== null && $duration > 0 ? max(0, $elapsed - $duration) : 0;
        return [
            'active' => true,
            'id' => (int) $stay['id'],
            'status' => (string) $stay['status'],
            'duracion_minutos' => $duration,
            'reserved_at' => (string) $stay['reserved_at'],
            'first_entry_at' => $firstEntry,
            'entry_confirmed_at' => $entryConfirmed,
            'exit_detected_at' => $stay['exit_detected_at'] ?? null,
            'closed_at' => $stay['closed_at'] ?? null,
            'started' => $started,
            'elapsed_minutes' => $elapsed,
            'remaining_minutes' => $remaining,
            'overstay_minutes' => $overstay,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function devicesForRoom(int $roomId): array
    {
        return $this->rows(
            "SELECT d.id, d.kind, d.subtype, d.label, d.external_id, d.transport,
                    d.meta_json, d.online_state, d.online_probed_at, d.battery_pct,
                    d.firmware_variant, d.firmware_version
             FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
             WHERE r.id = :rid
             ORDER BY FIELD(d.kind,'RPI','LOCK','SCANNER','PROXIMITY','PRESENCE','SWITCH','CAMERA'), d.id",
            [':rid' => $roomId]
        );
    }

    /** @param list<array<string,mixed>> $devices */
    private function lightBlock(array $devices): array
    {
        foreach ($devices as $d) {
            if ((string) $d['kind'] !== Device::KIND_SWITCH) {
                continue;
            }
            $meta = $this->decodeJson($d['meta_json'] ?? null);
            $state = $this->normalizeSwitchState($meta['switch_state'] ?? null);
            $lastCommand = strtoupper((string) ($meta['last_command'] ?? ''));
            $pending = in_array($lastCommand, ['ON', 'OFF'], true) && $lastCommand !== $state;
            return [
                'installed' => true,
                'device_id' => (int) $d['id'],
                'transport' => $d['transport'] !== null ? (string) $d['transport'] : null,
                'state' => $state,
                'state_at' => $meta['switch_state_at'] ?? null,
                'last_command' => in_array($lastCommand, ['ON', 'OFF'], true) ? $lastCommand : null,
                'commanded_at' => $meta['commanded_at'] ?? null,
                'pending_confirmation' => $pending,
                'confirmed' => in_array($state, ['ON', 'OFF'], true),
                'unreachable' => ($meta['switch_unreachable'] ?? false) === true,
                'last_error' => $meta['last_error'] ?? null,
                'last_error_at' => $meta['last_error_at'] ?? null,
            ];
        }
        return [
            'installed' => false, 'device_id' => null, 'transport' => null,
            'state' => 'UNKNOWN', 'state_at' => null, 'last_command' => null,
            'commanded_at' => null, 'pending_confirmation' => false,
            'confirmed' => false, 'unreachable' => false,
            'last_error' => null, 'last_error_at' => null,
        ];
    }

    /** @param list<array<string,mixed>> $devices */
    private function lockBlock(array $room, array $devices, int $now): array
    {
        $installed = false;
        $remoteCapable = false;
        foreach ($devices as $d) {
            if ((string) $d['kind'] === Device::KIND_LOCK) {
                $installed = true;
            }
            if ((string) $d['kind'] === Device::KIND_RPI) {
                $meta = $this->decodeJson($d['meta_json'] ?? null);
                if (RemoteDoorOpenPolicy::isCapable($meta, $d['firmware_version'] ?? null)) {
                    $remoteCapable = true;
                }
            }
        }
        $cooldownUntil = null;
        $cooldownActive = false;
        try {
            $row = $this->row('SELECT cooldown_until FROM rooms WHERE id = :rid LIMIT 1', [':rid' => (int) $room['id']]);
            $cooldownUntil = $row['cooldown_until'] ?? null;
            if ($cooldownUntil !== null) {
                $ts = strtotime((string) $cooldownUntil . ' UTC');
                $cooldownActive = $ts !== false && $ts > $now;
            }
        } catch (\Throwable $e) {
            error_log('[RoomControlStatusService] cooldown: ' . $e->getMessage());
        }
        return [
            'installed' => $installed || $remoteCapable,
            'has_local_lock' => $installed,
            'remote_capable' => $remoteCapable,
            'cooldown_until' => $cooldownUntil,
            'cooldown_active' => $cooldownActive,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function firmwareBlock(array $devices): array
    {
        $rpIs = array_values(array_filter($devices, static function (array $d): bool {
            return (string) $d['kind'] === Device::KIND_RPI;
        }));
        $releases = [];
        try {
            $releases = $this->rows(
                'SELECT variant, version, published_at
                 FROM firmware_releases WHERE published = 1
                 ORDER BY published_at DESC, id DESC'
            );
        } catch (\Throwable $e) {
            error_log('[RoomControlStatusService] releases: ' . $e->getMessage());
        }
        $ops = [];
        if ($rpIs !== []) {
            $ids = array_map(static fn(array $d): int => (int) $d['id'], $rpIs);
            $in = implode(',', $ids);
            $rows = $this->rows(
                "SELECT o.* FROM device_firmware_operations o
                 JOIN (SELECT device_id, MAX(id) AS id FROM device_firmware_operations
                       WHERE device_id IN ($in) GROUP BY device_id) latest ON latest.id = o.id"
            );
            foreach ($rows as $op) {
                $ops[(int) $op['device_id']] = [
                    'id' => (int) $op['id'],
                    'status' => (string) $op['status'],
                    'progress_pct' => (int) $op['progress_pct'],
                    'from_variant' => $op['from_variant'],
                    'to_variant' => (string) $op['to_variant'],
                    'to_version' => (string) $op['to_version'],
                    'error_code' => $op['error_code'],
                    'finished_at' => $op['finished_at'],
                    'updated_at' => $op['updated_at'],
                ];
            }
        }
        $devicesOut = [];
        foreach ($rpIs as $d) {
            $meta = $this->decodeJson($d['meta_json'] ?? null);
            $variant = $d['firmware_variant'] !== null
                ? (string) $d['firmware_variant']
                : Device::normalizeEspVariant($d['subtype'] ?? null);
            $capable = !empty($meta['ota_capable'])
                || (isset($meta['ota_protocol']) && (string) $meta['ota_protocol'] !== '');
            $devicesOut[] = [
                'device_id' => (int) $d['id'],
                'label' => $d['label'] !== null ? (string) $d['label'] : null,
                'external_id' => (string) $d['external_id'],
                'variant' => $variant,
                'version' => $d['firmware_version'] !== null ? (string) $d['firmware_version'] : null,
                'online_state' => $d['online_state'],
                'ota_capable' => $capable,
                'operation' => $ops[(int) $d['id']] ?? null,
            ];
        }
        $releasesOut = array_map(static function (array $r): array {
            return [
                'variant' => (string) $r['variant'],
                'version' => (string) $r['version'],
                'published_at' => $r['published_at'] ?? null,
            ];
        }, $releases);
        return ['devices' => $devicesOut, 'releases' => $releasesOut];
    }

    /** @return array<string,mixed>|null */
    private function warehouseBlock(int $roomId): ?array
    {
        try {
            $state = $this->row(
                'SELECT state, current_visit_id, entry_trigger, deadline_x, deadline_m, presence_confirmed, updated_at
                 FROM warehouse_state WHERE room_id = :rid LIMIT 1',
                [':rid' => $roomId]
            );
            $visits = $this->rows(
                "SELECT id, entry_trigger, outcome, entered_at, exited_at, created_at
                 FROM warehouse_visits WHERE room_id = :rid
                 ORDER BY created_at DESC, id DESC LIMIT 10",
                [':rid' => $roomId]
            );
            $cameras = $this->rows(
                "SELECT d.id, d.subtype, d.label, d.external_id, d.meta_json, d.last_motion_at
                 FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
                 WHERE r.id = :rid AND d.kind = 'CAMERA'
                 ORDER BY FIELD(d.subtype,'EXTERIOR','INTERIOR'), d.id",
                [':rid' => $roomId]
            );
            $cams = [];
            foreach ($cameras as $cam) {
                $meta = $this->decodeJson($cam['meta_json'] ?? null);
                $rec = $this->row(
                    "SELECT COUNT(*) AS c FROM camera_recordings
                     WHERE room_id = :rid AND position = :pos AND status = 'RECORDING'",
                    [':rid' => $roomId, ':pos' => (string) $cam['subtype']]
                );
                $cams[] = [
                    'id' => (int) $cam['id'],
                    'position' => (string) $cam['subtype'],
                    'label' => $cam['label'] !== null ? (string) $cam['label'] : null,
                    'external_id' => (string) $cam['external_id'],
                    'enabled' => ($meta['enabled'] ?? true) !== false,
                    'record_enabled' => ($meta['record_enabled'] ?? true) !== false,
                    'recording' => (int) ($rec['c'] ?? 0) > 0,
                    'last_motion_at' => $cam['last_motion_at'] ?? null,
                ];
            }
            return [
                'state' => $state !== null ? (string) $state['state'] : 'IDLE',
                'current_visit_id' => $state['current_visit_id'] ?? null,
                'entry_trigger' => $state['entry_trigger'] ?? null,
                'presence_confirmed' => (bool) ($state['presence_confirmed'] ?? false),
                'updated_at' => $state['updated_at'] ?? null,
                'visits' => array_map(static function (array $v): array {
                    return [
                        'id' => (int) $v['id'],
                        'entry_trigger' => (string) $v['entry_trigger'],
                        'outcome' => (string) $v['outcome'],
                        'entered_at' => $v['entered_at'],
                        'exited_at' => $v['exited_at'],
                        'created_at' => (string) $v['created_at'],
                    ];
                }, $visits),
                'cameras' => $cams,
            ];
        } catch (\Throwable $e) {
            error_log('[RoomControlStatusService] warehouse: ' . $e->getMessage());
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function qrStatus(int $roomId): array
    {
        $default = [
            'present' => false, 'state' => 'none', 'scannable' => false,
            'consumed' => false, 'revoked' => false, 'expired' => false,
            'jti' => null, 'stay_id' => null, 'expires_at' => null,
            'first_used_at' => null, 'valid_until' => null, 'arrival_deadline' => null,
            'in_use' => false,
        ];
        try {
            $row = $this->row(
                "SELECT qc.jti, qc.issued_at, qc.consumed_at, qc.revoked_at, qc.expires_at,
                        qc.first_used_at, qc.valid_until, qc.stay_id,
                        s.duracion_minutos AS stay_duracion
                 FROM qr_credentials qc JOIN stays s ON s.id = qc.stay_id
                 WHERE s.room_id = :rid AND s.status IN ('RESERVED','OCCUPIED')
                 ORDER BY qc.issued_at DESC LIMIT 1",
                [':rid' => $roomId]
            );
        } catch (\Throwable $e) {
            error_log('[RoomControlStatusService] qr: ' . $e->getMessage());
            return $default;
        }
        if ($row === null) {
            return $default;
        }
        $now = Clock::nowUtc()->getTimestamp();
        $iat = $this->sqlEpoch($row['issued_at'] ?? null) ?? $now;
        $firstUsed = $this->sqlEpoch($row['first_used_at'] ?? null)
            ?? $this->sqlEpoch($row['consumed_at'] ?? null);
        $validUntil = $this->sqlEpoch($row['valid_until'] ?? null);
        $arrivalMinutes = Config::getInt('QR_ARRIVAL_WINDOW_MINUTES', 15) ?? 15;
        $duration = (int) ($row['stay_duracion'] ?? 0);
        $state = QrWindows::evaluate($iat, $firstUsed, $validUntil, $duration, $arrivalMinutes, $now);
        $expired = in_array($state, [QrWindows::STATE_EXPIRED_ARRIVAL, QrWindows::STATE_EXPIRED_USAGE], true);
        return [
            'present' => true,
            'state' => $state,
            'scannable' => !$row['consumed_at'] && !$row['revoked_at'] && !$expired,
            'consumed' => (bool) $row['consumed_at'],
            'revoked' => (bool) $row['revoked_at'],
            'expired' => $expired,
            'jti' => (string) $row['jti'],
            'stay_id' => (int) $row['stay_id'],
            'expires_at' => $row['expires_at'],
            'first_used_at' => $row['first_used_at'] ?? null,
            'valid_until' => $row['valid_until'] ?? null,
            'arrival_deadline' => gmdate('Y-m-d H:i:s', QrWindows::arrivalDeadline($iat, $arrivalMinutes)) . '.000',
            'in_use' => $firstUsed !== null,
        ];
    }

    private function normalizeSwitchState(mixed $state): string
    {
        $value = strtoupper(trim((string) $state));
        return in_array($value, ['ON', 'OFF'], true) ? $value : 'UNKNOWN';
    }

    private function decodeJson(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function ageSeconds(?string $sqlDate, int $now): ?int
    {
        $ts = $this->sqlEpoch($sqlDate);
        return $ts === null ? null : max(0, $now - $ts);
    }

    private static function sqlEpoch(?string $sqlDate): ?int
    {
        if ($sqlDate === null || $sqlDate === '') {
            return null;
        }
        $ts = strtotime(str_replace('T', ' ', $sqlDate) . (str_ends_with($sqlDate, 'Z') ? '' : ' UTC'));
        return $ts === false ? null : $ts;
    }

    /** @param array<string,mixed> $params @return list<array<string,mixed>> */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $params @return array<string,mixed>|null */
    private function row(string $sql, array $params = []): ?array
    {
        $rows = $this->rows($sql, $params);
        return $rows[0] ?? null;
    }
}
