<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Presence\IotSessionService;
use App\Infrastructure\Gateways\Sensor\SensorIngressInterface;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\NotFoundException;

/**
 * PresenceController: sensor event ingestion and state query.
 *
 *   POST /api/v1/presence/events      scope: presence:write  (TSK-092)
 *   GET  /api/v1/rooms/{id}/presence  scope: presence:read   (TSK-093)
 *
 * The POST endpoint delegates payload normalisation to a SensorIngressInterface
 * implementation (Simulated or Tuya, resolved by SensorIngressFactory at wiring
 * time) and hands the canonical event to IotSessionService.
 *
 * Idempotency is enforced at the middleware level (Idempotency-Key required,
 * mapped to the scope "presence.events").
 *
 * See contracts.md §2.6, RF-5, RF-7.
 */
final class PresenceController
{
    private IotSessionService       $iotService;
    private SensorIngressInterface  $ingress;

    public function __construct(IotSessionService $iotService, SensorIngressInterface $ingress)
    {
        $this->iotService = $iotService;
        $this->ingress    = $ingress;
    }

    /**
     * POST /api/v1/presence/events
     * Accepts, normalises and processes one sensor event.
     * Returns 202 with derived state.
     */
    public function ingest(Request $request): Response
    {
        $body = is_array($request->jsonBody) ? $request->jsonBody : [];

        // Normalise raw payload → canonical event array
        $event = $this->ingress->normalize($body);

        $correlationId = (string) $request->attr('correlation_id', '');
        $result        = $this->iotService->processEvent($event, $correlationId);

        return Response::json(202, $result);
    }

    /**
     * GET /api/v1/rooms/{id}/presence
     * Returns derived IoT state + recent events for the room.
     * Propagates 404 if the room does not exist.
     */
    public function getPresence(Request $request): Response
    {
        $roomId = (int) $request->routeParam('id');
        $state  = $this->iotService->getPresenceState($roomId);
        return Response::json(200, $state);
    }
}
