<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Rooms\RoomTypeService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Json\JsonBody;

/**
 * RoomTypeController: /api/v1/room-types endpoints.
 *
 * Scopes (contracts.md §5):
 *   - GET  list / GET one:  rooms:read
 *   - POST / PATCH:         rooms:write
 */
final class RoomTypeController
{
    private RoomTypeService $service;

    public function __construct(RoomTypeService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): Response
    {
        $items = array_map(
            static function ($rt) { return $rt->toArray(); },
            $this->service->listAll()
        );
        return Response::json(200, ['items' => $items, 'next_cursor' => null]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        return Response::json(200, $this->service->getOrFail($id)->toArray());
    }

    public function create(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);
        $rt = $this->service->create(
            JsonBody::string($body, 'code', 32),
            JsonBody::string($body, 'name', 128),
            JsonBody::int($body, 'grace_minutes', 0, 120),
            JsonBody::int($body, 'exit_presence_gap_seconds', 1, 600),
            JsonBody::int($body, 'reentry_cooldown_seconds', 0, 600),
            JsonBody::int($body, 'qr_usage_window_minutes', 5, 240)
        );
        return Response::json(201, $rt->toArray());
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $body = JsonBody::require($request->jsonBody);
        // Build a sparse array; service will validate each present field.
        $fields = [];
        foreach ([
            'code',
            'name',
            'grace_minutes',
            'exit_presence_gap_seconds',
            'reentry_cooldown_seconds',
            'qr_usage_window_minutes',
        ] as $k) {
            if (array_key_exists($k, $body)) {
                $fields[$k] = $body[$k];
            }
        }
        return Response::json(200, $this->service->update($id, $fields)->toArray());
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $this->service->delete($id);
        return Response::json(200, ['deleted' => true]);
    }
}
