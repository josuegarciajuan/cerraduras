<?php
declare(strict_types=1);

namespace Ws\Http\Middlewares;

use Ws\Http\Middleware;
use Ws\Http\Request;
use Ws\Http\Response;
use Ws\Support\Errors\ApiException;
use Ws\Support\Logger\Logger;

final class ErrorHandlerMiddleware implements Middleware
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function handle(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (ApiException $e) {
            $corrId = (string) $request->attr('correlation_id', '');
            if ($e->httpStatus() >= 500) {
                $this->logger->error('api_exception', [
                    'code'    => $e->errorCode(),
                    'message' => $e->getMessage(),
                    'correlation_id' => $corrId,
                ]);
            }
            return Response::error(
                $e->httpStatus(),
                $e->errorCode(),
                $e->getMessage(),
                $corrId,
                $e->details() ?: null
            );
        } catch (\Throwable $e) {
            $corrId = (string) $request->attr('correlation_id', '');
            $this->logger->error('unhandled_exception', [
                'type'    => get_class($e),
                'message' => $e->getMessage(),
                'correlation_id' => $corrId,
            ]);
            return Response::error(500, 'internal_error', 'Internal server error', $corrId);
        }
    }
}
