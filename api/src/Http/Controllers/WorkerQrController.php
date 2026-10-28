<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AccessKeys\AccessKeyService;
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
    private ?AccessKeyService $accessKeys;
    private ?\App\Support\Qr\QrTokenizer $tokenizer;

    public function __construct(
        WorkerQrService $service,
        ?AccessKeyService $accessKeys = null,
        ?\App\Support\Qr\QrTokenizer $tokenizer = null
    ) {
        $this->service    = $service;
        $this->accessKeys = $accessKeys;
        $this->tokenizer  = $tokenizer;
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

        // F96: a key QR is accepted by the worker-reader endpoint too.
        if ($this->accessKeys !== null && $this->tokenizer !== null) {
            try {
                $claims = $this->tokenizer->parse($token);
            } catch (\Throwable $e) {
                $claims = null;
            }
            if (is_array($claims) && ($claims['sub'] ?? '') === 'key') {
                return Response::json(200, $this->accessKeys->validateQrToken(
                    $token, $roomId, $deviceId, $provider, $corrId
                ));
            }
        }

        $result = $this->service->validate($token, $roomId, $deviceId, $provider, $corrId);
        return Response::json(200, $result);
    }
}
