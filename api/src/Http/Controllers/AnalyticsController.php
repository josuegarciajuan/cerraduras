<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Analytics\AccessMovementCollapser;
use App\Infrastructure\Sensors\ZigbeePipelineStatusService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Config;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * AnalyticsController — F119.
 *
 * Read-only aggregates for the CRM panel. Everything is computed from the
 * local database (rooms, stays, worker_sessions, access_events,
 * key_access_events, anomalies, devices, warehouse_*). No Tuya calls, no
 * pollers, no writes.
 *
 * All endpoints accept ?days=N (1..366, default 7). Dates are grouped in the
 * application timezone (APP_TZ, Europe/Madrid by default) using a numeric
 * offset and converted to UTC for the SQL bounds. The underlying columns stay
 * in UTC.
 */
final class AnalyticsController
{
    private PDO $pdo;
    private ?ZigbeePipelineStatusService $pipelines = null;

    public function __construct(PDO $pdo, ?ZigbeePipelineStatusService $pipelines = null)
    {
        $this->pdo = $pdo;
        $this->pipelines = $pipelines;
    }

    private function pipelineService(): ZigbeePipelineStatusService
    {
        return $this->pipelines ??= new ZigbeePipelineStatusService();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Public endpoints
    // ─────────────────────────────────────────────────────────────────────

    public function overview(Request $request): Response
    {
        [$from, $to, $days, $granularity, $fromLocal, $toLocal] = $this->range($request);
        $today = $this->todayUtc();

        $statusRows = $this->rows('SELECT status, COUNT(*) AS c FROM rooms GROUP BY status');
        $statusCounts = [];
        foreach ($statusRows as $row) {
            $statusCounts[(string) $row['status']] = (int) $row['c'];
        }
        $roomsTotal = array_sum($statusCounts);
        $occupied = ($statusCounts['OCCUPIED'] ?? 0) + ($statusCounts['OVERSTAY'] ?? 0);

        $workersInside = $this->scalar('SELECT COUNT(DISTINCT worker_id) FROM worker_sessions WHERE exited_at IS NULL');
        $sessionsToday = $this->scalar(
            'SELECT COUNT(*) FROM worker_sessions WHERE entered_at >= :today',
            [':today' => $today]
        );
        $accessesToday = $this->scalar(
            'SELECT COUNT(*) FROM access_events WHERE occurred_at >= :today',
            [':today' => $today]
        );
        $deniedToday = $this->scalar(
            "SELECT COUNT(*) FROM access_events WHERE result = 'FAIL' AND occurred_at >= :today",
            [':today' => $today]
        );
        $scansToday = $this->scalar(
            "SELECT COUNT(*) FROM access_events WHERE kind = 'QR_VALIDATE' AND occurred_at >= :today",
            [':today' => $today]
        ) + $this->scalar(
            'SELECT COUNT(*) FROM key_access_events WHERE occurred_at >= :today',
            [':today' => $today]
        );
        $anomaliesOpen = $this->scalar("SELECT COUNT(*) FROM anomalies WHERE status = 'OPEN'");
        $avgStay = $this->scalar(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, first_entry_at, COALESCE(closed_at, exit_detected_at, UTC_TIMESTAMP())))
             FROM stays
             WHERE first_entry_at IS NOT NULL AND first_entry_at BETWEEN :from AND :to',
            [':from' => $from, ':to' => $to]
        );
        $warehouseVisitsToday = $this->scalar(
            'SELECT COUNT(*) FROM warehouse_visits WHERE created_at >= :today',
            [':today' => $today]
        );

        $deviceSummary = $this->deviceSummary();
        $warehouse = $this->warehouseSummary($from, $to);
        $accessSummary = $this->accessSummary($from, $to);
        $anomalySummary = $this->anomalySummary($from);

        $canSeeWorkers = $this->canSeeWorkers($request);

        $payload = [
            'generated_at' => gmdate('c'),
            'range' => [
                'from' => $fromLocal->format('Y-m-d'),
                'to' => $toLocal->format('Y-m-d'),
                'days' => $days,
                'granularity' => $granularity,
            ],
            'kpis' => [
                'rooms_total' => $roomsTotal,
                'rooms_occupied' => $statusCounts['OCCUPIED'] ?? 0,
                'rooms_overstay' => $statusCounts['OVERSTAY'] ?? 0,
                'rooms_free' => $statusCounts['FREE'] ?? 0,
                'rooms_cleaning' => $statusCounts['CLEANING'] ?? 0,
                'rooms_reserved' => $statusCounts['RESERVED'] ?? 0,
                'rooms_out_of_service' => $statusCounts['OUT_OF_SERVICE'] ?? 0,
                'occupancy_pct' => $roomsTotal > 0 ? round($occupied / $roomsTotal * 100, 1) : 0.0,
                'workers_inside' => $workersInside,
                'sessions_today' => $sessionsToday,
                'accesses_today' => $accessesToday,
                'denied_today' => $deniedToday,
                'scans_today' => $scansToday,
                'anomalies_open' => $anomaliesOpen,
                'avg_stay_minutes' => $avgStay !== null ? (int) round((float) $avgStay) : 0,
                'warehouse_visits_today' => $warehouseVisitsToday,
                'devices_online' => $deviceSummary['online'],
                'devices_offline' => $deviceSummary['offline'],
                'devices_unknown' => $deviceSummary['unknown'],
                'low_battery' => $deviceSummary['low_battery'],
            ],
            'status_distribution' => $this->statusDistribution($statusCounts),
            'occupancy_series' => $this->occupancySeries($from, $to, $fromLocal, $toLocal, $roomsTotal, $occupied),
            'activity_series' => $this->activitySeries($from, $to, $granularity, $fromLocal, $toLocal),
            'access_summary' => $accessSummary,
            'anomaly_summary' => $anomalySummary,
            'device_summary' => $deviceSummary,
            'warehouse' => $warehouse,
            'recent_activity' => $this->recentActivity(10),
        ];

        if ($canSeeWorkers) {
            $payload['top_workers'] = $this->topWorkers($from, $to, 5);
        } else {
            $payload['top_workers'] = [];
        }
        $payload['top_rooms'] = $this->topRooms($from, $to, 5);

        return Response::json(200, $payload);
    }

    public function live(Request $request): Response
    {
        $offset = $this->tzOffset();
        $rooms = $this->rows(
            "SELECT r.id, r.code, r.status, r.pack_id,
                    rt.name AS room_type,
                    p.name AS pack_name,
                    i.door_state, i.presence_state,
                    i.last_door_event_at, i.last_presence_event_at,
                    (SELECT COUNT(*) FROM worker_sessions ws
                      WHERE ws.room_id = r.id AND ws.exited_at IS NULL) AS workers_inside
             FROM rooms r
             LEFT JOIN room_types rt ON rt.id = r.room_type_id
             LEFT JOIN device_packs p ON p.id = r.pack_id
             LEFT JOIN iot_sessions i ON i.room_id = r.id
             ORDER BY r.code"
        );

        $workersInside = $this->rows(
            "SELECT ws.id, ws.room_id, ws.entered_at,
                    w.name AS worker_name, wr.name AS role,
                    r.code AS room_code,
                    TIMESTAMPDIFF(MINUTE, ws.entered_at, UTC_TIMESTAMP()) AS duration_minutes
             FROM worker_sessions ws
             JOIN workers w ON w.id = ws.worker_id
             LEFT JOIN worker_roles wr ON wr.id = w.role_id
             JOIN rooms r ON r.id = ws.room_id
             WHERE ws.exited_at IS NULL
             ORDER BY ws.entered_at ASC"
        );

        $doorStale = max(1, Config::getInt('DOOR_STALE_SECONDS', 300) ?? 300);
        $presenceStale = max(1, Config::getInt('PRESENCE_STALE_SECONDS', 180) ?? 180);
        $now = time();

        foreach ($rooms as &$room) {
            $room['door_age_seconds'] = $this->ageSeconds($room['last_door_event_at'] ?? null, $now);
            $room['presence_age_seconds'] = $this->ageSeconds($room['last_presence_event_at'] ?? null, $now);
            $room['door_stale'] = $room['door_age_seconds'] !== null && $room['door_age_seconds'] > $doorStale;
            $room['presence_stale'] = $room['presence_age_seconds'] !== null && $room['presence_age_seconds'] > $presenceStale;
        }
        unset($room);

        $alerts = [];
        $anomaliesOpen = $this->rows(
            "SELECT severity, COUNT(*) AS c FROM anomalies WHERE status = 'OPEN' GROUP BY severity"
        );
        foreach ($anomaliesOpen as $a) {
            $alerts[] = [
                'type' => 'anomaly',
                'severity' => (string) $a['severity'],
                'text' => (int) $a['c'] . ' anomalía(s) ' . $a['severity'],
            ];
        }
        $doorOpen = 0;
        $presencePresent = 0;
        foreach ($rooms as $room) {
            if (($room['door_state'] ?? '') === 'OPEN') {
                $doorOpen++;
            }
            if (($room['presence_state'] ?? '') === 'PRESENT') {
                $presencePresent++;
            }
        }
        if ($doorOpen > 0) {
            $alerts[] = ['type' => 'door_open', 'severity' => 'MEDIUM', 'text' => $doorOpen . ' puerta(s) abierta(s)'];
        }

        return Response::json(200, [
            'generated_at' => gmdate('c'),
            'server_ts' => gmdate('c'),
            'server_epoch' => $now,
            'rooms' => $rooms,
            'workers_inside' => $workersInside,
            'alerts' => $alerts,
            'counts' => [
                'rooms' => count($rooms),
                'workers_inside' => count($workersInside),
                'door_open' => $doorOpen,
                'presence_present' => $presencePresent,
                'anomalies_open' => (int) $this->scalar("SELECT COUNT(*) FROM anomalies WHERE status = 'OPEN'"),
                'devices_offline' => (int) $this->scalar('SELECT COUNT(*) FROM devices WHERE online_state = 0'),
                'devices_unknown' => (int) $this->scalar('SELECT COUNT(*) FROM devices WHERE online_state IS NULL'),
            ],
        ]);
    }

    public function workers(Request $request): Response
    {
        [$from, $to, $days, $granularity, $fromLocal, $toLocal] = $this->range($request);
        $q = trim((string) ($request->query('q') ?? ''));
        $roleId = (int) ($request->query('role_id') ?? 0);
        $active = $request->query('active');

        $where = [];
        $params = [':from' => $from, ':to' => $to];
        if ($q !== '') {
            $where[] = 'w.name LIKE :q';
            $params[':q'] = '%' . $q . '%';
        }
        if ($roleId > 0) {
            $where[] = 'w.role_id = :role_id';
            $params[':role_id'] = $roleId;
        }
        if ($active !== null && $active !== '') {
            $where[] = 'w.active = :active';
            $params[':active'] = ($active === '1' || $active === 'true') ? 1 : 0;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $rows = $this->rows(
            "SELECT w.id, w.name, w.active, w.role_id, wr.name AS role,
                    COUNT(ws.id) AS sessions,
                    COALESCE(SUM(GREATEST(0, TIMESTAMPDIFF(MINUTE, ws.entered_at, COALESCE(ws.exited_at, UTC_TIMESTAMP())))), 0) AS minutes,
                    COUNT(DISTINCT ws.room_id) AS rooms_visited,
                    MAX(ws.entered_at) AS last_seen,
                    SUM(CASE WHEN ws.exited_at IS NULL THEN 1 ELSE 0 END) AS open_sessions
             FROM workers w
             LEFT JOIN worker_roles wr ON wr.id = w.role_id
             LEFT JOIN worker_sessions ws
                    ON ws.worker_id = w.id AND ws.entered_at BETWEEN :from AND :to
             {$whereSql}
             GROUP BY w.id, w.name, w.active, w.role_id, wr.name
             ORDER BY sessions DESC, w.name ASC",
            $params
        );

        $keyCounts = [];
        foreach ($this->rows(
            "SELECT worker_id, COUNT(*) AS c FROM access_keys
             WHERE status = 'ACTIVE' AND valid_until > UTC_TIMESTAMP() AND worker_id IS NOT NULL
             GROUP BY worker_id"
        ) as $row) {
            $keyCounts[(int) $row['worker_id']] = (int) $row['c'];
        }
        $denials = [];
        foreach ($this->rows(
            "SELECT worker_id, COUNT(*) AS c FROM key_access_events
             WHERE result = 'FAIL' AND occurred_at BETWEEN :from AND :to AND worker_id IS NOT NULL
             GROUP BY worker_id",
            [':from' => $from, ':to' => $to]
        ) as $row) {
            $denials[(int) $row['worker_id']] = (int) $row['c'];
        }

        $current = [];
        foreach ($this->rows(
            "SELECT ws.worker_id, r.code AS room_code
             FROM worker_sessions ws JOIN rooms r ON r.id = ws.room_id
             WHERE ws.exited_at IS NULL ORDER BY ws.entered_at ASC"
        ) as $row) {
            if (!isset($current[(int) $row['worker_id']])) {
                $current[(int) $row['worker_id']] = (string) $row['room_code'];
            }
        }

        foreach ($rows as &$row) {
            $id = (int) $row['id'];
            $row['id'] = $id;
            $row['sessions'] = (int) $row['sessions'];
            $row['minutes'] = (int) $row['minutes'];
            $row['rooms_visited'] = (int) $row['rooms_visited'];
            $row['open_sessions'] = (int) $row['open_sessions'];
            $row['active'] = (bool) $row['active'];
            $row['keys_active'] = $keyCounts[$id] ?? 0;
            $row['denials'] = $denials[$id] ?? 0;
            $row['current_room'] = $current[$id] ?? null;
            $row['status'] = $row['open_sessions'] > 0 ? 'INSIDE' : 'OUTSIDE';
        }
        unset($row);

        return Response::json(200, [
            'range' => ['from' => $fromLocal->format('Y-m-d'), 'to' => $toLocal->format('Y-m-d'), 'days' => $days],
            'items' => $rows,
            'meta' => ['total' => count($rows)],
        ]);
    }

    public function workerDetail(Request $request): Response
    {
        [$from, $to, $days, $granularity, $fromLocal, $toLocal] = $this->range($request);
        $id = (int) $request->routeParam('id');

        $workerRows = $this->rows(
            "SELECT w.id, w.name, w.active, w.role_id, wr.name AS role, w.notes
             FROM workers w LEFT JOIN worker_roles wr ON wr.id = w.role_id
             WHERE w.id = :id LIMIT 1",
            [':id' => $id]
        );
        if ($workerRows === []) {
            return Response::json(404, ['error' => ['code' => 'worker_not_found', 'message' => 'Worker not found']]);
        }
        $worker = $workerRows[0];
        $worker['id'] = (int) $worker['id'];
        $worker['active'] = (bool) $worker['active'];

        $sessions = $this->rows(
            "SELECT ws.id, ws.room_id, r.code AS room_code, ws.entered_at, ws.exited_at,
                    ws.exit_kind,
                    TIMESTAMPDIFF(MINUTE, ws.entered_at, COALESCE(ws.exited_at, UTC_TIMESTAMP())) AS duration_minutes
             FROM worker_sessions ws JOIN rooms r ON r.id = ws.room_id
             WHERE ws.worker_id = :id AND ws.entered_at BETWEEN :from AND :to
             ORDER BY ws.entered_at DESC LIMIT 200",
            [':id' => $id, ':from' => $from, ':to' => $to]
        );

        $perRoom = $this->rows(
            "SELECT ws.room_id, r.code AS room_code, COUNT(*) AS sessions,
                    COALESCE(SUM(GREATEST(0, TIMESTAMPDIFF(MINUTE, ws.entered_at, COALESCE(ws.exited_at, UTC_TIMESTAMP())))), 0) AS minutes
             FROM worker_sessions ws JOIN rooms r ON r.id = ws.room_id
             WHERE ws.worker_id = :id AND ws.entered_at BETWEEN :from AND :to
             GROUP BY ws.room_id, r.code ORDER BY minutes DESC, sessions DESC",
            [':id' => $id, ':from' => $from, ':to' => $to]
        );

        $daily = [];
        foreach ($this->rows(
            "SELECT DATE(CONVERT_TZ(entered_at, '+00:00', :tz)) AS d, COUNT(*) AS sessions,
                    COALESCE(SUM(GREATEST(0, TIMESTAMPDIFF(MINUTE, entered_at, COALESCE(exited_at, UTC_TIMESTAMP())))), 0) AS minutes
             FROM worker_sessions
             WHERE worker_id = :id AND entered_at BETWEEN :from AND :to
             GROUP BY d ORDER BY d",
            [':id' => $id, ':from' => $from, ':to' => $to, ':tz' => $this->tzOffset()]
        ) as $row) {
            $daily[(string) $row['d']] = [
                'sessions' => (int) $row['sessions'],
                'minutes' => (int) $row['minutes'],
            ];
        }

        $heat = [];
        foreach ($this->rows(
            "SELECT DAYOFWEEK(CONVERT_TZ(entered_at, '+00:00', :tz1)) AS dw,
                    HOUR(CONVERT_TZ(entered_at, '+00:00', :tz2)) AS h,
                    COUNT(*) AS c
             FROM worker_sessions
             WHERE worker_id = :id AND entered_at BETWEEN :from AND :to
             GROUP BY dw, h",
            [':id' => $id, ':from' => $from, ':to' => $to, ':tz1' => $this->tzOffset(), ':tz2' => $this->tzOffset()]
        ) as $row) {
            $heat[(int) $row['dw']][(int) $row['h']] = (int) $row['c'];
        }

        $keys = $this->rows(
            "SELECT k.id, k.label, k.key_type, k.status, k.valid_until,
                    (SELECT COUNT(*) FROM key_access_events e WHERE e.key_id = k.id AND e.occurred_at BETWEEN :from1 AND :to1) AS uses,
                    (SELECT COUNT(*) FROM key_access_events e WHERE e.key_id = k.id AND e.result = 'FAIL' AND e.occurred_at BETWEEN :from2 AND :to2) AS denials
             FROM access_keys k
             WHERE k.worker_id = :id
             ORDER BY k.status ASC, k.valid_until DESC",
            [':id' => $id, ':from1' => $from, ':to1' => $to, ':from2' => $from, ':to2' => $to]
        );

        $keyEvents = $this->rows(
            "SELECT e.id, e.occurred_at, e.credential_type, e.result, e.reason,
                    r.code AS room_code, k.label AS key_label
             FROM key_access_events e
             LEFT JOIN rooms r ON r.id = e.room_id
             LEFT JOIN access_keys k ON k.id = e.key_id
             WHERE e.worker_id = :id AND e.occurred_at BETWEEN :from AND :to
             ORDER BY e.occurred_at DESC LIMIT 25",
            [':id' => $id, ':from' => $from, ':to' => $to]
        );

        $visits = $this->rows(
            "SELECT v.id, v.room_id, r.code AS room_code, v.entry_trigger, v.outcome,
                    v.created_at, v.entered_at, v.exited_at,
                    (SELECT COUNT(*) FROM camera_recordings cr WHERE cr.visit_id = v.id) AS recordings
             FROM warehouse_visits v LEFT JOIN rooms r ON r.id = v.room_id
             WHERE v.worker_id = :id AND v.created_at BETWEEN :from AND :to
             ORDER BY v.created_at DESC LIMIT 50",
            [':id' => $id, ':from' => $from, ':to' => $to]
        );

        $totals = [
            'sessions' => count($sessions),
            'minutes' => array_sum(array_map(static fn($s) => (int) $s['duration_minutes'], $sessions)),
            'rooms_visited' => count($perRoom),
            'denials' => array_sum(array_map(static fn($e) => $e['result'] === 'FAIL' ? 1 : 0, $keyEvents)),
        ];

        return Response::json(200, [
            'range' => ['from' => $fromLocal->format('Y-m-d'), 'to' => $toLocal->format('Y-m-d'), 'days' => $days],
            'worker' => $worker,
            'totals' => $totals,
            'sessions' => $sessions,
            'per_room' => $perRoom,
            'daily' => $this->fillDaily($fromLocal, $days, $daily, ['sessions' => 0, 'minutes' => 0]),
            'heatmap' => $heat,
            'keys' => $keys,
            'key_events' => $keyEvents,
            'visits' => $visits,
            'current_room' => $this->currentRoomForWorker($id),
        ]);
    }

    public function rooms(Request $request): Response
    {
        [$from, $to, $days, $granularity, $fromLocal, $toLocal] = $this->range($request);
        $q = trim((string) ($request->query('q') ?? ''));
        $status = trim((string) ($request->query('status') ?? ''));
        $typeId = (int) ($request->query('room_type_id') ?? 0);

        $where = [];
        $params = [
            ':from1' => $from, ':to1' => $to,
            ':from2' => $from, ':to2' => $to,
            ':from3' => $from, ':to3' => $to,
            ':from4' => $from, ':to4' => $to,
            ':from5' => $from, ':to5' => $to,
        ];
        if ($q !== '') {
            $where[] = '(r.code LIKE :q1 OR rt.name LIKE :q2)';
            $params[':q1'] = '%' . $q . '%';
            $params[':q2'] = '%' . $q . '%';
        }
        if ($status !== '') {
            $where[] = 'r.status = :status';
            $params[':status'] = $status;
        }
        if ($typeId > 0) {
            $where[] = 'r.room_type_id = :type_id';
            $params[':type_id'] = $typeId;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $rows = $this->rows(
            "SELECT r.id, r.code, r.status, r.room_type_id, rt.name AS room_type,
                    r.pack_id, p.name AS pack_name,
                    (SELECT COUNT(*) FROM worker_sessions ws
                      WHERE ws.room_id = r.id AND ws.entered_at BETWEEN :from1 AND :to1) AS sessions,
                    (SELECT COUNT(*) FROM access_events ae
                      WHERE ae.room_id = r.id AND ae.occurred_at BETWEEN :from2 AND :to2) AS accesses,
                    (SELECT COUNT(*) FROM access_events ae
                      WHERE ae.room_id = r.id AND ae.result = 'FAIL' AND ae.occurred_at BETWEEN :from3 AND :to3) AS denied,
                    (SELECT COUNT(*) FROM anomalies a
                      WHERE a.room_id = r.id AND a.detected_at BETWEEN :from4 AND :to4) AS anomalies,
                    (SELECT COUNT(*) FROM warehouse_visits v
                      WHERE v.room_id = r.id AND v.created_at BETWEEN :from5 AND :to5) AS visits,
                    (SELECT MAX(ae.occurred_at) FROM access_events ae WHERE ae.room_id = r.id) AS last_access_at,
                    (SELECT COUNT(*) FROM worker_sessions ws
                      WHERE ws.room_id = r.id AND ws.exited_at IS NULL) AS workers_inside
             FROM rooms r
             LEFT JOIN room_types rt ON rt.id = r.room_type_id
             LEFT JOIN device_packs p ON p.id = r.pack_id
             {$whereSql}
             ORDER BY (sessions + accesses) DESC, r.code ASC",
            $params
        );

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['room_type_id'] = (int) $row['room_type_id'];
            $row['sessions'] = (int) $row['sessions'];
            $row['accesses'] = (int) $row['accesses'];
            $row['denied'] = (int) $row['denied'];
            $row['anomalies'] = (int) $row['anomalies'];
            $row['visits'] = (int) $row['visits'];
            $row['workers_inside'] = (int) $row['workers_inside'];
        }
        unset($row);

        return Response::json(200, [
            'range' => ['from' => $fromLocal->format('Y-m-d'), 'to' => $toLocal->format('Y-m-d'), 'days' => $days],
            'items' => $rows,
            'meta' => ['total' => count($rows)],
        ]);
    }

    public function roomDetail(Request $request): Response
    {
        [$from, $to, $days, $granularity, $fromLocal, $toLocal] = $this->range($request);
        $id = (int) $request->routeParam('id');

        $roomRows = $this->rows(
            "SELECT r.id, r.code, r.status, r.room_type_id, rt.name AS room_type,
                    r.pack_id, p.name AS pack_name, r.notes
             FROM rooms r
             LEFT JOIN room_types rt ON rt.id = r.room_type_id
             LEFT JOIN device_packs p ON p.id = r.pack_id
             WHERE r.id = :id LIMIT 1",
            [':id' => $id]
        );
        if ($roomRows === []) {
            return Response::json(404, ['error' => ['code' => 'room_not_found', 'message' => 'Room not found']]);
        }
        $room = $roomRows[0];
        $room['id'] = (int) $room['id'];

        $sessions = $this->rows(
            "SELECT ws.id, ws.worker_id, w.name AS worker_name, ws.entered_at, ws.exited_at, ws.exit_kind,
                    TIMESTAMPDIFF(MINUTE, ws.entered_at, COALESCE(ws.exited_at, UTC_TIMESTAMP())) AS duration_minutes
             FROM worker_sessions ws JOIN workers w ON w.id = ws.worker_id
             WHERE ws.room_id = :id AND ws.entered_at BETWEEN :from AND :to
             ORDER BY ws.entered_at DESC LIMIT 100",
            [':id' => $id, ':from' => $from, ':to' => $to]
        );

        $accesses = $this->rows(
            "SELECT id, occurred_at, kind, result, reason, provider
             FROM access_events WHERE room_id = :id AND occurred_at BETWEEN :from AND :to
             ORDER BY occurred_at DESC LIMIT 100",
            [':id' => $id, ':from' => $from, ':to' => $to]
        );

        $keyAccesses = $this->rows(
            "SELECT e.id, e.occurred_at, e.credential_type, e.result, e.reason,
                    k.label AS key_label, w.name AS worker_name, e.owner_type
             FROM key_access_events e
             LEFT JOIN access_keys k ON k.id = e.key_id
             LEFT JOIN workers w ON w.id = e.worker_id
             WHERE e.room_id = :id AND e.occurred_at BETWEEN :from AND :to
             ORDER BY e.occurred_at DESC LIMIT 100",
            [':id' => $id, ':from' => $from, ':to' => $to]
        );

        $stays = $this->rows(
            "SELECT id, status, duracion_minutos, reserved_at, first_entry_at, exit_detected_at, closed_at
             FROM stays WHERE room_id = :id AND reserved_at BETWEEN :from AND :to
             ORDER BY reserved_at DESC LIMIT 100",
            [':id' => $id, ':from' => $from, ':to' => $to]
        );

        $visits = $this->rows(
            "SELECT id, entry_trigger, outcome, qr_at, entered_at, exited_at, created_at
             FROM warehouse_visits WHERE room_id = :id AND created_at BETWEEN :from AND :to
             ORDER BY created_at DESC LIMIT 100",
            [':id' => $id, ':from' => $from, ':to' => $to]
        );

        $anomalies = $this->rows(
            "SELECT id, anomaly_type, severity, status, detected_at
             FROM anomalies WHERE room_id = :id AND detected_at BETWEEN :from AND :to
             ORDER BY detected_at DESC LIMIT 100",
            [':id' => $id, ':from' => $from, ':to' => $to]
        );

        $devices = $this->rows(
            "SELECT d.id, d.kind, d.subtype, d.external_id, d.label, d.battery_pct,
                    d.online_state, d.online_probed_at, d.transport, d.firmware_variant, d.firmware_version
             FROM devices d
             JOIN device_packs p ON p.id = d.pack_id
             JOIN rooms r ON r.pack_id = p.id
             WHERE r.id = :id
             ORDER BY d.kind ASC",
            [':id' => $id]
        );

        $daily = [];
        foreach ($this->rows(
            "SELECT DATE(CONVERT_TZ(occurred_at, '+00:00', :tz)) AS d, COUNT(*) AS c
             FROM access_events WHERE room_id = :id AND occurred_at BETWEEN :from AND :to
             GROUP BY d ORDER BY d",
            [':id' => $id, ':from' => $from, ':to' => $to, ':tz' => $this->tzOffset()]
        ) as $row) {
            $daily[(string) $row['d']]['accesses'] = (int) $row['c'];
        }
        foreach ($this->rows(
            "SELECT DATE(CONVERT_TZ(entered_at, '+00:00', :tz)) AS d, COUNT(*) AS c
             FROM worker_sessions WHERE room_id = :id AND entered_at BETWEEN :from AND :to
             GROUP BY d ORDER BY d",
            [':id' => $id, ':from' => $from, ':to' => $to, ':tz' => $this->tzOffset()]
        ) as $row) {
            $daily[(string) $row['d']]['sessions'] = (int) $row['c'];
        }

        return Response::json(200, [
            'range' => ['from' => $fromLocal->format('Y-m-d'), 'to' => $toLocal->format('Y-m-d'), 'days' => $days],
            'room' => $room,
            'sessions' => $sessions,
            'accesses' => $accesses,
            'key_accesses' => $keyAccesses,
            'stays' => $stays,
            'visits' => $visits,
            'anomalies' => $anomalies,
            'devices' => $devices,
            'daily' => $this->fillDaily($fromLocal, $days, $daily, ['sessions' => 0, 'accesses' => 0]),
        ]);
    }

    public function accesses(Request $request): Response
    {
        [$from, $to, $days, $granularity, $fromLocal, $toLocal] = $this->range($request);
        $offset = $this->tzOffset();

        // F121 — rango exacto opcional (hora local del panel → UTC).
        $tz  = new DateTimeZone(date_default_timezone_get());
        $utc = new DateTimeZone('UTC');
        foreach (['from', 'to'] as $bound) {
            $raw = trim((string) ($request->query($bound) ?? ''));
            if ($raw === '') {
                continue;
            }
            try {
                $dt = new DateTimeImmutable(str_replace(' ', 'T', $raw), $tz);
                if ($bound === 'from') {
                    $from = $dt->setTimezone($utc)->format('Y-m-d H:i:s');
                    $fromLocal = $dt;
                } else {
                    $to = $dt->setTimezone($utc)->format('Y-m-d H:i:s');
                    $toLocal = $dt;
                }
            } catch (\Throwable $e) {
                // Fecha inválida: se conserva el rango por días.
            }
        }

        // F121 — filtros aditivos (sala, tipo, trabajador, resultado).
        $roomQuery = $request->query('room_id');
        $roomId = $roomQuery !== null && $roomQuery !== '' ? (int) $roomQuery : null;
        $workerQuery = $request->query('worker_id');
        $workerId = $workerQuery !== null && $workerQuery !== '' ? (int) $workerQuery : null;
        $resultFilter = (string) ($request->query('result') ?? '');
        $resultFilter = in_array($resultFilter, ['OK', 'FAIL'], true) ? $resultFilter : null;

        $type = (string) ($request->query('type') ?? '');
        $credentialType = in_array($type, ['QR', 'NFC'], true) ? $type : null;
        $eventKind = in_array($type, [
            'QR_VALIDATE', 'OPEN', 'AUTO_LOCK', 'MANUAL_LOCK', 'DENIED', 'WORKER_EXIT',
        ], true) ? $type : null;

        // Una credencial solo existe en key_access_events y un evento de puerta
        // solo en access_events; sin tipo se consultan ambas ramas.
        $useAccess = $credentialType === null;
        $useKey    = $eventKind === null;

        $accessWhere  = ['e.occurred_at BETWEEN :ae_from AND :ae_to'];
        $accessParams = [':ae_from' => $from, ':ae_to' => $to];
        if ($roomId !== null) {
            $accessWhere[] = 'e.room_id = :ae_room';
            $accessParams[':ae_room'] = $roomId;
        }
        if ($eventKind !== null) {
            $accessWhere[] = 'e.kind = :ae_kind';
            $accessParams[':ae_kind'] = $eventKind;
        }
        if ($resultFilter !== null) {
            $accessWhere[] = 'e.result = :ae_result';
            $accessParams[':ae_result'] = $resultFilter;
        }
        if ($workerId !== null) {
            $accessWhere[] = 'ws.worker_id = :ae_worker';
            $accessParams[':ae_worker'] = $workerId;
        }
        $accessJoin = $workerId !== null
            ? ' LEFT JOIN worker_sessions ws ON ws.id = e.worker_session_id '
            : '';
        $accessWhereSql = implode(' AND ', $accessWhere);

        $keyWhere  = ['e.occurred_at BETWEEN :ke_from AND :ke_to'];
        $keyParams = [':ke_from' => $from, ':ke_to' => $to];
        if ($roomId !== null) {
            $keyWhere[] = 'e.room_id = :ke_room';
            $keyParams[':ke_room'] = $roomId;
        }
        if ($credentialType !== null) {
            $keyWhere[] = 'e.credential_type = :ke_credential';
            $keyParams[':ke_credential'] = $credentialType;
        }
        if ($resultFilter !== null) {
            $keyWhere[] = 'e.result = :ke_result';
            $keyParams[':ke_result'] = $resultFilter;
        }
        if ($workerId !== null) {
            $keyWhere[] = 'e.worker_id = :ke_worker';
            $keyParams[':ke_worker'] = $workerId;
        }
        $keyWhereSql = implode(' AND ', $keyWhere);

        $byResult    = [];
        $resultParts = [];
        $resultParams = [];
        if ($useAccess) {
            $resultParts[] = 'SELECT result FROM access_events e' . $accessJoin . ' WHERE ' . $accessWhereSql;
            $resultParams += $accessParams;
        }
        if ($useKey) {
            $resultParts[] = 'SELECT result FROM key_access_events e WHERE ' . $keyWhereSql;
            $resultParams += $keyParams;
        }
        if ($resultParts !== []) {
            foreach ($this->rows(
                'SELECT result, COUNT(*) AS c FROM (' . implode(' UNION ALL ', $resultParts) . ') x GROUP BY result',
                $resultParams
            ) as $row) {
                $byResult[(string) $row['result']] = ($byResult[(string) $row['result']] ?? 0) + (int) $row['c'];
            }
        }

        $byKind = $this->rows(
            "SELECT kind, result, COUNT(*) AS c FROM access_events
             WHERE occurred_at BETWEEN :from AND :to GROUP BY kind, result ORDER BY c DESC",
            [':from' => $from, ':to' => $to]
        );

        $byCredential = $this->rows(
            "SELECT credential_type, result, COUNT(*) AS c FROM key_access_events
             WHERE occurred_at BETWEEN :from AND :to GROUP BY credential_type, result",
            [':from' => $from, ':to' => $to]
        );

        $denialReasons = $this->rows(
            "SELECT reason, COUNT(*) AS c FROM (
                SELECT reason FROM access_events WHERE result = 'FAIL' AND occurred_at BETWEEN :from1 AND :to1
                UNION ALL
                SELECT reason FROM key_access_events WHERE result = 'FAIL' AND occurred_at BETWEEN :from2 AND :to2
             ) x GROUP BY reason ORDER BY c DESC LIMIT 10",
            [':from1' => $from, ':to1' => $to, ':from2' => $from, ':to2' => $to]
        );

        $topWorkers = $this->rows(
            "SELECT w.id, w.name, COUNT(*) AS c
             FROM key_access_events e JOIN workers w ON w.id = e.worker_id
             WHERE e.occurred_at BETWEEN :from AND :to AND e.worker_id IS NOT NULL
             GROUP BY w.id, w.name ORDER BY c DESC LIMIT 8",
            [':from' => $from, ':to' => $to]
        );

        $topKeys = $this->rows(
            "SELECT k.id, k.label, k.key_type, COUNT(*) AS c,
                    SUM(CASE WHEN e.result = 'FAIL' THEN 1 ELSE 0 END) AS denials
             FROM key_access_events e JOIN access_keys k ON k.id = e.key_id
             WHERE e.occurred_at BETWEEN :from AND :to AND e.key_id IS NOT NULL
             GROUP BY k.id, k.label, k.key_type ORDER BY c DESC LIMIT 8",
            [':from' => $from, ':to' => $to]
        );

        $series       = [];
        $bucket       = $granularity === 'hour' ? 'HOUR(CONVERT_TZ(occurred_at, \'+00:00\', %s))' : 'DATE(CONVERT_TZ(occurred_at, \'+00:00\', %s))';
        $seriesParts  = [];
        $seriesParams = [];
        if ($useAccess) {
            $seriesParts[] = 'SELECT ' . sprintf($bucket, ':tz_ae') . " AS k, 'accesses' AS src, COUNT(*) AS c
                FROM access_events e{$accessJoin} WHERE {$accessWhereSql} GROUP BY k";
            $seriesParams[':tz_ae'] = $offset;
            $seriesParams += $accessParams;
        }
        if ($useKey) {
            $seriesParts[] = 'SELECT ' . sprintf($bucket, ':tz_ke') . " AS k, 'keys' AS src, COUNT(*) AS c
                FROM key_access_events e WHERE {$keyWhereSql} GROUP BY k";
            $seriesParams[':tz_ke'] = $offset;
            $seriesParams += $keyParams;
        }
        if ($seriesParts !== []) {
            foreach ($this->rows(
                'SELECT k, src, SUM(c) AS c FROM (' . implode(' UNION ALL ', $seriesParts) . ') y GROUP BY k, src',
                $seriesParams
            ) as $row) {
                $series[(string) $row['k']][(string) $row['src']] = (int) $row['c'];
            }
        }

        $recentParts  = [];
        $recentParams = [];
        if ($useAccess) {
            $recentParts[] = "SELECT e.id, e.occurred_at, 'ACCESS' AS source, e.room_id AS room_id, r.code AS room_code,
                    e.kind AS type, e.result, e.reason, e.correlation_id AS correlation_id,
                    ws.worker_id AS worker_id, NULL AS worker_name, NULL AS key_label
                 FROM access_events e
                 JOIN rooms r ON r.id = e.room_id
                 LEFT JOIN worker_sessions ws ON ws.id = e.worker_session_id
                 WHERE {$accessWhereSql}";
            $recentParams += $accessParams;
        }
        if ($useKey) {
            $recentParts[] = "SELECT e.id, e.occurred_at, 'KEY' AS source, e.room_id AS room_id, r.code AS room_code,
                    e.credential_type AS type, e.result, e.reason, e.correlation_id AS correlation_id,
                    e.worker_id AS worker_id, w.name AS worker_name, k.label AS key_label
                 FROM key_access_events e
                 LEFT JOIN rooms r ON r.id = e.room_id
                 LEFT JOIN workers w ON w.id = e.worker_id
                 LEFT JOIN access_keys k ON k.id = e.key_id
                 WHERE {$keyWhereSql}";
            $recentParams += $keyParams;
        }
        $recent = $recentParts === [] ? [] : $this->rows(
            'SELECT * FROM (' . implode(' UNION ALL ', $recentParts) . ') x ORDER BY x.occurred_at DESC LIMIT 300',
            $recentParams
        );
        $recent = AccessMovementCollapser::collapse($recent);

        $total = array_sum($byResult);
        $ok = $byResult['OK'] ?? 0;
        $fail = $byResult['FAIL'] ?? 0;

        return Response::json(200, [
            'range' => ['from' => $fromLocal->format('Y-m-d'), 'to' => $toLocal->format('Y-m-d'), 'days' => $days],
            'summary' => [
                'total' => $total,
                'ok' => $ok,
                'fail' => $fail,
                'success_rate' => $total > 0 ? round($ok / $total * 100, 1) : 0.0,
            ],
            'by_result' => $byResult,
            'by_kind' => $byKind,
            'by_credential' => $byCredential,
            'denial_reasons' => $denialReasons,
            'top_workers' => $topWorkers,
            'top_keys' => $topKeys,
            'series' => $this->fillActivity($fromLocal, $days, $granularity, $series, ['accesses' => 0, 'keys' => 0]),
            'recent' => $recent,
        ]);
    }

    public function keys(Request $request): Response
    {
        [$from, $to, $days, $granularity, $fromLocal, $toLocal] = $this->range($request);

        $counts = ['active' => 0, 'expired' => 0, 'revoked' => 0, 'total' => 0];
        foreach ($this->rows('SELECT status, valid_until FROM access_keys') as $row) {
            $counts['total']++;
            if ((string) $row['status'] !== 'ACTIVE') {
                $counts['revoked']++;
            } elseif (strtotime((string) $row['valid_until']) !== false && strtotime((string) $row['valid_until']) < time()) {
                $counts['expired']++;
            } else {
                $counts['active']++;
            }
        }

        $expiring = $this->rows(
            "SELECT id, label, key_type, valid_until FROM access_keys
             WHERE status = 'ACTIVE' AND valid_until BETWEEN UTC_TIMESTAMP() AND DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY)
             ORDER BY valid_until ASC LIMIT 20"
        );

        $top = $this->rows(
            "SELECT k.id, k.label, k.key_type, k.owner_type,
                    COUNT(e.id) AS uses,
                    SUM(CASE WHEN e.result = 'FAIL' THEN 1 ELSE 0 END) AS denials
             FROM access_keys k LEFT JOIN key_access_events e
                    ON e.key_id = k.id AND e.occurred_at BETWEEN :from AND :to
             GROUP BY k.id, k.label, k.key_type, k.owner_type
             ORDER BY uses DESC, k.label ASC LIMIT 20",
            [':from' => $from, ':to' => $to]
        );

        $unused = $this->rows(
            "SELECT k.id, k.label, k.key_type, k.valid_until
             FROM access_keys k
             WHERE k.status = 'ACTIVE' AND k.valid_until > UTC_TIMESTAMP()
               AND NOT EXISTS (SELECT 1 FROM key_access_events e WHERE e.key_id = k.id AND e.occurred_at BETWEEN :from AND :to)
             ORDER BY k.valid_until ASC LIMIT 20",
            [':from' => $from, ':to' => $to]
        );

        $nfc = ['available' => 0, 'assigned' => 0, 'disabled' => 0, 'total' => 0];
        foreach ($this->rows('SELECT status, COUNT(*) AS c FROM nfc_cards GROUP BY status') as $row) {
            $nfc['total'] += (int) $row['c'];
            $nfc[strtolower((string) $row['status'])] = (int) $row['c'];
        }

        return Response::json(200, [
            'range' => ['from' => $fromLocal->format('Y-m-d'), 'to' => $toLocal->format('Y-m-d'), 'days' => $days],
            'counts' => $counts,
            'expiring_soon' => $expiring,
            'top_keys' => $top,
            'unused_keys' => $unused,
            'nfc' => $nfc,
        ]);
    }

    public function devices(Request $request): Response
    {
        $summary = $this->deviceSummary();

        $byKind = $this->rows(
            'SELECT kind, COUNT(*) AS c,
                    SUM(CASE WHEN online_state = 1 THEN 1 ELSE 0 END) AS online,
                    SUM(CASE WHEN online_state = 0 THEN 1 ELSE 0 END) AS offline,
                    SUM(CASE WHEN online_state IS NULL THEN 1 ELSE 0 END) AS unknown
             FROM devices GROUP BY kind ORDER BY c DESC'
        );
        $byTransport = $this->rows(
            "SELECT COALESCE(transport, 'UNKNOWN') AS transport, COUNT(*) AS c
             FROM devices GROUP BY transport ORDER BY c DESC"
        );
        $lowBattery = $this->rows(
            'SELECT d.id, d.kind, d.label, d.external_id, d.battery_pct, p.name AS pack_name, r.code AS room_code
             FROM devices d
             LEFT JOIN device_packs p ON p.id = d.pack_id
             LEFT JOIN rooms r ON r.pack_id = p.id
             WHERE d.battery_pct IS NOT NULL AND d.battery_pct <= 20
             ORDER BY d.battery_pct ASC LIMIT 30'
        );
        $packs = $this->rows(
            'SELECT p.id, p.name, p.code, r.code AS room_code, COUNT(d.id) AS devices
             FROM device_packs p
             LEFT JOIN rooms r ON r.pack_id = p.id
             LEFT JOIN devices d ON d.pack_id = p.id
             GROUP BY p.id, p.name, p.code, r.code
             ORDER BY devices DESC, p.name ASC'
        );
        $firmware = $this->rows(
            "SELECT COALESCE(firmware_variant, '—') AS variant, COALESCE(firmware_version, '—') AS version,
                    COUNT(*) AS c
             FROM devices WHERE kind = 'RPI'
             GROUP BY firmware_variant, firmware_version ORDER BY c DESC"
        );

        return Response::json(200, [
            'summary' => $summary,
            'by_kind' => $byKind,
            'by_transport' => $byTransport,
            'low_battery' => $lowBattery,
            'packs' => $packs,
            'firmware' => $firmware,
            'pipelines' => $this->pipelineService()->all(),
        ]);
    }

    public function anomalies(Request $request): Response
    {
        [$from, $to, $days, $granularity, $fromLocal, $toLocal] = $this->range($request);
        $offset = $this->tzOffset();

        $byStatus = [];
        foreach ($this->rows(
            'SELECT status, COUNT(*) AS c FROM anomalies WHERE detected_at BETWEEN :from AND :to GROUP BY status',
            [':from' => $from, ':to' => $to]
        ) as $row) {
            $byStatus[(string) $row['status']] = (int) $row['c'];
        }
        $bySeverity = [];
        foreach ($this->rows(
            'SELECT severity, COUNT(*) AS c FROM anomalies WHERE detected_at BETWEEN :from AND :to GROUP BY severity',
            [':from' => $from, ':to' => $to]
        ) as $row) {
            $bySeverity[(string) $row['severity']] = (int) $row['c'];
        }
        $byType = $this->rows(
            'SELECT anomaly_type, severity, COUNT(*) AS c FROM anomalies
             WHERE detected_at BETWEEN :from AND :to GROUP BY anomaly_type, severity ORDER BY c DESC',
            [':from' => $from, ':to' => $to]
        );
        $topRooms = $this->rows(
            'SELECT a.room_id, r.code AS room_code, COUNT(*) AS c,
                    SUM(CASE WHEN a.severity = "CRITICAL" THEN 1 ELSE 0 END) AS critical
             FROM anomalies a JOIN rooms r ON r.id = a.room_id
             WHERE a.detected_at BETWEEN :from AND :to
             GROUP BY a.room_id, r.code ORDER BY c DESC LIMIT 10',
            [':from' => $from, ':to' => $to]
        );
        $mtta = $this->scalar(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, detected_at, acknowledged_at))
             FROM anomalies WHERE acknowledged_at IS NOT NULL AND detected_at BETWEEN :from AND :to',
            [':from' => $from, ':to' => $to]
        );
        $series = [];
        foreach ($this->rows(
            "SELECT DATE(CONVERT_TZ(detected_at, '+00:00', :tz)) AS d, COUNT(*) AS c
             FROM anomalies WHERE detected_at BETWEEN :from AND :to GROUP BY d ORDER BY d",
            [':from' => $from, ':to' => $to, ':tz' => $offset]
        ) as $row) {
            $series[(string) $row['d']] = (int) $row['c'];
        }
        $recent = $this->rows(
            'SELECT a.id, a.room_id, r.code AS room_code, a.anomaly_type, a.severity, a.status,
                    a.detected_at, a.acknowledged_at
             FROM anomalies a JOIN rooms r ON r.id = a.room_id
             WHERE a.detected_at BETWEEN :from AND :to
             ORDER BY a.detected_at DESC LIMIT 100',
            [':from' => $from, ':to' => $to]
        );

        return Response::json(200, [
            'range' => ['from' => $fromLocal->format('Y-m-d'), 'to' => $toLocal->format('Y-m-d'), 'days' => $days],
            'by_status' => $byStatus,
            'by_severity' => $bySeverity,
            'by_type' => $byType,
            'top_rooms' => $topRooms,
            'mtta_minutes' => $mtta !== null ? (int) round((float) $mtta) : null,
            'series' => $this->fillDaily($fromLocal, $days, $series, 0),
            'recent' => $recent,
        ]);
    }

    public function occupations(Request $request): Response
    {
        [$from, $to, $days, $granularity, $fromLocal, $toLocal] = $this->range($request);
        $kind   = strtolower(trim((string) ($request->query('kind') ?? '')));
        $status = trim((string) ($request->query('status') ?? ''));
        $roomId = (int) ($request->query('room_id') ?? 0);
        $q      = trim((string) ($request->query('q') ?? ''));

        $sql = "SELECT * FROM (
            SELECT 'stay' AS kind, s.id AS id, s.room_id, r.code AS room_code,
                   rt.name AS room_type, s.status AS status,
                   NULL AS worker_id, NULL AS worker_name,
                   s.duracion_minutos AS reserved_minutes,
                   CASE WHEN s.first_entry_at IS NULL THEN NULL
                        ELSE TIMESTAMPDIFF(MINUTE, s.first_entry_at,
                             COALESCE(s.closed_at, s.exit_detected_at, UTC_TIMESTAMP(3))) END AS real_minutes,
                   s.reserved_at AS reserved_at,
                   s.first_entry_at AS entered_at,
                   COALESCE(s.exit_detected_at, s.closed_at) AS exited_at,
                   s.closed_at AS closed_at,
                   CASE WHEN EXISTS (SELECT 1 FROM qr_credentials q WHERE q.stay_id = s.id)
                        THEN 'QR_STAY' ELSE 'UNKNOWN' END AS access_medium,
                   COALESCE(s.first_entry_at, s.reserved_at, s.created_at) AS sort_at
            FROM stays s
            LEFT JOIN rooms r ON r.id = s.room_id
            LEFT JOIN room_types rt ON rt.id = r.room_type_id
            WHERE COALESCE(s.first_entry_at, s.reserved_at, s.created_at) BETWEEN :from1 AND :to1
            UNION ALL
            SELECT 'worker' AS kind, ws.id AS id, ws.room_id, r.code AS room_code,
                   rt.name AS room_type,
                   CASE WHEN ws.exited_at IS NULL THEN 'INSIDE' ELSE 'OUTSIDE' END AS status,
                   w.id AS worker_id, w.name AS worker_name,
                   NULL AS reserved_minutes,
                   TIMESTAMPDIFF(MINUTE, ws.entered_at, COALESCE(ws.exited_at, UTC_TIMESTAMP(3))) AS real_minutes,
                   NULL AS reserved_at,
                   ws.entered_at AS entered_at,
                   ws.exited_at AS exited_at,
                   ws.exited_at AS closed_at,
                   CASE
                       WHEN EXISTS (SELECT 1 FROM key_access_events k WHERE k.worker_session_id = ws.id AND k.result = 'OK' AND k.credential_type = 'NFC') THEN 'NFC'
                       WHEN EXISTS (SELECT 1 FROM key_access_events k WHERE k.worker_session_id = ws.id AND k.result = 'OK' AND k.credential_type = 'QR') THEN 'QR_KEY'
                       WHEN EXISTS (SELECT 1 FROM access_events ae WHERE ae.worker_session_id = ws.id) THEN 'WORKER_QR'
                       ELSE 'UNKNOWN' END AS access_medium,
                   ws.entered_at AS sort_at
            FROM worker_sessions ws
            JOIN workers w ON w.id = ws.worker_id
            LEFT JOIN rooms r ON r.id = ws.room_id
            LEFT JOIN room_types rt ON rt.id = r.room_type_id
            WHERE ws.entered_at BETWEEN :from2 AND :to2
        ) x WHERE 1=1";

        $params = [':from1' => $from, ':to1' => $to, ':from2' => $from, ':to2' => $to];
        if ($kind === 'stay' || $kind === 'worker') {
            $sql .= ' AND x.kind = :kind';
            $params[':kind'] = $kind;
        }
        if ($status !== '') {
            $sql .= ' AND x.status = :status';
            $params[':status'] = $status;
        }
        if ($roomId > 0) {
            $sql .= ' AND x.room_id = :room_id';
            $params[':room_id'] = $roomId;
        }
        if ($q !== '') {
            $sql .= ' AND (x.room_code LIKE :q1 OR x.worker_name LIKE :q2)';
            $params[':q1'] = '%' . $q . '%';
            $params[':q2'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY x.sort_at DESC LIMIT 300';

        $items = $this->rows($sql, $params);
        $summary = ['total' => count($items), 'stays' => 0, 'workers' => 0, 'inside' => 0, 'overstay' => 0];
        foreach ($items as $it) {
            if ($it['kind'] === 'stay') { $summary['stays']++; } else { $summary['workers']++; }
            if ($it['status'] === 'INSIDE') { $summary['inside']++; }
            if ($it['status'] === 'OVERSTAY') { $summary['overstay']++; }
        }

        return Response::json(200, [
            'range' => ['from' => $fromLocal->format('Y-m-d'), 'to' => $toLocal->format('Y-m-d'), 'days' => $days],
            'items' => $items,
            'summary' => $summary,
        ]);
    }

    public function occupationDetail(Request $request): Response
    {
        $kind = strtolower((string) $request->routeParam('kind'));
        $id   = (int) $request->routeParam('id');
        $timeline = [];

        if ($kind === 'stay') {
            $occupation = $this->row(
                'SELECT s.*, r.code AS room_code, rt.name AS room_type
                 FROM stays s
                 LEFT JOIN rooms r ON r.id = s.room_id
                 LEFT JOIN room_types rt ON rt.id = r.room_type_id
                 WHERE s.id = :id LIMIT 1',
                [':id' => $id]
            );
            if ($occupation === null) {
                return Response::json(404, ['error' => ['code' => 'occupation_not_found', 'message' => 'Stay not found']]);
            }
            $qr = $this->rows(
                'SELECT id, jti, issued_at, expires_at, first_used_at, valid_until, consumed_at, revoked_at
                 FROM qr_credentials WHERE stay_id = :id ORDER BY issued_at DESC',
                [':id' => $id]
            );
            $debts = $this->rows(
                'SELECT id, exceso_minutos, amount_eur_snapshot, status, attempts, last_error, created_at
                 FROM debts WHERE stay_id = :id ORDER BY created_at DESC',
                [':id' => $id]
            );
            $accesses = $this->rows(
                'SELECT id, kind, result, reason, provider, occurred_at, correlation_id
                 FROM access_events WHERE stay_id = :id ORDER BY occurred_at DESC LIMIT 100',
                [':id' => $id]
            );
            $anomalies = $this->rows(
                'SELECT id, anomaly_type, severity, status, detected_at
                 FROM anomalies WHERE stay_id = :id ORDER BY detected_at DESC LIMIT 50',
                [':id' => $id]
            );
            foreach ($qr as $row) { $timeline[] = ['at' => $row['issued_at'], 'kind' => 'qr', 'label' => 'QR emitido', 'detail' => substr((string) $row['jti'], 0, 8)]; }
            foreach ($accesses as $row) { $timeline[] = ['at' => $row['occurred_at'], 'kind' => 'access', 'label' => $row['kind'], 'detail' => $row['result'] . ($row['reason'] ? ' · ' . $row['reason'] : '')]; }
            foreach ($debts as $row) { $timeline[] = ['at' => $row['created_at'], 'kind' => 'debt', 'label' => 'Deuda', 'detail' => $row['exceso_minutos'] . ' min · ' . $row['status']]; }
            foreach ($anomalies as $row) { $timeline[] = ['at' => $row['detected_at'], 'kind' => 'anomaly', 'label' => 'Anomalía ' . $row['anomaly_type'], 'detail' => $row['severity'] . ' · ' . $row['status']]; }
            $occupation['real_minutes'] = $occupation['first_entry_at'] === null ? null
                : max(0, (int) round((strtotime((string) ($occupation['closed_at'] ?? $occupation['exit_detected_at'] ?? 'now')) - strtotime((string) $occupation['first_entry_at'])) / 60));
            return Response::json(200, [
                'kind' => 'stay', 'occupation' => $occupation, 'qr' => $qr,
                'debts' => $debts, 'accesses' => $accesses, 'anomalies' => $anomalies,
                'timeline' => $this->sortTimeline($timeline),
            ]);
        }

        if ($kind === 'worker') {
            $occupation = $this->row(
                'SELECT ws.*, w.name AS worker_name, r.code AS room_code, rt.name AS room_type
                 FROM worker_sessions ws
                 JOIN workers w ON w.id = ws.worker_id
                 LEFT JOIN rooms r ON r.id = ws.room_id
                 LEFT JOIN room_types rt ON rt.id = r.room_type_id
                 WHERE ws.id = :id LIMIT 1',
                [':id' => $id]
            );
            if ($occupation === null) {
                return Response::json(404, ['error' => ['code' => 'occupation_not_found', 'message' => 'Worker session not found']]);
            }
            $keys = $this->rows(
                'SELECT id, key_id, credential_type, result, reason, occurred_at
                 FROM key_access_events WHERE worker_session_id = :id ORDER BY occurred_at DESC LIMIT 100',
                [':id' => $id]
            );
            $accesses = $this->rows(
                'SELECT id, kind, result, reason, provider, occurred_at
                 FROM access_events WHERE worker_session_id = :id ORDER BY occurred_at DESC LIMIT 100',
                [':id' => $id]
            );
            $visits = $this->rows(
                'SELECT id, entry_trigger, outcome, created_at, entered_at, exited_at
                 FROM warehouse_visits WHERE worker_session_id = :id ORDER BY created_at DESC LIMIT 50',
                [':id' => $id]
            );
            foreach ($accesses as $row) { $timeline[] = ['at' => $row['occurred_at'], 'kind' => 'access', 'label' => $row['kind'], 'detail' => $row['result']]; }
            foreach ($keys as $row) { $timeline[] = ['at' => $row['occurred_at'], 'kind' => 'key', 'label' => 'Llave ' . $row['credential_type'], 'detail' => $row['result'] . ($row['reason'] ? ' · ' . $row['reason'] : '')]; }
            foreach ($visits as $row) { $timeline[] = ['at' => $row['created_at'], 'kind' => 'visit', 'label' => 'Visita ' . $row['entry_trigger'], 'detail' => $row['outcome']]; }
            $occupation['real_minutes'] = max(0, (int) round((strtotime((string) ($occupation['exited_at'] ?? 'now')) - strtotime((string) $occupation['entered_at'])) / 60));
            return Response::json(200, [
                'kind' => 'worker', 'occupation' => $occupation, 'keys' => $keys,
                'accesses' => $accesses, 'visits' => $visits,
                'timeline' => $this->sortTimeline($timeline),
            ]);
        }

        return Response::json(400, ['error' => ['code' => 'invalid_kind', 'message' => 'kind must be stay or worker']]);
    }

    /** @param list<array<string,mixed>> $items */
    private function sortTimeline(array $items): array
    {
        usort($items, static fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        return array_slice($items, 0, 100);
    }

    public function warehouse(Request $request): Response
    {
        [$from, $to, $days, $granularity, $fromLocal, $toLocal] = $this->range($request);
        $offset  = $this->tzOffset();
        $roomId  = (int) ($request->query('room_id') ?? 0);
        $roomSql = $roomId > 0 ? ' AND room_id = :room_id' : '';
        $vRoomSql = $roomId > 0 ? ' AND v.room_id = :room_id' : '';
        $base    = [':from' => $from, ':to' => $to];
        $params  = $base + ($roomId > 0 ? [':room_id' => $roomId] : []);

        $byTrigger = $this->rows(
            'SELECT entry_trigger, COUNT(*) AS c FROM warehouse_visits
             WHERE created_at BETWEEN :from AND :to' . $roomSql . '
             GROUP BY entry_trigger ORDER BY c DESC',
            $params
        );
        $byOutcome = $this->rows(
            'SELECT outcome, COUNT(*) AS c FROM warehouse_visits
             WHERE created_at BETWEEN :from AND :to' . $roomSql . '
             GROUP BY outcome ORDER BY c DESC',
            $params
        );
        $topWorkers = $this->rows(
            'SELECT w.id, w.name, COUNT(*) AS visits,
                    SUM(CASE WHEN v.outcome = "ENTERED" THEN 1 ELSE 0 END) AS entered
             FROM warehouse_visits v JOIN workers w ON w.id = v.worker_id
             WHERE v.created_at BETWEEN :from AND :to AND v.worker_id IS NOT NULL' . $vRoomSql . '
             GROUP BY w.id, w.name ORDER BY visits DESC LIMIT 10',
            $params
        );

        $visitSummary = $this->row(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN outcome = "ENTERED" THEN 1 ELSE 0 END) AS entered,
                    SUM(CASE WHEN outcome = "NO_SHOW" THEN 1 ELSE 0 END) AS no_show,
                    SUM(CASE WHEN outcome = "NOISE" THEN 1 ELSE 0 END) AS noise,
                    AVG(CASE WHEN entered_at IS NOT NULL AND exited_at IS NOT NULL
                        THEN TIMESTAMPDIFF(SECOND, entered_at, exited_at) END) AS avg_seconds
             FROM warehouse_visits
             WHERE created_at BETWEEN :from AND :to' . $roomSql,
            $params
        ) ?: [];

        $recordings = [];
        foreach ($this->rows(
            'SELECT status, COUNT(*) AS c, COALESCE(SUM(size_bytes), 0) AS bytes,
                    COALESCE(SUM(duration_s), 0) AS seconds
             FROM camera_recordings WHERE requested_at BETWEEN :from AND :to' . $roomSql . '
             GROUP BY status',
            $params
        ) as $row) {
            $recordings[(string) $row['status']] = [
                'count' => (int) $row['c'],
                'bytes' => (int) $row['bytes'],
                'seconds' => (int) $row['seconds'],
            ];
        }

        $series = [];
        foreach ($this->rows(
            'SELECT DATE(CONVERT_TZ(created_at, \'+00:00\', :tz)) AS d, COUNT(*) AS c
             FROM warehouse_visits WHERE created_at BETWEEN :from AND :to' . $roomSql . '
             GROUP BY d ORDER BY d',
            $params + [':tz' => $offset]
        ) as $row) {
            $series[(string) $row['d']] = (int) $row['c'];
        }

        $recent = $this->rows(
            'SELECT v.id, v.room_id, r.code AS room_code, v.worker_id, w.name AS worker_name,
                    v.worker_session_id, v.entry_trigger, v.outcome, v.created_at,
                    v.entered_at, v.exited_at,
                    (SELECT COUNT(*) FROM camera_recordings cr WHERE cr.visit_id = v.id) AS recordings,
                    (SELECT COALESCE(SUM(cr.size_bytes), 0) FROM camera_recordings cr WHERE cr.visit_id = v.id) AS recording_bytes
             FROM warehouse_visits v
             LEFT JOIN rooms r ON r.id = v.room_id
             LEFT JOIN workers w ON w.id = v.worker_id
             WHERE v.created_at BETWEEN :from AND :to' . $vRoomSql . '
             ORDER BY v.created_at DESC LIMIT 100',
            $params
        );

        $rooms = $this->rows(
            "SELECT r.id, r.code, rt.name AS room_type
             FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
             WHERE rt.code IN ('ALMACEN_BEBIDAS', 'TIMBRADO')
             ORDER BY r.code ASC"
        );

        $cameraParams = $roomId > 0 ? [':room_id' => $roomId] : [];
        $cameraSql = $roomId > 0
            ? "SELECT COUNT(*) AS total,
                      SUM(CASE WHEN d.online_state = 1 THEN 1 ELSE 0 END) AS online,
                      SUM(CASE WHEN d.online_state = 0 THEN 1 ELSE 0 END) AS offline,
                      SUM(CASE WHEN d.online_state IS NULL THEN 1 ELSE 0 END) AS unknown
               FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
               WHERE d.kind = 'CAMERA' AND r.id = :room_id"
            : "SELECT COUNT(*) AS total,
                      SUM(CASE WHEN online_state = 1 THEN 1 ELSE 0 END) AS online,
                      SUM(CASE WHEN online_state = 0 THEN 1 ELSE 0 END) AS offline,
                      SUM(CASE WHEN online_state IS NULL THEN 1 ELSE 0 END) AS unknown
               FROM devices WHERE kind = 'CAMERA'";
        $cameras = $this->row($cameraSql, $cameraParams) ?: [];

        return Response::json(200, [
            'range' => ['from' => $fromLocal->format('Y-m-d'), 'to' => $toLocal->format('Y-m-d'), 'days' => $days],
            'room_id' => $roomId ?: null,
            'rooms' => $rooms,
            'cameras' => [
                'total' => (int) ($cameras['total'] ?? 0),
                'online' => (int) ($cameras['online'] ?? 0),
                'offline' => (int) ($cameras['offline'] ?? 0),
                'unknown' => (int) ($cameras['unknown'] ?? 0),
            ],
            'visit_summary' => [
                'total' => (int) ($visitSummary['total'] ?? 0),
                'entered' => (int) ($visitSummary['entered'] ?? 0),
                'no_show' => (int) ($visitSummary['no_show'] ?? 0),
                'noise' => (int) ($visitSummary['noise'] ?? 0),
                'avg_seconds' => $visitSummary['avg_seconds'] === null ? null : (int) round((float) $visitSummary['avg_seconds']),
            ],
            'by_trigger' => $byTrigger,
            'by_outcome' => $byOutcome,
            'top_workers' => $topWorkers,
            'recordings' => $recordings,
            'series' => $this->fillDaily($fromLocal, $days, $series, 0),
            'recent' => $recent,
        ]);
    }

    public function warehouseVisitDetail(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $visit = $this->row(
            'SELECT v.*, r.code AS room_code, rt.name AS room_type, w.name AS worker_name
             FROM warehouse_visits v
             LEFT JOIN rooms r ON r.id = v.room_id
             LEFT JOIN room_types rt ON rt.id = r.room_type_id
             LEFT JOIN workers w ON w.id = v.worker_id
             WHERE v.id = :id LIMIT 1',
            [':id' => $id]
        );
        if ($visit === null) {
            return Response::json(404, ['error' => ['code' => 'visit_not_found', 'message' => 'Visit not found']]);
        }
        $recordings = $this->rows(
            'SELECT cr.id, cr.device_id, cr.position, cr.episode, cr.`trigger`, cr.status,
                    cr.requested_at, cr.started_at, cr.stopped_at, cr.duration_s, cr.size_bytes,
                    cr.error, d.label AS device_label
             FROM camera_recordings cr
             LEFT JOIN devices d ON d.id = cr.device_id
             WHERE cr.visit_id = :id ORDER BY cr.requested_at ASC',
            [':id' => $id]
        );
        return Response::json(200, ['visit' => $visit, 'recordings' => $recordings]);
    }

    public function warehouseLogs(Request $request): Response
    {
        [$from, $to, $days, $granularity, $fromLocal, $toLocal] = $this->range($request);
        $roomId = (int) ($request->query('room_id') ?? 0);
        $kind   = strtolower(trim((string) ($request->query('kind') ?? '')));
        $applied = trim((string) ($request->query('applied') ?? ''));

        $items = [];
        if ($kind === '' || $kind === 'door' || $kind === 'presence') {
            $where = ['pe.occurred_at BETWEEN :from AND :to'];
            $params = [':from' => $from, ':to' => $to];
            if ($roomId > 0) { $where[] = 'pe.room_id = :room_id'; $params[':room_id'] = $roomId; }
            if ($kind === 'door') { $where[] = "pe.sensor = 'PROXIMITY'"; }
            if ($kind === 'presence') { $where[] = "pe.sensor = 'PRESENCE'"; }
            if ($applied === '1' || $applied === '0') {
                $where[] = 'pe.applied = :applied';
                $params[':applied'] = (int) $applied;
            }
            foreach ($this->rows(
                'SELECT pe.id, pe.room_id, r.code AS room_code, pe.sensor, pe.value,
                        pe.applied, pe.discard_reason, pe.occurred_at, pe.received_at,
                        pe.provider, pe.meta_json
                 FROM presence_events pe LEFT JOIN rooms r ON r.id = pe.room_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY pe.occurred_at DESC LIMIT 300',
                $params
            ) as $row) {
                $meta = [];
                if (!empty($row['meta_json'])) {
                    $decoded = json_decode((string) $row['meta_json'], true);
                    $meta = is_array($decoded) ? $decoded : [];
                }
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'sensor',
                    'kind' => $row['sensor'] === 'PROXIMITY' ? 'door' : 'presence',
                    'label' => $row['sensor'] === 'PROXIMITY'
                        ? ('Puerta ' . strtolower((string) $row['value']))
                        : ('Presencia ' . strtolower((string) $row['value'])),
                    'value' => $row['value'],
                    'applied' => $row['applied'] === null ? null : (bool) $row['applied'],
                    'discard_reason' => $row['discard_reason'],
                    'room_id' => (int) $row['room_id'],
                    'room_code' => $row['room_code'],
                    'provider' => $row['provider'],
                    'occurred_at' => $row['occurred_at'],
                    'source' => $meta['source'] ?? null,
                ];
            }
        }

        if ($kind === '' || $kind === 'light') {
            $where = ['sce.requested_at BETWEEN :from AND :to'];
            $params = [':from' => $from, ':to' => $to];
            if ($roomId > 0) { $where[] = 'sce.room_id = :room_id'; $params[':room_id'] = $roomId; }
            if ($applied === '1') { $where[] = 'sce.publish_ok = 1'; }
            if ($applied === '0') { $where[] = '(sce.publish_ok = 0 OR sce.publish_ok IS NULL)'; }
            foreach ($this->rows(
                'SELECT sce.id, sce.room_id, r.code AS room_code, sce.action, sce.publish_ok,
                        sce.verdict, sce.diagnosis, sce.requested_at, sce.published_at,
                        sce.reported_state, sce.reported_at, sce.origin
                 FROM switch_command_evidence sce LEFT JOIN rooms r ON r.id = sce.room_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY sce.requested_at DESC LIMIT 200',
                $params
            ) as $row) {
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => 'light',
                    'kind' => 'light',
                    'label' => 'Luz ' . ($row['action'] === 'on' ? 'encendida' : 'apagada'),
                    'value' => $row['action'],
                    'applied' => $row['publish_ok'] === null ? null : (bool) $row['publish_ok'],
                    'discard_reason' => $row['diagnosis'],
                    'room_id' => (int) $row['room_id'],
                    'room_code' => $row['room_code'],
                    'provider' => $row['origin'],
                    'occurred_at' => $row['requested_at'],
                    'source' => $row['verdict'],
                ];
            }
        }

        usort($items, static fn ($a, $b) => strcmp((string) $b['occurred_at'], (string) $a['occurred_at']));
        $items = array_slice($items, 0, 400);

        return Response::json(200, [
            'range' => ['from' => $fromLocal->format('Y-m-d'), 'to' => $toLocal->format('Y-m-d'), 'days' => $days],
            'items' => $items,
            'meta' => ['count' => count($items)],
        ]);
    }

    /**
     * F129 — Registro de eventos unificado (solo lectura).
     *
     * Cronología de sensores (puerta/presencia), accesos de puerta, accesos
     * con llave y órdenes de luz. Sin tabla nueva: UNION ALL en tiempo de
     * consulta sobre las tablas auditadas. Sin Tuya, sin pollers, sin escrituras.
     *
     * Filtros: days | from/to (hora local) | room_id | category | type |
     * result | applied | worker_id | key_id | device_id | q | since |
     * before_at+before_source+before_id | limit.
     */
    public function events(Request $request): Response
    {
        [$from, $to, $days, , $fromLocal, $toLocal] = $this->range($request);

        // Rango exacto opcional (hora local del panel → UTC).
        $tz  = new DateTimeZone(date_default_timezone_get());
        $utc = new DateTimeZone('UTC');
        foreach (['from', 'to'] as $bound) {
            $raw = trim((string) ($request->query($bound) ?? ''));
            if ($raw === '') {
                continue;
            }
            try {
                $dt = new DateTimeImmutable(str_replace(' ', 'T', $raw), $tz);
                if ($bound === 'from') {
                    $from = $dt->setTimezone($utc)->format('Y-m-d H:i:s');
                    $fromLocal = $dt;
                } else {
                    $to = $dt->setTimezone($utc)->format('Y-m-d H:i:s');
                    $toLocal = $dt;
                }
            } catch (\Throwable $e) {
                // Fecha inválida: se conserva el rango por días.
            }
        }

        $allowedCategories = ['door', 'presence', 'access', 'key', 'light'];
        $category = strtolower(trim((string) ($request->query('category') ?? '')));
        $category = in_array($category, $allowedCategories, true) ? $category : null;

        $type = strtoupper(trim((string) ($request->query('type') ?? '')));
        $type = $type !== '' ? $type : null;

        $resultFilter = strtoupper(trim((string) ($request->query('result') ?? '')));
        $resultFilter = in_array($resultFilter, ['OK', 'FAIL'], true) ? $resultFilter : null;

        $appliedRaw = trim((string) ($request->query('applied') ?? ''));
        $appliedFilter = in_array($appliedRaw, ['0', '1'], true) ? (int) $appliedRaw : null;

        $roomRaw   = $request->query('room_id');
        $roomId    = $roomRaw !== null && $roomRaw !== '' ? (int) $roomRaw : null;
        $workerRaw = $request->query('worker_id');
        $workerId  = $workerRaw !== null && $workerRaw !== '' ? (int) $workerRaw : null;
        $keyRaw    = $request->query('key_id');
        $keyId     = $keyRaw !== null && $keyRaw !== '' ? (int) $keyRaw : null;

        $deviceId = trim((string) ($request->query('device_id') ?? ''));
        $deviceId = $deviceId !== '' ? $deviceId : null;

        $q = trim((string) ($request->query('q') ?? ''));
        $q = $q !== '' ? $q : null;

        $limit = (int) ($request->query('limit') ?? 100);
        $limit = max(1, min(200, $limit));

        $beforeAt = trim((string) ($request->query('before_at') ?? ''));
        $beforeAt = $beforeAt !== '' ? $beforeAt : null;
        $beforeSource = strtolower(trim((string) ($request->query('before_source') ?? '')));
        $beforeSource = $beforeSource !== '' ? $beforeSource : null;
        $beforeId = (int) ($request->query('before_id') ?? 0);

        $since = trim((string) ($request->query('since') ?? ''));
        $since = $since !== '' ? $since : null;

        /**
         * Rango de fechas de una rama. El cursor y el live tail se aplican
         * sobre el UNION ya materializado (columnas occurred_at/source/ref_id).
         *
         * @return array{0:list<string>,1:array<string,string>}
         */
        $timeConditions = static function (string $prefix, string $timeCol) use ($from, $to): array {
            return [
                ["{$timeCol} BETWEEN :{$prefix}_from AND :{$prefix}_to"],
                [":{$prefix}_from" => $from, ":{$prefix}_to" => $to],
            ];
        };

        $branches = [];

        // ── Accesos de puerta ────────────────────────────────────────────
        if ($category === null || $category === 'access') {
            [$where, $params] = $timeConditions('ae', 'e.occurred_at');
            if ($roomId !== null) { $where[] = 'e.room_id = :ae_room'; $params[':ae_room'] = (string) $roomId; }
            if ($type !== null) { $where[] = 'UPPER(e.kind) = :ae_type'; $params[':ae_type'] = $type; }
            if ($resultFilter !== null) { $where[] = 'e.result = :ae_result'; $params[':ae_result'] = $resultFilter; }
            if ($workerId !== null) { $where[] = 'ws.worker_id = :ae_worker'; $params[':ae_worker'] = (string) $workerId; }
            if ($q !== null) {
                $where[] = '(e.reason LIKE :ae_q1 OR e.kind LIKE :ae_q2 OR r.code LIKE :ae_q3 OR w.name LIKE :ae_q4)';
                foreach (['1', '2', '3', '4'] as $n) { $params[":ae_q{$n}"] = '%' . $q . '%'; }
            }
            if ($appliedFilter === null) {
                $branches[] = [
                    'sql' => "SELECT CONCAT('access:', e.id) AS event_key, 'access' AS source, 'access' AS category,
                            CAST(e.id AS UNSIGNED) AS ref_id, e.kind AS kind, e.kind AS action, e.result AS result,
                            NULL AS applied, e.reason AS reason, e.room_id AS room_id, r.code AS room_code,
                            ws.worker_id AS worker_id, w.name AS worker_name, NULL AS key_id, NULL AS key_label,
                            NULL AS device_id, e.occurred_at AS occurred_at
                     FROM access_events e
                     LEFT JOIN rooms r ON r.id = e.room_id
                     LEFT JOIN worker_sessions ws ON ws.id = e.worker_session_id
                     LEFT JOIN workers w ON w.id = ws.worker_id
                     WHERE " . implode(' AND ', $where),
                    'prefix' => 'ae', 'time_col' => 'e.occurred_at', 'id_col' => 'e.id', 'source' => 'access', 'category' => 'access',
                    'params' => $params,
                ];
            }
        }

        // ── Accesos con llave / ficha ────────────────────────────────────
        if ($category === null || $category === 'key') {
            [$where, $params] = $timeConditions('ke', 'e.occurred_at');
            if ($roomId !== null) { $where[] = 'e.room_id = :ke_room'; $params[':ke_room'] = (string) $roomId; }
            if ($type !== null) { $where[] = 'UPPER(e.credential_type) = :ke_type'; $params[':ke_type'] = $type; }
            if ($resultFilter !== null) { $where[] = 'e.result = :ke_result'; $params[':ke_result'] = $resultFilter; }
            if ($workerId !== null) { $where[] = 'e.worker_id = :ke_worker'; $params[':ke_worker'] = (string) $workerId; }
            if ($keyId !== null) { $where[] = 'e.key_id = :ke_key'; $params[':ke_key'] = (string) $keyId; }
            if ($deviceId !== null) { $where[] = 'e.device_id = :ke_device'; $params[':ke_device'] = $deviceId; }
            if ($q !== null) {
                $where[] = '(e.reason LIKE :ke_q1 OR e.credential_type LIKE :ke_q2 OR r.code LIKE :ke_q3 OR w.name LIKE :ke_q4 OR k.label LIKE :ke_q5)';
                foreach (['1', '2', '3', '4', '5'] as $n) { $params[":ke_q{$n}"] = '%' . $q . '%'; }
            }
            if ($appliedFilter === null) {
                $branches[] = [
                    'sql' => "SELECT CONCAT('key:', e.id) AS event_key, 'key' AS source, 'key' AS category,
                            CAST(e.id AS UNSIGNED) AS ref_id, e.credential_type AS kind, e.credential_type AS action,
                            e.result AS result, NULL AS applied, e.reason AS reason, e.room_id AS room_id,
                            r.code AS room_code, e.worker_id AS worker_id, w.name AS worker_name,
                            e.key_id AS key_id, k.label AS key_label, e.device_id AS device_id,
                            e.occurred_at AS occurred_at
                     FROM key_access_events e
                     LEFT JOIN rooms r ON r.id = e.room_id
                     LEFT JOIN workers w ON w.id = e.worker_id
                     LEFT JOIN access_keys k ON k.id = e.key_id
                     WHERE " . implode(' AND ', $where),
                    'prefix' => 'ke', 'time_col' => 'e.occurred_at', 'id_col' => 'e.id', 'source' => 'key', 'category' => 'key',
                    'params' => $params,
                ];
            }
        }

        // ── Órdenes de luz ───────────────────────────────────────────────
        if ($category === null || $category === 'light') {
            [$where, $params] = $timeConditions('se', 'sce.requested_at');
            if ($roomId !== null) { $where[] = 'sce.room_id = :se_room'; $params[':se_room'] = (string) $roomId; }
            if ($type !== null) { $where[] = 'UPPER(sce.action) = :se_type'; $params[':se_type'] = $type; }
            if ($resultFilter !== null) {
                $where[] = $resultFilter === 'OK' ? 'sce.publish_ok = 1' : '(sce.publish_ok = 0 OR sce.publish_ok IS NULL)';
            }
            if ($deviceId !== null) { $where[] = 'sce.external_id = :se_device'; $params[':se_device'] = $deviceId; }
            if ($q !== null) {
                $where[] = '(sce.diagnosis LIKE :se_q1 OR sce.actor LIKE :se_q2 OR sce.action LIKE :se_q3 OR r.code LIKE :se_q4 OR sce.origin LIKE :se_q5)';
                foreach (['1', '2', '3', '4', '5'] as $n) { $params[":se_q{$n}"] = '%' . $q . '%'; }
            }
            if ($appliedFilter !== null) {
                $where[] = $appliedFilter === 1 ? 'sce.publish_ok = 1' : '(sce.publish_ok = 0 OR sce.publish_ok IS NULL)';
            }
            $branches[] = [
                'sql' => "SELECT CONCAT('light:', sce.id) AS event_key, 'light' AS source, 'light' AS category,
                        CAST(sce.id AS UNSIGNED) AS ref_id, 'SWITCH' AS kind, sce.action AS action,
                        CASE WHEN sce.publish_ok = 1 THEN 'OK' WHEN sce.publish_ok = 0 THEN 'FAIL' ELSE NULL END AS result,
                        sce.publish_ok AS applied, sce.diagnosis AS reason, sce.room_id AS room_id,
                        r.code AS room_code, NULL AS worker_id, sce.actor AS worker_name, NULL AS key_id,
                        NULL AS key_label, sce.external_id AS device_id, sce.requested_at AS occurred_at
                 FROM switch_command_evidence sce
                 LEFT JOIN rooms r ON r.id = sce.room_id
                 WHERE " . implode(' AND ', $where),
                'prefix' => 'se', 'time_col' => 'sce.requested_at', 'id_col' => 'sce.id', 'source' => 'light', 'category' => 'light',
                'params' => $params,
            ];
        }

        // ── Sensores de puerta / presencia ───────────────────────────────
        if (($category === null || $category === 'door' || $category === 'presence') && $resultFilter === null) {
            $sensorBranches = ['PROXIMITY', 'PRESENCE'];
            if ($category === 'door') {
                $sensorBranches = ['PROXIMITY'];
            } elseif ($category === 'presence') {
                $sensorBranches = ['PRESENCE'];
            } elseif ($type !== null) {
                if (in_array($type, ['OPEN', 'CLOSED', 'PROXIMITY'], true)) {
                    $sensorBranches = ['PROXIMITY'];
                } elseif (in_array($type, ['PRESENT', 'ABSENT', 'PRESENCE'], true)) {
                    $sensorBranches = ['PRESENCE'];
                } else {
                    // El tipo pertenece a otra categoría (QR, NFC, on/off...).
                    $sensorBranches = [];
                }
            }
            // PROXIMITY/PRESENCE seleccionan el sensor; el resto filtra el valor.
            $valueFilter = in_array($type, ['OPEN', 'CLOSED', 'PRESENT', 'ABSENT'], true) ? $type : null;

            foreach ($sensorBranches as $sensor) {
                $prefix = $sensor === 'PROXIMITY' ? 'pe_door' : 'pe_pres';
                [$where, $params] = $timeConditions($prefix, 'pe.occurred_at');
                $where[] = "pe.sensor = '{$sensor}'";
                // F129 — ABSENT satura el registro (el radar lo emite cada ~1,5 s).
                if ($sensor === 'PRESENCE') { $where[] = "pe.value <> 'ABSENT'"; }
                if ($roomId !== null) { $where[] = 'pe.room_id = :' . $prefix . '_room'; $params[':' . $prefix . '_room'] = (string) $roomId; }
                if ($valueFilter !== null) {
                    $where[] = 'UPPER(pe.value) = :' . $prefix . '_type';
                    $params[':' . $prefix . '_type'] = $valueFilter;
                }
                if ($appliedFilter !== null) {
                    $where[] = 'pe.applied = :' . $prefix . '_applied';
                    $params[':' . $prefix . '_applied'] = (string) $appliedFilter;
                }
                if ($q !== null) {
                    $where[] = '(pe.value LIKE :' . $prefix . '_q1 OR pe.discard_reason LIKE :' . $prefix . '_q2 OR r.code LIKE :' . $prefix . '_q3)';
                    foreach (['1', '2', '3'] as $n) { $params[':' . $prefix . "_q{$n}"] = '%' . $q . '%'; }
                }
                $cat = $sensor === 'PROXIMITY' ? 'door' : 'presence';
                $branches[] = [
                    'sql' => "SELECT CONCAT('presence:', pe.id) AS event_key, 'presence' AS source, '{$cat}' AS category,
                            CAST(pe.id AS UNSIGNED) AS ref_id, pe.sensor AS kind, pe.value AS action,
                            NULL AS result, pe.applied AS applied, pe.discard_reason AS reason,
                            pe.room_id AS room_id, r.code AS room_code, NULL AS worker_id, NULL AS worker_name,
                            NULL AS key_id, NULL AS key_label, NULL AS device_id, pe.occurred_at AS occurred_at
                     FROM presence_events pe
                     LEFT JOIN rooms r ON r.id = pe.room_id
                     WHERE " . implode(' AND ', $where),
                    'prefix' => $prefix, 'time_col' => 'pe.occurred_at', 'id_col' => 'pe.id', 'source' => 'presence', 'category' => $cat,
                    'params' => $params,
                ];
            }
        }

        $params = [];
        $parts  = [];
        foreach ($branches as $branch) {
            $parts[] = $branch['sql'];
            $params += $branch['params'];
        }

        $items = [];
        if ($parts !== []) {
            // Cursor, `since` y LIMIT por rama: el top-N global está contenido
            // en la unión de los top-N de cada fuente, así no se materializa
            // todo el rango para ordenarlo después.
            $itemParts  = [];
            $itemParams = [];
            foreach ($branches as $branch) {
                $sql    = $branch['sql'];
                $branchParams = $branch['params'];
                $prefix = (string) $branch['prefix'];
                $timeCol = (string) $branch['time_col'];
                $idCol   = (string) $branch['id_col'];
                $source  = (string) $branch['source'];
                $cond    = [];

                if ($beforeAt !== null && $beforeSource !== null) {
                    // Orden global: occurred_at DESC, source ASC, ref_id DESC.
                    if (strcmp($source, $beforeSource) < 0) {
                        $cond[] = "{$timeCol} < :{$prefix}_ba";
                        $branchParams[":{$prefix}_ba"] = $beforeAt;
                    } elseif (strcmp($source, $beforeSource) === 0) {
                        $cond[] = "({$timeCol} < :{$prefix}_ba OR ({$timeCol} = :{$prefix}_bb AND {$idCol} < :{$prefix}_bid))";
                        $branchParams[":{$prefix}_ba"]  = $beforeAt;
                        $branchParams[":{$prefix}_bb"]  = $beforeAt;
                        $branchParams[":{$prefix}_bid"] = (string) $beforeId;
                    } else {
                        $cond[] = "{$timeCol} <= :{$prefix}_ba";
                        $branchParams[":{$prefix}_ba"] = $beforeAt;
                    }
                }
                if ($since !== null) {
                    $cond[] = "{$timeCol} > :{$prefix}_since";
                    $branchParams[":{$prefix}_since"] = $since;
                }
                if ($cond !== []) {
                    $sql .= ' AND (' . implode(' AND ', $cond) . ')';
                }
                $sql .= " ORDER BY {$timeCol} DESC LIMIT {$limit}";
                $itemParts[] = '(' . $sql . ')';
                $itemParams += $branchParams;
            }

            $rows = $this->rows(
                'SELECT * FROM (' . implode(' UNION ALL ', $itemParts) . ') ev
                 ORDER BY ev.occurred_at DESC, ev.source ASC, ev.ref_id DESC LIMIT ' . $limit,
                $itemParams
            );
            foreach ($rows as $row) {
                $items[] = [
                    'key' => (string) $row['event_key'],
                    'source' => (string) $row['source'],
                    'category' => (string) $row['category'],
                    'kind' => $row['kind'],
                    'action' => $row['action'],
                    'result' => $row['result'],
                    'applied' => $row['applied'] === null ? null : (bool) $row['applied'],
                    'reason' => $row['reason'],
                    'room_id' => $row['room_id'] === null ? null : (int) $row['room_id'],
                    'room_code' => $row['room_code'],
                    'worker_id' => $row['worker_id'] === null ? null : (int) $row['worker_id'],
                    'worker_name' => $row['worker_name'],
                    'key_id' => $row['key_id'] === null ? null : (int) $row['key_id'],
                    'key_label' => $row['key_label'],
                    'device_id' => $row['device_id'],
                    'occurred_at' => $row['occurred_at'],
                    'detail' => null,
                ];
            }
            // El meta_json solo se materializa para la página devuelta.
            $details = $this->eventDetails($items);
            foreach ($items as $i => $item) {
                $items[$i]['detail'] = $details[$item['key']] ?? null;
            }
        }

        $summary = [];
        // Conteo por rama sin materializar el rango entero ni el JSON.
        foreach ($branches as $branch) {
            $sql = (string) $branch['sql'];
            $fromPos = stripos($sql, ' FROM ');
            if ($fromPos === false) {
                continue;
            }
            $countSql = 'SELECT COUNT(*) AS c FROM ' . substr($sql, $fromPos + 6);
            $count = $this->scalar($countSql, $branch['params']);
            $category = (string) $branch['category'];
            $summary[$category] = ($summary[$category] ?? 0) + (int) $count;
        }

        $last = $items !== [] ? $items[count($items) - 1] : null;

        return Response::json(200, [
            'range' => ['from' => $fromLocal->format('Y-m-d H:i:s'), 'to' => $toLocal->format('Y-m-d H:i:s'), 'days' => $days],
            'items' => $items,
            'summary' => $summary,
            'meta' => [
                'count' => count($items),
                'limit' => $limit,
                'next_before_at' => $last['occurred_at'] ?? null,
                'next_before_source' => $last['source'] ?? null,
                'next_before_id' => $last !== null ? (int) substr((string) $last['key'], strrpos((string) $last['key'], ':') + 1) : null,
                'has_more' => count($items) === $limit,
            ],
        ]);
    }

    /**
     * Detalle técnico de la página de eventos. Evita materializar el JSON de
     * todo el rango en el UNION: solo se consulta para los ids devueltos.
     *
     * @param list<array<string,mixed>> $items
     * @return array<string,array<string,mixed>|null>
     */
    private function eventDetails(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $ids = ['presence' => [], 'access' => [], 'key' => [], 'light' => []];
        foreach ($items as $item) {
            $source = (string) $item['source'];
            $key = (string) $item['key'];
            $pos = strrpos($key, ':');
            $id = $pos === false ? 0 : (int) substr($key, $pos + 1);
            if ($id > 0 && isset($ids[$source])) {
                $ids[$source][] = $id;
            }
        }

        $queries = [
            'presence' => "SELECT id, JSON_OBJECT('provider', provider, 'received_at', received_at,
                    'meta', meta_json) AS detail FROM presence_events WHERE id IN (%s)",
            'access' => "SELECT id, JSON_OBJECT('provider', provider, 'stay_id', stay_id,
                    'correlation_id', correlation_id, 'meta', meta_json) AS detail
                    FROM access_events WHERE id IN (%s)",
            'key' => "SELECT id, JSON_OBJECT('owner_type', owner_type, 'role_id', role_id,
                    'correlation_id', correlation_id, 'worker_session_id', worker_session_id,
                    'provider', provider) AS detail FROM key_access_events WHERE id IN (%s)",
            'light' => "SELECT id, JSON_OBJECT('verdict', verdict, 'origin', origin, 'provider', provider,
                    'prior_state', prior_state, 'reported_state', reported_state,
                    'reported_at', reported_at, 'fresh', fresh) AS detail
                    FROM switch_command_evidence WHERE id IN (%s)",
        ];

        $map = [];
        foreach ($ids as $source => $list) {
            $list = array_values(array_unique($list));
            if ($list === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($list), '?'));
            foreach ($this->rows(sprintf($queries[$source], $placeholders), $list) as $row) {
                $decoded = json_decode((string) $row['detail'], true);
                $map[$source . ':' . $row['id']] = is_array($decoded) ? $decoded : null;
            }
        }
        return $map;
    }

    public function search(Request $request): Response
    {
        $q = trim((string) ($request->query('q') ?? ''));
        if (mb_strlen($q) < 2) {
            return Response::json(200, ['q' => $q, 'groups' => []]);
        }
        $like = '%' . $q . '%';
        $limit = 6;

        $rooms = $this->rows(
            "SELECT r.id, r.code, r.status, rt.name AS room_type
             FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id
             WHERE r.code LIKE :q1 OR rt.name LIKE :q2
             ORDER BY r.code ASC LIMIT {$limit}",
            [':q1' => $like, ':q2' => $like]
        );
        $workers = $this->rows(
            "SELECT w.id, w.name, wr.name AS role
             FROM workers w LEFT JOIN worker_roles wr ON wr.id = w.role_id
             WHERE w.name LIKE :q1 ORDER BY w.name ASC LIMIT {$limit}",
            [':q1' => $like]
        );
        $keys = $this->rows(
            "SELECT k.id, k.label, k.key_type, k.status
             FROM access_keys k WHERE k.label LIKE :q1
             ORDER BY k.label ASC LIMIT {$limit}",
            [':q1' => $like]
        );
        $devices = $this->rows(
            "SELECT d.id, d.kind, d.label, d.external_id
             FROM devices d WHERE d.label LIKE :q1 OR d.external_id LIKE :q2
             ORDER BY d.kind ASC LIMIT {$limit}",
            [':q1' => $like, ':q2' => $like]
        );
        $packs = $this->rows(
            "SELECT p.id, p.name, p.code FROM device_packs p
             WHERE p.name LIKE :q1 OR p.code LIKE :q2 ORDER BY p.name ASC LIMIT {$limit}",
            [':q1' => $like, ':q2' => $like]
        );
        $anomalies = $this->rows(
            "SELECT a.id, a.anomaly_type, a.severity, a.status, r.code AS room_code
             FROM anomalies a JOIN rooms r ON r.id = a.room_id
             WHERE a.anomaly_type LIKE :q OR CAST(a.id AS CHAR) = :exact
             ORDER BY a.detected_at DESC LIMIT {$limit}",
            [':q' => $like, ':exact' => $q]
        );
        $stays = $this->rows(
            "SELECT s.id, s.status, s.room_id, r.code AS room_code
             FROM stays s JOIN rooms r ON r.id = s.room_id
             WHERE CAST(s.id AS CHAR) = :exact
             ORDER BY s.id DESC LIMIT {$limit}",
            [':exact' => $q]
        );

        $groups = [];
        if ($rooms) $groups[] = ['type' => 'rooms', 'label' => 'Habitaciones', 'items' => $rooms];
        if ($workers) $groups[] = ['type' => 'workers', 'label' => 'Trabajadores', 'items' => $workers];
        if ($keys) $groups[] = ['type' => 'keys', 'label' => 'Llaves', 'items' => $keys];
        if ($devices) $groups[] = ['type' => 'devices', 'label' => 'Dispositivos', 'items' => $devices];
        if ($packs) $groups[] = ['type' => 'packs', 'label' => 'Packs', 'items' => $packs];
        if ($anomalies) $groups[] = ['type' => 'anomalies', 'label' => 'Anomalías', 'items' => $anomalies];
        if ($stays) $groups[] = ['type' => 'stays', 'label' => 'Estancias', 'items' => $stays];

        return Response::json(200, ['q' => $q, 'groups' => $groups]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /** @return array{0:string,1:string,2:int,3:string,4:DateTimeImmutable,5:DateTimeImmutable} */
    private function range(Request $request): array
    {
        $days = (int) ($request->query('days') ?? 7);
        $days = max(1, min(366, $days));
        $tz = new DateTimeZone(date_default_timezone_get());
        $toLocal = new DateTimeImmutable('now', $tz);
        $fromLocal = (new DateTimeImmutable('today', $tz))->modify('-' . ($days - 1) . ' days');
        $utc = new DateTimeZone('UTC');
        $from = $fromLocal->setTimezone($utc)->format('Y-m-d H:i:s');
        $to = $toLocal->setTimezone($utc)->format('Y-m-d H:i:s');
        $granularity = $days <= 1 ? 'hour' : 'day';
        return [$from, $to, $days, $granularity, $fromLocal, $toLocal];
    }

    private function todayUtc(): string
    {
        $tz = new DateTimeZone(date_default_timezone_get());
        return (new DateTimeImmutable('today', $tz))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function tzOffset(): string
    {
        $tz = new DateTimeZone(date_default_timezone_get());
        return (new DateTimeImmutable('now', $tz))->format('P');
    }

    /** @param array<string,mixed> $params */
    private function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function row(string $sql, array $params = []): ?array
    {
        $rows = $this->rows($sql, $params);
        return $rows[0] ?? null;
    }

    private function ageSeconds(?string $sqlDate, int $now): ?int
    {
        if ($sqlDate === null || $sqlDate === '') {
            return null;
        }
        $ts = strtotime($sqlDate . ' UTC');
        return $ts === false ? null : max(0, $now - $ts);
    }

    /** @param array<string,int> $statusCounts */
    private function statusDistribution(array $statusCounts): array
    {
        $order = ['FREE', 'OCCUPIED', 'OVERSTAY', 'RESERVED', 'CLEANING', 'OUT_OF_SERVICE'];
        $labels = [
            'FREE' => 'Libres',
            'OCCUPIED' => 'Ocupadas',
            'OVERSTAY' => 'Excedidas',
            'RESERVED' => 'Reservadas',
            'CLEANING' => 'Limpieza',
            'OUT_OF_SERVICE' => 'Fuera de servicio',
        ];
        $out = [];
        foreach ($order as $status) {
            $out[] = [
                'status' => $status,
                'label' => $labels[$status],
                'count' => $statusCounts[$status] ?? 0,
            ];
        }
        return $out;
    }

    /**
     * Occupancy approximated from stays. For today, the current room snapshot is
     * more accurate and replaces the computed value.
     */
    private function occupancySeries(
        string $from,
        string $to,
        DateTimeImmutable $fromLocal,
        DateTimeImmutable $toLocal,
        int $roomsTotal,
        int $currentOccupied
    ): array {
        $stays = $this->rows(
            "SELECT room_id, first_entry_at, COALESCE(closed_at, exit_detected_at, UTC_TIMESTAMP()) AS ended_at
             FROM stays
             WHERE first_entry_at IS NOT NULL
               AND first_entry_at <= :to
               AND (closed_at IS NULL OR closed_at >= :from)",
            [':from' => $from, ':to' => $to]
        );

        $tz = new DateTimeZone(date_default_timezone_get());
        $utc = new DateTimeZone('UTC');
        $days = (int) $fromLocal->diff($toLocal->setTime(0, 0, 0))->days + 1;
        $days = max(1, min($days, 366));
        $out = [];
        $todayKey = (new DateTimeImmutable('today', $tz))->format('Y-m-d');

        for ($i = 0; $i < $days; $i++) {
            $dayLocal = $fromLocal->modify('+' . $i . ' days');
            $dayStart = $dayLocal->setTime(0, 0, 0)->setTimezone($utc)->format('Y-m-d H:i:s');
            $dayEnd = $dayLocal->setTime(0, 0, 0)->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
            $rooms = [];
            foreach ($stays as $stay) {
                $entry = (string) $stay['first_entry_at'];
                $ended = (string) $stay['ended_at'];
                if ($entry < $dayEnd && $ended >= $dayStart) {
                    $rooms[(int) $stay['room_id']] = true;
                }
            }
            $count = count($rooms);
            $key = $dayLocal->format('Y-m-d');
            if ($key === $todayKey) {
                $count = $currentOccupied;
            }
            $out[] = [
                'date' => $key,
                'occupied' => $count,
                'occupancy_pct' => $roomsTotal > 0 ? round($count / $roomsTotal * 100, 1) : 0.0,
            ];
        }
        return $out;
    }

    private function activitySeries(
        string $from,
        string $to,
        string $granularity,
        DateTimeImmutable $fromLocal,
        DateTimeImmutable $toLocal
    ): array {
        $offset = $this->tzOffset();
        $series = [];
        $bucketSql = $granularity === 'hour'
            ? "HOUR(CONVERT_TZ(%s, '+00:00', :tz))"
            : "DATE(CONVERT_TZ(%s, '+00:00', :tz))";

        $queries = [
            'entries' => ['worker_sessions', 'entered_at'],
            'exits' => ['worker_sessions', 'exited_at'],
            'accesses' => ['access_events', 'occurred_at'],
        ];
        foreach ($queries as $key => [$table, $column]) {
            $bucket = sprintf($bucketSql, $column);
            foreach ($this->rows(
                "SELECT {$bucket} AS k, COUNT(*) AS c FROM {$table}
                 WHERE {$column} IS NOT NULL AND {$column} BETWEEN :from AND :to
                 GROUP BY k ORDER BY k",
                [':from' => $from, ':to' => $to, ':tz' => $offset]
            ) as $row) {
                $series[(string) $row['k']][$key] = (int) $row['c'];
            }
        }
        $scanBucket = sprintf($bucketSql, 'occurred_at');
        foreach ($this->rows(
            "SELECT {$scanBucket} AS k, COUNT(*) AS c FROM access_events
             WHERE kind = 'QR_VALIDATE' AND occurred_at BETWEEN :from AND :to GROUP BY k",
            [':from' => $from, ':to' => $to, ':tz' => $offset]
        ) as $row) {
            $series[(string) $row['k']]['scans'] = (int) $row['c'];
        }
        foreach ($this->rows(
            "SELECT {$scanBucket} AS k, COUNT(*) AS c FROM key_access_events
             WHERE occurred_at BETWEEN :from AND :to GROUP BY k",
            [':from' => $from, ':to' => $to, ':tz' => $offset]
        ) as $row) {
            $series[(string) $row['k']]['scans'] = ($series[(string) $row['k']]['scans'] ?? 0) + (int) $row['c'];
        }

        return $this->fillActivity($fromLocal, (int) $fromLocal->diff($toLocal)->days + 1, $granularity, $series, [
            'entries' => 0, 'exits' => 0, 'accesses' => 0, 'scans' => 0,
        ]);
    }

    private function fillActivity(
        DateTimeImmutable $fromLocal,
        int $days,
        string $granularity,
        array $data,
        array $defaults
    ): array {
        $out = [];
        if ($granularity === 'hour') {
            for ($h = 0; $h < 24; $h++) {
                $label = str_pad((string) $h, 2, '0', STR_PAD_LEFT);
                $row = ['label' => $label . ':00', 'key' => (string) $h];
                foreach ($defaults as $k => $v) {
                    $row[$k] = $data[(string) $h][$k] ?? $v;
                }
                $out[] = $row;
            }
            return $out;
        }
        $days = max(1, min($days, 366));
        for ($i = 0; $i < $days; $i++) {
            $day = $fromLocal->modify('+' . $i . ' days');
            $key = $day->format('Y-m-d');
            $row = ['label' => $day->format('d/m'), 'key' => $key];
            foreach ($defaults as $k => $v) {
                $row[$k] = $data[$key][$k] ?? $v;
            }
            $out[] = $row;
        }
        return $out;
    }

    private function fillDaily(DateTimeImmutable $fromLocal, int $days, array $data, mixed $default): array
    {
        $out = [];
        $days = max(1, min($days, 366));
        for ($i = 0; $i < $days; $i++) {
            $day = $fromLocal->modify('+' . $i . ' days');
            $key = $day->format('Y-m-d');
            $value = $data[$key] ?? $default;
            $out[] = ['date' => $key, 'label' => $day->format('d/m'), 'value' => $value];
        }
        return $out;
    }

    private function topWorkers(string $from, string $to, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $rows = $this->rows(
            "SELECT w.id, w.name, wr.name AS role, COUNT(ws.id) AS sessions,
                    COALESCE(SUM(GREATEST(0, TIMESTAMPDIFF(MINUTE, ws.entered_at, COALESCE(ws.exited_at, UTC_TIMESTAMP())))), 0) AS minutes
             FROM worker_sessions ws
             JOIN workers w ON w.id = ws.worker_id
             LEFT JOIN worker_roles wr ON wr.id = w.role_id
             WHERE ws.entered_at BETWEEN :from AND :to
             GROUP BY w.id, w.name, wr.name
             ORDER BY sessions DESC, minutes DESC LIMIT {$limit}",
            [':from' => $from, ':to' => $to]
        );
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['sessions'] = (int) $row['sessions'];
            $row['minutes'] = (int) $row['minutes'];
        }
        unset($row);
        return $rows;
    }

    private function topRooms(string $from, string $to, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $rows = $this->rows(
            "SELECT r.id, r.code, r.status, rt.name AS room_type,
                    (SELECT COUNT(*) FROM worker_sessions ws
                      WHERE ws.room_id = r.id AND ws.entered_at BETWEEN :from1 AND :to1) AS sessions,
                    (SELECT COUNT(*) FROM access_events ae
                      WHERE ae.room_id = r.id AND ae.occurred_at BETWEEN :from2 AND :to2) AS accesses
             FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id
             ORDER BY (sessions + accesses) DESC, r.code ASC LIMIT {$limit}",
            [':from1' => $from, ':to1' => $to, ':from2' => $from, ':to2' => $to]
        );
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['sessions'] = (int) $row['sessions'];
            $row['accesses'] = (int) $row['accesses'];
        }
        unset($row);
        return $rows;
    }

    private function accessSummary(string $from, string $to): array
    {
        $ok = 0;
        $fail = 0;
        foreach ($this->rows(
            'SELECT result, COUNT(*) AS c FROM access_events WHERE occurred_at BETWEEN :from AND :to GROUP BY result',
            [':from' => $from, ':to' => $to]
        ) as $row) {
            if ((string) $row['result'] === 'OK') $ok += (int) $row['c'];
            else $fail += (int) $row['c'];
        }
        foreach ($this->rows(
            'SELECT result, COUNT(*) AS c FROM key_access_events WHERE occurred_at BETWEEN :from AND :to GROUP BY result',
            [':from' => $from, ':to' => $to]
        ) as $row) {
            if ((string) $row['result'] === 'OK') $ok += (int) $row['c'];
            else $fail += (int) $row['c'];
        }
        $total = $ok + $fail;
        return [
            'ok' => $ok,
            'fail' => $fail,
            'total' => $total,
            'success_rate' => $total > 0 ? round($ok / $total * 100, 1) : 0.0,
        ];
    }

    private function anomalySummary(string $from): array
    {
        $counts = [];
        foreach ($this->rows(
            "SELECT severity, COUNT(*) AS c FROM anomalies WHERE status = 'OPEN' GROUP BY severity"
        ) as $row) {
            $counts[(string) $row['severity']] = (int) $row['c'];
        }
        $out = [];
        foreach (['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'] as $sev) {
            $out[] = ['severity' => $sev, 'count' => $counts[$sev] ?? 0];
        }
        return $out;
    }

    private function deviceSummary(): array
    {
        $row = $this->rows(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN online_state = 1 THEN 1 ELSE 0 END) AS online,
                    SUM(CASE WHEN online_state = 0 THEN 1 ELSE 0 END) AS offline,
                    SUM(CASE WHEN online_state IS NULL THEN 1 ELSE 0 END) AS unknown,
                    SUM(CASE WHEN battery_pct IS NOT NULL AND battery_pct <= 20 THEN 1 ELSE 0 END) AS low_battery
             FROM devices'
        )[0] ?? [];
        return [
            'total' => (int) ($row['total'] ?? 0),
            'online' => (int) ($row['online'] ?? 0),
            'offline' => (int) ($row['offline'] ?? 0),
            'unknown' => (int) ($row['unknown'] ?? 0),
            'low_battery' => (int) ($row['low_battery'] ?? 0),
        ];
    }

    private function warehouseSummary(string $from, string $to): array
    {
        $row = $this->rows(
            "SELECT COUNT(*) AS visits,
                    SUM(CASE WHEN outcome = 'ENTERED' THEN 1 ELSE 0 END) AS entered,
                    SUM(CASE WHEN outcome = 'NO_SHOW' THEN 1 ELSE 0 END) AS no_show,
                    SUM(CASE WHEN outcome = 'NOISE' THEN 1 ELSE 0 END) AS noise,
                    SUM(CASE WHEN outcome = 'DENIED' THEN 1 ELSE 0 END) AS denied
             FROM warehouse_visits WHERE created_at BETWEEN :from AND :to",
            [':from' => $from, ':to' => $to]
        )[0] ?? [];
        $recordings = (int) $this->scalar(
            'SELECT COUNT(*) FROM camera_recordings WHERE requested_at BETWEEN :from AND :to',
            [':from' => $from, ':to' => $to]
        );
        return [
            'visits' => (int) ($row['visits'] ?? 0),
            'entered' => (int) ($row['entered'] ?? 0),
            'no_show' => (int) ($row['no_show'] ?? 0),
            'noise' => (int) ($row['noise'] ?? 0),
            'denied' => (int) ($row['denied'] ?? 0),
            'recordings' => $recordings,
        ];
    }

    private function recentActivity(int $limit): array
    {
        $limit = max(1, min(50, $limit));
        return $this->rows(
            "SELECT e.id, e.occurred_at, 'ACCESS' AS source, r.code AS room_code,
                    e.kind AS type, e.result, e.reason
             FROM access_events e JOIN rooms r ON r.id = e.room_id
             ORDER BY e.occurred_at DESC LIMIT {$limit}"
        );
    }

    private function currentRoomForWorker(int $workerId): ?string
    {
        $room = $this->scalar(
            "SELECT r.code FROM worker_sessions ws JOIN rooms r ON r.id = ws.room_id
             WHERE ws.worker_id = :id AND ws.exited_at IS NULL
             ORDER BY ws.entered_at ASC LIMIT 1",
            [':id' => $workerId]
        );
        return $room !== null ? (string) $room : null;
    }

    private function canSeeWorkers(Request $request): bool
    {
        $client = $request->attr('api_client');
        if ($client === null || !method_exists($client, 'hasScope')) {
            return false;
        }
        return $client->hasScope('workers:read');
    }
}
