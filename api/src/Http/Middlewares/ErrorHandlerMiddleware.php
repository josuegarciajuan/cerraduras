<?php
declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;
use App\Support\Config;
use App\Support\Errors\ApiException;
use App\Support\Logger\Logger;

/**
 * ErrorHandlerMiddleware:
 *  - Catches ApiException and emits the standard error envelope with the
 *    declared HTTP status and error code.
 *  - Catches any other Throwable, logs it, and returns a generic 500
 *    `internal_error`. In debug mode, details include type/file/line.
 *
 * This middleware should be registered FIRST (outermost) so it wraps every
 * other middleware and the handler.
 */
final class ErrorHandlerMiddleware implements Middleware
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function handle(Request $request, callable $next): Response
    {
        $correlationId = (string) $request->attr('correlation_id', '');

        try {
            return $next($request);
        } catch (ApiException $e) {
            // Domain/expected error: log at warn level with context, no stack trace.
            $this->logger->warn('api.exception', [
                'correlation_id' => $correlationId,
                'code' => $e->errorCode(),
                'status' => $e->httpStatus(),
                'message' => $e->getMessage(),
                'details' => $e->errorDetails(),
                'route' => $request->path,
                'method' => $request->method,
            ]);
            return Response::error(
                $e->httpStatus(),
                $e->errorCode(),
                $e->getMessage(),
                $correlationId,
                $e->errorDetails()
            );
        } catch (\Throwable $e) {
            // Unexpected error: log with trace, return a generic envelope.
            $this->logger->error('api.unhandled', [
                'correlation_id' => $correlationId,
                'type' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'route' => $request->path,
                'method' => $request->method,
            ]);
            $debug = Config::getBool('APP_DEBUG', false);
            $details = null;
            if ($debug) {
                $details = [
                    'type' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ];
            }
            return Response::error(
                500,
                'internal_error',
                $debug ? $e->getMessage() : 'Internal server error',
                $correlationId,
                $details
            );
        }
    }
}
