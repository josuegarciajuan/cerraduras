<?php
declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Domain\Auth\ApiClient;
use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\BadRequestException;
use App\Support\Errors\ConflictException;
use App\Support\Errors\UnauthorizedException;
use App\Support\Idempotency\IdempotencyStore;

/**
 * IdempotencyMiddleware: enforce Idempotency-Key on side-effectful POSTs.
 *
 * Constructor parameters:
 *   - $scope: logical name per endpoint (e.g. 'qr.issue', 'locks.open',
 *     'presence.events', 'stays.close'). Used together with (key, client_id)
 *     for uniqueness.
 *   - $required: when true (default), a missing header yields 400 bad_request.
 *     Some endpoints (qr.validate) recommend but don't require it.
 *   - $ttlSeconds: retention period for the stored response.
 *
 * Behavior:
 *   1. Parse and validate the header.
 *   2. Compute SHA-256 of the raw request body.
 *   3. If a stored record matches (scope, key, client_id, hash), replay it.
 *   4. If a record exists but the hash differs, return 409 idempotency_conflict.
 *   5. Otherwise let the request through; on a 2xx/4xx response, persist it.
 *
 * Notes:
 *  - Requires an ApiClient in the request attribute (AuthApiKey before it).
 *  - Only persists responses with status < 500 to avoid sticking transient errors.
 *  - The stored body is the exact response body sent to the client.
 */
final class IdempotencyMiddleware implements Middleware
{
    private IdempotencyStore $store;
    private string $scope;
    private bool $required;
    private int $ttlSeconds;

    public function __construct(
        IdempotencyStore $store,
        string $scope,
        bool $required = true,
        int $ttlSeconds = 86400
    ) {
        $this->store = $store;
        $this->scope = $scope;
        $this->required = $required;
        $this->ttlSeconds = $ttlSeconds;
    }

    public function handle(Request $request, callable $next): Response
    {
        $client = $request->attr('api_client');
        if (!$client instanceof ApiClient) {
            throw new UnauthorizedException('Authentication required before idempotency check');
        }

        $key = $request->header('idempotency-key');
        if ($key === null || $key === '') {
            if ($this->required) {
                throw new BadRequestException(
                    'Missing Idempotency-Key header',
                    ['scope' => $this->scope]
                );
            }
            // Not required: just pass through.
            return $next($request);
        }

        $this->validateKeyFormat($key);

        $requestHash = hash('sha256', $request->rawBody);
        $existing = $this->store->find($this->scope, $key, $client->id);

        if ($existing !== null) {
            if ($existing['request_hash'] !== $requestHash) {
                throw new ConflictException(
                    'idempotency_conflict',
                    'Idempotency key reused with a different payload',
                    ['scope' => $this->scope]
                );
            }
            // Replay the stored response verbatim.
            $response = new Response(
                $existing['response_status'],
                ['Content-Type' => 'application/json; charset=utf-8', 'X-Idempotent-Replay' => '1'],
                $existing['response_body'] ?? ''
            );
            return $response;
        }

        // Fresh request: run the inner pipeline, then persist if appropriate.
        $response = $next($request);

        // Persist 2xx/4xx only. Skip 5xx and >=500 since they are transient.
        if ($response->status >= 200 && $response->status < 500) {
            try {
                $this->store->insert(
                    $this->scope,
                    $key,
                    $client->id,
                    $requestHash,
                    $response->status,
                    $response->body,
                    $this->ttlSeconds
                );
            } catch (\Throwable $e) {
                // Best-effort: a race condition (unique violation) means another
                // request saved it first; we simply keep our freshly computed
                // response. Avoid failing the request for this.
            }
        }

        return $response;
    }

    private function validateKeyFormat(string $key): void
    {
        if (strlen($key) > 128) {
            throw new BadRequestException(
                'Idempotency-Key exceeds 128 characters',
                ['scope' => $this->scope]
            );
        }
        if (!preg_match('/^[A-Za-z0-9_\-:]+$/', $key)) {
            throw new BadRequestException(
                'Idempotency-Key contains invalid characters',
                ['scope' => $this->scope, 'allowed' => 'A-Za-z0-9_-:']
            );
        }
    }
}
