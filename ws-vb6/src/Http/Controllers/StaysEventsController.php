<?php
declare(strict_types=1);

namespace Ws\Http\Controllers;

use Ws\Http\Request;
use Ws\Http\Response;
use Ws\Stays\StayEventsSyncService;
use Ws\Support\Json\JsonBody;

/**
 * StaysEventsController (TSK-142): POST /ws-vb6/v1/stays/events
 *
 * Accepts stay event payloads from the API outbox worker and writes to
 * bs2026.log_usuarios via StayEventsSyncService.
 *
 * Required body fields:
 *   - topic (string): 'stay.closed' or 'stay.overstay'
 *   - occurred_at (string, optional): ISO8601 datetime
 *
 * Returns 201 on successful log insertion.
 *
 * Scope: all authenticated callers (WS-VB6-CALLER).
 *
 * See contracts.md §3.3, design.md §19.2.
 */
final class StaysEventsController
{
    private StayEventsSyncService $service;

    public function __construct(StayEventsSyncService $service)
    {
        $this->service = $service;
    }

    public function create(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);

        $result = $this->service->process($body);

        return Response::json(201, [
            'logged' => $result['logged'],
            'topic'  => $result['topic'],
            'tipo'   => $result['tipo'],
            'rfid'   => $result['rfid'],
            'fecha'  => $result['fecha'],
        ]);
    }
}
