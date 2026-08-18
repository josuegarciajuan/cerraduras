<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Workers\WorkerService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Json\JsonBody;

/**
 * WorkerController: /api/v1/workers CRUD + query endpoints.
 *
 * Scopes:
 *   GET  → workers:read
 *   POST / PATCH / DELETE → workers:write
 *
 * See contracts.md F38 §1, TSK-W08.
 */
final class WorkerController
{
    private WorkerService $service;

    public function __construct(WorkerService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /api/v1/workers?role_id=&active=
     */
    public function list(Request $request): Response
    {
        $filters = [];
        $roleId = $request->query('role_id');
        if ($roleId !== null && $roleId !== '') {
            $filters['role_id'] = (int) $roleId;
        }
        $active = $request->query('active');
        if ($active !== null && $active !== '') {
            $filters['active'] = $active === '1' || $active === 'true';
        }

        $items = $this->service->list($filters);
        return Response::json(200, [
            'data' => $items,
            'meta' => ['total' => count($items)],
        ]);
    }

    /**
     * POST /api/v1/workers
     */
    public function create(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);
        $data = [
            'name'    => JsonBody::string($body, 'name'),
            'role_id' => JsonBody::int($body, 'role_id'),
        ];
        if (isset($body['notes'])) {
            $data['notes'] = (string) $body['notes'];
        }

        return Response::json(201, [
            'data' => $this->service->create($data),
        ]);
    }

    /**
     * GET /api/v1/workers/{id}
     */
    public function show(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        return Response::json(200, [
            'data' => $this->service->show($id),
        ]);
    }

    /**
     * PATCH /api/v1/workers/{id}
     */
    public function update(Request $request): Response
    {
        $id   = (int) $request->routeParam('id');
        $body = JsonBody::require($request->jsonBody);

        $data = [];
        if (isset($body['name'])) {
            $data['name'] = (string) $body['name'];
        }
        if (isset($body['role_id'])) {
            $data['role_id'] = (int) $body['role_id'];
        }
        if (array_key_exists('notes', $body)) {
            $data['notes'] = $body['notes'];
        }

        return Response::json(200, [
            'data' => $this->service->update($id, $data),
        ]);
    }

    /**
     * DELETE /api/v1/workers/{id}  (soft deactivate)
     */
    public function deactivate(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        return Response::json(200, [
            'data' => $this->service->deactivate($id),
        ]);
    }

    /**
     * POST /api/v1/workers/{id}/qr — regenerate master QR
     */
    public function regenerateQr(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        return Response::json(200, [
            'data' => $this->service->regenerateQr($id),
        ]);
    }

    /**
     * GET /api/v1/workers/{id}/sessions?from=&to=&room_id=&limit=
     */
    public function sessions(Request $request): Response
    {
        $id      = (int) $request->routeParam('id');
        $filters = [];
        $from = $request->query('from');
        if ($from !== null && $from !== '') {
            $filters['from'] = (string) $from;
        }
        $to = $request->query('to');
        if ($to !== null && $to !== '') {
            $filters['to'] = (string) $to;
        }
        $roomId = $request->query('room_id');
        if ($roomId !== null && $roomId !== '') {
            $filters['room_id'] = (int) $roomId;
        }
        $limit = $request->query('limit');
        if ($limit !== null && $limit !== '') {
            $filters['limit'] = (int) $limit;
        }

        $items = $this->service->getSessions($id, $filters);
        return Response::json(200, [
            'data' => $items,
            'meta' => ['total' => count($items)],
        ]);
    }

    /**
     * GET /api/v1/workers/inside
     */
    public function inside(Request $request): Response
    {
        return Response::json(200, [
            'data' => $this->service->getWorkersInside(),
        ]);
    }
}
