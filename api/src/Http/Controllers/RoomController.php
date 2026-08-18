<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Rooms\RoomService;
use App\Domain\Rooms\RoomStateService;
use App\Domain\Devices\DeviceService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\BadRequestException;
use App\Support\Json\JsonBody;

/**
 * RoomController: /api/v1/rooms endpoints.
 *
 * Scopes:
 *   GET list / show:          rooms:read
 *   POST / PATCH metadata:    rooms:write
 *   PATCH /{id}/state:        rooms:state
 */
final class RoomController
{
    private RoomService $rooms;
    private RoomStateService $state;
    private DeviceService $devices;

    public function __construct(
        RoomService $rooms,
        RoomStateService $state,
        DeviceService $devices
    ) {
        $this->rooms = $rooms;
        $this->state = $state;
        $this->devices = $devices;
    }

    public function index(Request $request): Response
    {
        $filters = [
            'status' => $request->query('status'),
            'room_type_id' => $request->query('room_type_id'),
        ];
        $limit = (int) ($request->query('limit') ?? '50');
        $limit = max(1, min(200, $limit));
        // Simple offset-based pagination for admin; contracts.md mentions
        // cursors, but we don't need stable cursors for admin listings yet.
        $offset = (int) ($request->query('offset') ?? '0');
        $offset = max(0, $offset);

        $list = $this->rooms->listFiltered($filters, $limit, $offset);

        return Response::json(200, [
            'items' => array_map(static function ($r) { return $r->toArray(); }, $list),
            'next_cursor' => null,
        ]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $room = $this->rooms->getOrFail($id);
        $devices = $this->devices->listForRoom($id);
        $payload = $room->toArray();
        $payload['devices'] = array_map(static function ($d) { return $d->toArray(); }, $devices);
        return Response::json(200, $payload);
    }

    public function create(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);
        $code = JsonBody::string($body, 'code', 32);
        $rtId = JsonBody::int($body, 'room_type_id', 1);
        $sim = JsonBody::boolOpt($body, 'simulated_override');
        $notes = isset($body['notes']) ? ($body['notes'] === null ? null : (string) $body['notes']) : null;
        $room = $this->rooms->create($code, $rtId, $sim, $notes);
        return Response::json(201, $room->toArray());
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $body = JsonBody::require($request->jsonBody);
        $fields = [];
        if (array_key_exists('code', $body)) {
            $fields['code'] = $body['code'];
        }
        if (array_key_exists('room_type_id', $body)) {
            $fields['room_type_id'] = $body['room_type_id'];
        }
        if (array_key_exists('simulated_override', $body)) {
            // Accept explicit null to "clear"; boolOpt accepts both bool and null.
            $v = $body['simulated_override'];
            if ($v !== null && !is_bool($v)) {
                throw new BadRequestException('simulated_override must be boolean or null');
            }
            $fields['simulated_override'] = $v;
        }
        if (array_key_exists('notes', $body)) {
            $fields['notes'] = $body['notes'] === null ? null : (string) $body['notes'];
        }
        if (array_key_exists('pack_id', $body)) {
            $v = $body['pack_id'];
            $fields['pack_id'] = $v === null || $v === '' ? null : (int) $v;
        }
        if (array_key_exists('presence_check_seconds', $body)) {
            $v = $body['presence_check_seconds'];
            $fields['presence_check_seconds'] = $v === null || $v === '' ? null : (int) $v;
        }
        return Response::json(200, $this->rooms->update($id, $fields)->toArray());
    }

    public function updateState(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $body = JsonBody::require($request->jsonBody);
        $target = JsonBody::string($body, 'status', 32);

        $room = $this->rooms->getOrFail($id);
        $room = $this->state->transitionAdmin($room, $target);
        return Response::json(200, $room->toArray());
    }

    /**
     * List rooms whose RPI devices are currently marked as identified ("mírame").
     * GET /api/v1/rooms/identified (scope: rooms:read)
     */
    public function identified(Request $request): Response
    {
        $items = $this->devices->findIdentified();
        return Response::json(200, ['items' => $items]);
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $this->rooms->delete($id);
        return Response::json(200, ['deleted' => true]);
    }
}
