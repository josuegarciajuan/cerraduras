<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Workers\WorkerQrService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Json\JsonBody;

/**
 * WorkerQrController: POST /api/v1/workers/qr/validate
 *
 * Scope: qr:validate (same as guest QR — devices that scan QRs already have it).
 *
 * See contracts.md F38 §2, TSK-W11.
 */
final class WorkerQrController
{
    private WorkerQrService $service;

    public function __construct(WorkerQrService $service)
    {
        $this->service = $service;
    }

    /**
     * POST /api/v1/workers/qr/validate
     */
    public function validate(Request $request): Response
    {
        $body    = JsonBody::require($request->jsonBody);
        $token   = JsonBody::string($body, 'token');
        $roomId  = JsonBody::int($body, 'room_id');
        $deviceId = JsonBody::string($body, 'device_id');
        $provider  = (string) ($request->query('provider') ?? 'TUYA');
        $corrId    = (string) $request->attr('correlation_id', '');

        $result = $this->service->validate($token, $roomId, $deviceId, $provider, $corrId);
        return Response::json(200, $result);
    }
}
