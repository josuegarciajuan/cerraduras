<?php
declare(strict_types=1);

namespace Ws\Http\Middlewares;

use Ws\Http\Middleware;
use Ws\Http\Request;
use Ws\Http\Response;
use Ws\Support\Config;
use Ws\Support\Errors\UnauthorizedException;

/**
 * AuthApiKeyMiddleware (WS-VB6): validates X-API-Key by comparing its
 * SHA-256 hash against WS_API_KEY_HASH from config.
 *
 * Unlike the main API (which has multiple clients in DB), the WS-VB6 accepts
 * exactly one caller: the main API's outbox worker, identified by a single
 * pre-shared key stored as a hash in .env.
 *
 * On success, stores the client code 'WS-VB6-CALLER' in request attributes
 * so downstream middlewares (scope check, idempotency) can read it.
 */
final class AuthApiKeyMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $key = $request->header('x-api-key');
        if ($key === null || $key === '') {
            throw new UnauthorizedException('Missing X-API-Key header');
        }

        $expected = Config::get('WS_API_KEY_HASH', '');
        if ($expected === '' || $expected === null) {
            throw new \RuntimeException('WS_API_KEY_HASH not configured');
        }

        $actual = hash('sha256', $key);
        if (!hash_equals($expected, $actual)) {
            throw new UnauthorizedException('Invalid API key');
        }

        // Mark as authenticated; all vb6-bridge:* scopes are implicitly granted.
        $request->withAttr('auth_client', 'WS-VB6-CALLER');
        return $next($request);
    }
}
