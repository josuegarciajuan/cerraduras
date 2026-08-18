<?php
declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;
use App\Support\Logger\Logger;
use App\Support\Ulid;

/**
 * RequestLogMiddleware:
 *  - Ensures every request has a correlation id (ULID).
 *    - Accepts a well-formed X-Correlation-Id header; otherwise generates one.
 *  - Stores it in $request->attributes['correlation_id'] for downstream usage.
 *  - Measures request duration and logs one structured line per request.
 *  - Adds X-Correlation-Id to the response.
 *
 * Registration order: this middleware should sit immediately AFTER the
 * ErrorHandler middleware (outer = error handler, inner = logger), so that
 * errors carry the correlation id and the log line still runs.
 *
 * Note: a small correlation hand-off pattern is used: the error handler reads
 * the correlation id from $request->attributes, so this middleware must run
 * BEFORE any handler that may throw. To keep the log line even when a request
 * fails, we let errors propagate, then log in a finally block.
 */
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
        $correlationId = ($incoming !== null && Ulid::isValid($incoming))
            ? strtoupper($incoming)
            : Ulid::generate();

        $request->withAttr('correlation_id', $correlationId);

        $startedAt = microtime(true);
        $status = 0;
        try {
            $response = $next($request);
            $status = $response->status;
            return $response->withHeader('X-Correlation-Id', $correlationId);
        } finally {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logger->info('http.request', [
                'correlation_id' => $correlationId,
                'method' => $request->method,
                'route' => $request->path,
                'status' => $status,
                'duration_ms' => $durationMs,
                'remote_ip' => $request->remoteIp,
                'query' => $request->query,
            ]);
        }
    }
}
