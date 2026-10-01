<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\NotFoundException;
use PDO;

/**
 * WarehouseVisitController: read-only visits + recordings for the /almacen
 * panel (F63/RF-70.3, F74).
 *
 * Routes (public LAN):
 *   GET /almacen-api/visits?room_id=&worker_id=&from=&to=&outcome=&limit=
 *   GET /almacen-api/visits/{id}
 */
final class WarehouseVisitController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function index(Request $request): Response
    {
        $where = [];
        $params = [];

        $roomId = (int) ($request->query['room_id'] ?? 0);
        if ($roomId > 0) {
            $where[] = 'v.room_id = :room_id';
            $params[':room_id'] = $roomId;
        }
        $workerId = (int) ($request->query['worker_id'] ?? 0);
        if ($workerId > 0) {
            $where[] = 'v.worker_id = :worker_id';
            $params[':worker_id'] = $workerId;
        }
        $outcome = (string) ($request->query['outcome'] ?? '');
        if (in_array($outcome, ['ENTERED', 'NO_SHOW', 'ANONYMOUS', 'DENIED'], true)) {
            $where[] = 'v.outcome = :outcome';
            $params[':outcome'] = $outcome;
        }
        $from = (string) ($request->query['from'] ?? '');
        if ($from !== '') {
            $where[] = 'v.created_at >= :from';
            $params[':from'] = $from;
        }
        $to = (string) ($request->query['to'] ?? '');
        if ($to !== '') {
            $where[] = 'v.created_at <= :to';
            $params[':to'] = $to;
        }

        $limit = (int) ($request->query['limit'] ?? 50);
        $limit = max(1, min(200, $limit));

        $sql = 'SELECT v.id, v.room_id, v.worker_id, w.name AS worker_name,
                       v.entry_trigger, v.outcome, v.denied_reason,
                       v.qr_at, v.entered_at, v.exited_at, v.created_at
                FROM warehouse_visits v
                LEFT JOIN workers w ON w.id = v.worker_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY v.created_at DESC, v.id DESC LIMIT ' . $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $this->formatVisit($r);
        }
        $this->attachRecordings($byId);

        return Response::json(200, ['visits' => array_values($byId)]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $stmt = $this->pdo->prepare(
            'SELECT v.id, v.room_id, v.worker_id, w.name AS worker_name,
                    v.entry_trigger, v.outcome, v.denied_reason,
                    v.qr_at, v.entered_at, v.exited_at, v.created_at
             FROM warehouse_visits v
             LEFT JOIN workers w ON w.id = v.worker_id
             WHERE v.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new NotFoundException('Visit not found', ['visit_id' => $id]);
        }
        $byId = [(int) $row['id'] => $this->formatVisit($row)];
        $this->attachRecordings($byId);

        return Response::json(200, ['visit' => $byId[(int) $row['id']]]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formatVisit(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'room_id' => (int) $row['room_id'],
            'worker' => $row['worker_id'] !== null
                ? ['id' => (int) $row['worker_id'], 'name' => (string) ($row['worker_name'] ?? '')]
                : null,
            'entry_trigger' => (string) $row['entry_trigger'],
            'outcome' => (string) $row['outcome'],
            'denied_reason' => $row['denied_reason'] !== null ? (string) $row['denied_reason'] : null,
            'qr_at' => $row['qr_at'],
            'entered_at' => $row['entered_at'],
            'exited_at' => $row['exited_at'],
            'created_at' => $row['created_at'],
            'recordings' => [],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $visitsById
     */
    private function attachRecordings(array &$visitsById): void
    {
        if ($visitsById === []) {
            return;
        }
        $ids = array_keys($visitsById);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, visit_id, position, episode, `trigger`, status,
                    requested_at, started_at, stopped_at, duration_s, size_bytes
             FROM camera_recordings
             WHERE visit_id IN ($placeholders)
             ORDER BY id ASC"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rec) {
            $vid = (int) $rec['visit_id'];
            if (!isset($visitsById[$vid])) {
                continue;
            }
            $status = (string) $rec['status'];
            $rid = (int) $rec['id'];
            $saved = $status === 'SAVED';
            $visitsById[$vid]['recordings'][] = [
                'id' => $rid,
                'position' => (string) $rec['position'],
                'episode' => (string) $rec['episode'],
                'trigger' => (string) $rec['trigger'],
                'status' => $status,
                // F68/RF-78.7: instante de solicitud (más próximo al evento que
                // `started_at`); origen de sincronización de la reproducción.
                'requested_at' => $rec['requested_at'],
                'started_at' => $rec['started_at'],
                'stopped_at' => $rec['stopped_at'],
                'duration_s' => $rec['duration_s'] !== null ? (int) $rec['duration_s'] : null,
                'size_bytes' => $rec['size_bytes'] !== null ? (int) $rec['size_bytes'] : null,
                'video_url' => $saved ? "/almacen-api/recordings/{$rid}/video" : null,
                'poster_url' => $saved ? "/almacen-api/recordings/{$rid}/poster" : null,
            ];
        }
    }
}
