<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Devices\SwitchService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\NotFoundException;

/**
 * SwitchController: endpoints for Smart Electricity Protector (EAWCBT-J).
 *
 * Endpoints (contracts.md §2.11, RF-16):
 *   GET  /api/v1/rooms/{room_id}/switches  → list switches for a room
 *   POST /api/v1/switches/{room_id}/on       → turn switch on
 *   POST /api/v1/switches/{room_id}/off      → turn switch off
 *
 * TSK-SW8.
 */
final class SwitchController
{
    private SwitchService $switchService;

    public function __construct(SwitchService $switchService)
    {
        $this->switchService = $switchService;
    }

    /**
     * GET /api/v1/rooms/{room_id}/switches
     */
    public function getSwitches(Request $request): Response
    {
        $roomId = (int) $request->routeParam('room_id');

        $device = $this->switchService->getSwitchForRoom($roomId);
        $switches = [];
        if ($device !== null) {
            $switches[] = [
                'id'          => $device->id,
                'kind'        => $device->kind,
                'external_id' => $device->externalId,
                'meta'        => $device->meta,
            ];
        }

        return Response::json(200, [
            'room_id'  => $roomId,
            'switches' => $switches,
        ]);
    }

    /**
     * POST /api/v1/switches/{room_id}/on
     */
    public function turnOn(Request $request): Response
    {
        $roomId = (int) $request->routeParam('room_id');

        $device = $this->switchService->getSwitchForRoom($roomId);
        if ($device === null) {
            throw new NotFoundException(
                'No SWITCH device registered for this room',
                ['room_id' => $roomId]
            );
        }

        $result = $this->switchService->turnOn($roomId);

        $status = $result['ok'] ? 200 : 502;
        return Response::json($status, [
            'result'   => $result['ok'] ? 'ok' : 'error',
            'provider' => $result['provider'],
            'action'   => 'on',
            'error'    => $result['error'],
        ]);
    }

    /**
     * POST /api/v1/switches/{room_id}/off
     */
    public function turnOff(Request $request): Response
    {
        $roomId = (int) $request->routeParam('room_id');

        $device = $this->switchService->getSwitchForRoom($roomId);
        if ($device === null) {
            throw new NotFoundException(
                'No SWITCH device registered for this room',
                ['room_id' => $roomId]
            );
        }

        $result = $this->switchService->turnOff($roomId);

        $status = $result['ok'] ? 200 : 502;
        return Response::json($status, [
            'result'   => $result['ok'] ? 'ok' : 'error',
            'provider' => $result['provider'],
            'action'   => 'off',
            'error'    => $result['error'],
        ]);
    }
}
