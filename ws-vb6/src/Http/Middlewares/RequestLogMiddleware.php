<?php
declare(strict_types=1);

namespace Ws\Http\Middlewares;

use Ws\Http\Middleware;
use Ws\Http\Request;
use Ws\Http\Response;
use Ws\Support\Logger\Logger;
use Ws\Support\Ulid;

final class RequestLogMiddleware implements Middleware
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function handle(Request $request, callable $next): Response
    {
        $incoming = $request->header('x-correlation-id');
        $corrId   = ($incoming !== null && Ulid::isValid($incoming))
            ? strtoupper($incoming)
            : Ulid::generate();

        $request->withAttr('correlation_id', $corrId);

        $start  = microtime(true);
        $status = 0;
        try {
            $response = $next($request);
            $status   = $response->status;
            return $response->withHeader('X-Correlation-Id', $corrId);
        } finally {
            $dur = (int) round((microtime(true) - $start) * 1000);
            $this->logger->info('http.request', [
                'correlation_id' => $corrId,
                'method'         => $request->method,
                'path'           => $request->path,
                'status'         => $status,
                'duration_ms'    => $dur,
            ]);
        }
    }
}
