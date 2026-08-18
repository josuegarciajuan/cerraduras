<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\ApiClient;
use App\Http\Request;
use App\Http\Response;

/**
 * DevEchoController: internal smoke-test endpoint. NOT part of the public API.
 *
 * Guarded by auth + scope + idempotency. Intended to exercise the middleware
 * pipeline during TSK-013/014/015 verification. Remove or guard by APP_ENV
 * when the full feature endpoints (TSK-041 onwards) replace it.
 */
final class DevEchoController
{
    public function handle(Request $request): Response
    {
        /** @var ApiClient|null $client */
        $client = $request->attr('api_client');

        // Debug: log raw reading body for R35D-B testing
        $logFile = __DIR__ . '/../../../logs/r35d-readings.log';
        file_put_contents($logFile, json_encode([
            'ts'   => gmdate('Y-m-d\TH:i:s.v\Z'),
            'body' => $request->jsonBody,
        ]) . "\n", FILE_APPEND);

        return Response::json(201, [
            'echo' => $request->jsonBody,
            'client' => $client !== null ? $client->code : null,
            'correlation_id' => $request->attr('correlation_id'),
        ]);
    }
}
