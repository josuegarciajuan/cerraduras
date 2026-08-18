<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Locks\LockService;
use App\Domain\Presence\IotSessionService;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Gateways\Sensor\SensorIngressInterface;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Errors\ForbiddenException;
use App\Support\Errors\NotFoundException;
use App\Support\Json\JsonBody;

/**
 * SimController: /sim/* endpoints for simulated room interactions.
 *
 * F15 (TSK-160, TSK-161)
 *
 * Requires scope: sim:*
 * Only works when SIMULATED_MODE=true OR rooms.simulated_override=true.
 *
 * Endpoints:
 *   POST /sim/rooms/{id}/presence     → injects PRESENCE/PROXIMITY sensor event
 *   POST /sim/rooms/{id}/door         → generates PROXIMITY event (OPEN|CLOSED)
 *   POST /sim/rooms/{id}/lock/ack     → records simulated lock acknowledgement
 *
 * See design.md §10, RF-11.
 */
final class SimController
{
    private IotSessionService        $iotService;
    private SensorIngressInterface    $ingress;
    private LockService               $lockService;
    private RoomRepositoryInterface   $rooms;

    public function __construct(
        IotSessionService $iotService,
        LockService $lockService,
        RoomRepositoryInterface $rooms,
        SensorIngressInterface $ingress
    ) {
        $this->iotService  = $iotService;
        $this->ingress     = $ingress;
        $this->lockService = $lockService;
        $this->rooms       = $rooms;
    }

    /**
     * POST /sim/rooms/{id}/presence
     *
     * Injects a sensor event (PRESENCE or PROXIMITY) for the room.
     *
     * Body fields:
     *   sensor       (string): PRESENCE | PROXIMITY
     *   value        (string): PRESENT | ABSENT | OPEN | CLOSED
     *   occurred_at  (string, optional): ISO8601 datetime
     *
     * Returns 202 with derived state.
     */
    public function presence(Request $request): Response
    {
        $roomId = (int) $request->routeParam('id');
        $this->assertSimulatedMode($roomId);

        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        $body['room_id'] = $roomId;

        // Ensure source_event_id for idempotency (use correlation_id)
        if (empty($body['source_event_id'])) {
            $body['source_event_id'] = 'sim-presence-' . $roomId . '-' . Clock::nowUtc()->format('Uu');
        }

        $event         = $this->ingress->normalize($body);
        $correlationId = (string) $request->attr('correlation_id', '');
        $result        = $this->iotService->processEvent($event, $correlationId);

        return Response::json(202, array_merge($result, ['simulated' => true]));
    }

    /**
     * POST /sim/rooms/{id}/door
     *
     * Generates a PROXIMITY event for the room door.
     *
     * Body fields:
     *   state (string): OPEN | CLOSED
     *
     * Returns 202 with derived state.
     */
    public function door(Request $request): Response
    {
        $roomId = (int) $request->routeParam('id');
        $this->assertSimulatedMode($roomId);

        $body  = is_array($request->jsonBody) ? $request->jsonBody : [];
        $state = strtoupper((string) ($body['state'] ?? ''));

        if (!in_array($state, ['OPEN', 'CLOSED'], true)) {
            return Response::json(422, [
                'error' => [
                    'code'    => 'invalid_state',
                    'message' => 'state must be OPEN or CLOSED',
                    'details' => ['allowed' => ['OPEN', 'CLOSED'], 'given' => $state],
                ],
            ]);
        }

        $event = $this->ingress->normalize([
            'room_id'         => $roomId,
            'sensor'          => 'PROXIMITY',
            'value'           => $state,
            'occurred_at'     => $body['occurred_at'] ?? Clock::nowUtc()->format('Y-m-d\TH:i:s\Z'),
            'source_event_id' => 'sim-door-' . $roomId . '-' . Clock::nowUtc()->format('Uu'),
        ]);

        $correlationId = (string) $request->attr('correlation_id', '');
        $result        = $this->iotService->processEvent($event, $correlationId);

        return Response::json(202, array_merge($result, ['simulated' => true, 'door_state' => $state]));
    }

    /**
     * POST /sim/rooms/{id}/lock/ack
     *
     * Records a simulated lock acknowledgement (e.g. lock/unlock confirmed).
     *
     * Body fields:
     *   action (string): OPEN | LOCK
     *   reason (string, optional)
     *
     * Returns 200 with acknowledgement details.
     */
    public function lockAck(Request $request): Response
    {
        $roomId = (int) $request->routeParam('id');
        $this->assertSimulatedMode($roomId);

        $room = $this->rooms->findById($roomId);
        if ($room === null) {
            throw new NotFoundException('Room not found', ['room_id' => $roomId]);
        }

        $body   = is_array($request->jsonBody) ? $request->jsonBody : [];
        $action = strtoupper((string) ($body['action'] ?? 'OPEN'));
        $reason = (string) ($body['reason'] ?? 'sim_ack');

        $correlationId = (string) $request->attr('correlation_id', '');

        if ($action === 'OPEN') {
            $result = $this->lockService->open($roomId, $reason, true, $correlationId);
        } else {
            $result = $this->lockService->lockRoom($roomId, $reason, $correlationId);
        }

        return Response::json(200, array_merge($result, [
            'simulated' => true,
            'acked_at'  => Clock::nowUtc()->format(DATE_ATOM),
        ]));
    }

    /**
     * Check if simulated mode is allowed for this room.
     * Throws ForbiddenException if neither global nor per-room simulated mode is active.
     */
    private function assertSimulatedMode(int $roomId): void
    {
        // Check global flag
        if (Config::getBool('SIMULATED_MODE', true)) {
            return;
        }

        // Check per-room override
        $room = $this->rooms->findById($roomId);
        if ($room !== null && $room->simulatedOverride === true) {
            return;
        }

        throw new ForbiddenException(
            'sim_mode_disabled',
            'Simulated mode is not enabled for this room',
            ['room_id' => $roomId]
        );
    }
}
