<?php
declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Domain\Auth\ApiClient;
use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\ForbiddenException;
use App\Support\Errors\UnauthorizedException;

/**
 * AuthScopeMiddleware: per-route authorization.
 *
 * Parameterized by the list of required scopes. Expects an ApiClient already
 * populated by AuthApiKeyMiddleware (runs after it in the pipeline).
 *
 * Usage:
 *   $router->post('/qr', [$ctrl, 'issue'], [$authKey, new AuthScopeMiddleware(['qr:issue'])]);
 */
final class AuthScopeMiddleware implements Middleware
{
    /** @var list<string> */
    private array $required;

    /**
     * @param list<string> $required scopes the route needs
     */
    public function __construct(array $required)
    {
        $this->required = array_values($required);
    }

    public function handle(Request $request, callable $next): Response
    {
        $client = $request->attr('api_client');
        if (!$client instanceof ApiClient) {
            // Defensive: someone put AuthScope before AuthApiKey.
            throw new UnauthorizedException('Authentication required before scope check');
        }

        if (!$client->hasAllScopes($this->required)) {
            throw new ForbiddenException(
                'auth_insufficient_scope',
                'Client does not have the required scopes',
                [
                    'required' => $this->required,
                    'client' => $client->code,
                ]
            );
        }

        return $next($request);
    }
}
