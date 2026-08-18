<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Rooms\RoomTypeService;
use App\Domain\TimeSlots\TimeSlotService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\BadRequestException;
use App\Support\Json\JsonBody;

/**
 * TimeSlotController: /api/v1/room-types/{id}/time-slots
 *
 * - GET lists current slots.
 * - PUT replaces all slots atomically. The service validates coverage/overlap.
 *
 * Scopes (contracts.md §5): time-slots:read and time-slots:write.
 */
final class TimeSlotController
{
    private RoomTypeService $roomTypes;
    private TimeSlotService $slots;

    public function __construct(RoomTypeService $roomTypes, TimeSlotService $slots)
    {
        $this->roomTypes = $roomTypes;
        $this->slots = $slots;
    }

    public function index(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $this->roomTypes->getOrFail($id); // 404 if not exists
        $list = $this->slots->listForRoomType($id);
        return Response::json(200, [
            'room_type_id' => $id,
            'slots' => array_map(static function ($s) { return $s->toArray(); }, $list),
        ]);
    }

    public function put(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $this->roomTypes->getOrFail($id); // 404 if not exists

        $body = JsonBody::require($request->jsonBody);
        if (!isset($body['slots']) || !is_array($body['slots'])) {
            throw new BadRequestException('slots field is required and must be an array');
        }

        $raw = [];
        foreach ($body['slots'] as $s) {
            if (!is_array($s)) {
                throw new BadRequestException('Each slot must be an object');
            }
            $raw[] = $s;
        }
        $this->slots->saveRaw($id, $raw);

        // Return the (normalized) stored set.
        $list = $this->slots->listForRoomType($id);
        return Response::json(200, [
            'room_type_id' => $id,
            'slots' => array_map(static function ($s) { return $s->toArray(); }, $list),
        ]);
    }
}
