<?php
declare(strict_types=1);

namespace Ws\Http\Middlewares;

use Ws\Http\Middleware;
use Ws\Http\Request;
use Ws\Http\Response;
use Ws\Support\Errors\BadRequestException;
use Ws\Support\Errors\ConflictException;
use Ws\Support\Idempotency\IdempotencyStore;

/**
 * IdempotencyMiddleware (WS-VB6): enforces idempotency for POST endpoints
 * that write to the VB6 database.
 *
 * Uses vb6_bridge_idempotency table in the auxiliary DB via IdempotencyStore.
 *
 * Key: Idempotency-Key header (required).
 * Scope: logical name of the operation (e.g. 'debts', 'stays.events').
 *
 * Same key + same body hash → replay cached response.
 * Same key + different body → 409 idempotency_conflict.
 * Missing key → 400 (when $required = true).
 */
final class IdempotencyMiddleware implements Middleware
{
    private IdempotencyStore $store;
    private string           $scope;
    private bool             $required;

    public function __construct(IdempotencyStore $store, string $scope, bool $required = true)
    {
        $this->store    = $store;
        $this->scope    = $scope;
        $this->required = $required;
    }

    public function handle(Request $request, callable $next): Response
    {
        $key = $request->header('idempotency-key');

        if ($key === null || $key === '') {
            if ($this->required) {
                throw new BadRequestException('Missing required Idempotency-Key header');
            }
            return $next($request);
        }

        $bodyHash    = hash('sha256', $request->rawBody);
        $corrId      = (string) $request->attr('correlation_id', '');
        $lookup      = $this->store->lookup($this->scope, $key, $bodyHash);

        if ($lookup === 'conflict') {
            throw new ConflictException(
                'idempotency_conflict',
                'Same Idempotency-Key used with a different request body',
                ['scope' => $this->scope, 'key' => $key]
            );
        }

        if (is_array($lookup)) {
            // Replay cached response
            return new Response(
                $lookup['status'],
                ['Content-Type' => 'application/json; charset=utf-8',
                 'X-Idempotent-Replayed' => 'true',
                 'X-Correlation-Id' => $corrId],
                $lookup['body']
            );
        }

        // Not seen before — process and cache
        $response = $next($request);
        if ($response->status < 500) {
            $this->store->store(
                $this->scope, $key, $bodyHash,
                $response->status, $response->body
            );
        }
        return $response;
    }
}
