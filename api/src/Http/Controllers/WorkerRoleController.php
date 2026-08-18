<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Workers\WorkerRoleService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Json\JsonBody;

/**
 * WorkerRoleController: /api/v1/worker-roles CRUD endpoints.
 *
 * Scopes:
 *   GET  → worker-roles:read
 *   POST / PATCH / DELETE → worker-roles:write
 *
 * See contracts.md F38 §3, TSK-W06.
 */
final class WorkerRoleController
{
    private WorkerRoleService $service;

    public function __construct(WorkerRoleService $service)
    {
        $this->service = $service;
    }

    public function list(Request $request): Response
    {
        return Response::json(200, [
            'data' => $this->service->list(),
        ]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $role = $this->service->get($id);
        return Response::json(200, [
            'data' => $role->toArray(),
        ]);
    }

    public function create(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);
        $data = [
            'name' => JsonBody::string($body, 'name'),
        ];

        if (isset($body['description'])) {
            $data['description'] = (string) $body['description'];
        }

        if (isset($body['room_type_ids']) && is_array($body['room_type_ids'])) {
            $data['room_type_ids'] = $body['room_type_ids'];
        }

        return Response::json(201, [
            'data' => $this->service->create($data),
        ]);
    }

    public function update(Request $request): Response
    {
        $id   = (int) $request->routeParam('id');
        $body = JsonBody::require($request->jsonBody);

        $data = [];
        if (isset($body['name'])) {
            $data['name'] = (string) $body['name'];
        }
        if (array_key_exists('description', $body)) {
            $data['description'] = $body['description'] !== null ? (string) $body['description'] : null;
        }
        if (isset($body['room_type_ids']) && is_array($body['room_type_ids'])) {
            $data['room_type_ids'] = $body['room_type_ids'];
        }

        return Response::json(200, [
            'data' => $this->service->update($id, $data),
        ]);
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        return Response::json(200, [
            'data' => $this->service->delete($id),
        ]);
    }
}
