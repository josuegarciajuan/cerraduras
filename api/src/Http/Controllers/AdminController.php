<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use PDO;

final class AdminController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // -------------------------------------------------------------------
    // Access Events
    // -------------------------------------------------------------------

    public function listAccessEvents(Request $request): Response
    {
        $limit  = min((int) ($request->query('limit') ?? '50'), 200);
        $cursor = (int) ($request->query('cursor') ?? '0');
        $roomId = $request->query('room_id');
        $kind   = $request->query('kind');
        $result = $request->query('result');
        $from   = $request->query('from');
        $to     = $request->query('to');

        $where = [];
        $params = [];
        if ($roomId !== null) { $where[] = 'ae.room_id = :rid'; $params[':rid'] = (int) $roomId; }
        if ($kind !== null)   { $where[] = 'ae.kind = :kind';   $params[':kind'] = $kind; }
        if ($result !== null) { $where[] = 'ae.result = :res';  $params[':res'] = $result; }
        if ($from !== null)   { $where[] = 'ae.occurred_at >= :from'; $params[':from'] = $from; }
        if ($to !== null)     { $where[] = 'ae.occurred_at <= :to';   $params[':to'] = $to; }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT ae.*, r.code as room_code FROM access_events ae
                JOIN rooms r ON r.id = ae.room_id {$whereSql}
                ORDER BY ae.occurred_at DESC LIMIT {$limit} OFFSET {$cursor}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $nextCursor = count($items) >= $limit ? $cursor + $limit : null;

        return Response::json(200, ['items' => $items, 'next_cursor' => $nextCursor]);
    }

    // -------------------------------------------------------------------
    // Presence Events
    // -------------------------------------------------------------------

    public function listPresenceEvents(Request $request): Response
    {
        $limit  = min((int) ($request->query('limit') ?? '50'), 200);
        $cursor = (int) ($request->query('cursor') ?? '0');
        $roomId = $request->query('room_id');
        $sensor = $request->query('sensor');
        $value  = $request->query('value');
        $from   = $request->query('from');
        $to     = $request->query('to');

        $where = [];
        $params = [];
        if ($roomId !== null) { $where[] = 'pe.room_id = :rid';  $params[':rid'] = (int) $roomId; }
        if ($sensor !== null) { $where[] = 'pe.sensor = :sensor'; $params[':sensor'] = $sensor; }
        if ($value !== null)  { $where[] = 'pe.value = :val';     $params[':val'] = $value; }
        if ($from !== null)   { $where[] = 'pe.occurred_at >= :from'; $params[':from'] = $from; }
        if ($to !== null)     { $where[] = 'pe.occurred_at <= :to';   $params[':to'] = $to; }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT pe.*, r.code as room_code FROM presence_events pe
                JOIN rooms r ON r.id = pe.room_id {$whereSql}
                ORDER BY pe.occurred_at DESC LIMIT {$limit} OFFSET {$cursor}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $nextCursor = count($items) >= $limit ? $cursor + $limit : null;

        return Response::json(200, ['items' => $items, 'next_cursor' => $nextCursor]);
    }

    // -------------------------------------------------------------------
    // Outbox
    // -------------------------------------------------------------------

    public function listOutbox(Request $request): Response
    {
        $limit  = min((int) ($request->query('limit') ?? '50'), 200);
        $cursor = (int) ($request->query('cursor') ?? '0');
        $status = $request->query('status');
        $topic  = $request->query('topic');

        $where = [];
        $params = [];
        if ($status !== null) { $where[] = 'status = :st';    $params[':st'] = $status; }
        if ($topic !== null)  { $where[] = 'topic = :topic';  $params[':topic'] = $topic; }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT * FROM outbox_vb6 {$whereSql} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$cursor}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Summary counts
        $summary = [];
        foreach (['PENDING','SENDING','SENT','FAILED','DEAD'] as $s) {
            $cStmt = $this->pdo->prepare('SELECT COUNT(*) as cnt FROM outbox_vb6 WHERE status = :s');
            $cStmt->bindValue(':s', $s);
            $cStmt->execute();
            $summary[strtolower($s)] = (int) $cStmt->fetchColumn();
        }

        $nextCursor = count($items) >= $limit ? $cursor + $limit : null;

        return Response::json(200, ['items' => $items, 'summary' => $summary, 'next_cursor' => $nextCursor]);
    }

    public function retryOutbox(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        // No status filter on purpose: any item (PENDING, FAILED or DEAD) is
        // re-armed. Re-driving a DEAD item is the intended manual recovery path
        // once the root cause of its 4xx has been fixed.
        $stmt = $this->pdo->prepare(
            "UPDATE outbox_vb6 SET status = 'PENDING', next_attempt_at = UTC_TIMESTAMP(3), attempts = 0, last_error = NULL WHERE id = :id"
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() === 0) {
            return Response::json(404, ['error' => ['code' => 'not_found', 'message' => 'Outbox item not found']]);
        }
        return Response::json(200, ['retried' => true]);
    }
}
