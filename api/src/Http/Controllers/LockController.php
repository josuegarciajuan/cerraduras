<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Locks\LockService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Json\JsonBody;

/**
 * LockController: admin endpoints for manual lock/unlock (RF-4, TSK-072).
 *
 *   POST /api/v1/locks/{room_id}/open   scope: locks:open  (+ locks:override for cooldown bypass)
 *   POST /api/v1/locks/{room_id}/lock   scope: locks:lock
 *
 * Both endpoints require an Idempotency-Key (see contracts.md §1.5).
 * The cooldown override is a runtime check: the controller checks whether
 * the client has the locks:override scope BEFORE forwarding to the service.
 */
final class LockController
{
    private LockService $service;

    public function __construct(LockService $service)
    {
        $this->service = $service;
    }

    public function open(Request $request): Response
    {
        $roomId = (int) $request->routeParam('room_id');

        $body             = is_array($request->jsonBody) ? $request->jsonBody : [];
        $reason           = isset($body['reason']) ? (string) $body['reason'] : 'manual_override';
        $overrideCooldown = isset($body['override_cooldown']) && (bool) $body['override_cooldown'];

        // If the client requests a cooldown override it must have the
        // locks:override scope. We read the resolved ApiClient attached by
        // AuthApiKeyMiddleware to the request attributes.
        if ($overrideCooldown) {
            /** @var \App\Domain\Auth\ApiClient|null $client */
            $client = $request->attr('api_client');
            if ($client === null || !$client->hasScope('locks:override')) {
                return Response::json(403, [
                    'error' => [
                        'code'    => 'auth_insufficient_scope',
                        'message' => 'locks:override scope required to bypass cooldown',
                    ],
                ]);
            }
        }

        $correlationId = (string) $request->attr('correlation_id', '');

        $result = $this->service->open($roomId, $reason, $overrideCooldown, $correlationId);

        return Response::json(200, $result);
    }

    public function lock(Request $request): Response
    {
        $roomId = (int) $request->routeParam('room_id');

        $body   = is_array($request->jsonBody) ? $request->jsonBody : [];
        $reason = isset($body['reason']) ? (string) $body['reason'] : 'manual';

        $correlationId = (string) $request->attr('correlation_id', '');

        $result = $this->service->lockRoom($roomId, $reason, $correlationId);

        return Response::json(200, $result);
    }
}
