<?php declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Domain\Crm\CrmSessionRepository;
use App\Domain\Crm\CrmUserRepository;
use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;

/**
 * CrmSessionMiddleware:
 *  - Checks for the "crm_session" cookie.
 *  - Validates it against the crm_sessions table.
 *  - If valid, stores the CrmUser in request attributes as "crm_user".
 *  - For browser page views (/panel* or /llaves*): redirects to the matching
 *    login page (/panel/login or /llaves/login) on failure.
 *  - For API calls: throws an Unauthorized exception (JSON response).
 *
 * This middleware is used to protect CRM HTML pages and CRM-specific API endpoints.
 * It is distinct from AuthApiKeyMiddleware, which handles API key auth.
 */
final class CrmSessionMiddleware implements Middleware
{
    private CrmSessionRepository $sessionRepo;
    private CrmUserRepository $userRepo;

    public function __construct(CrmSessionRepository $sessionRepo, CrmUserRepository $userRepo)
    {
        $this->sessionRepo = $sessionRepo;
        $this->userRepo    = $userRepo;
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = $request->cookie('crm_session');
        $loginPath = $this->loginPathFor($request->path);

        // --- No session cookie ---
        if ($token === null || $token === '') {
            return $this->fail($request, $loginPath);
        }

        // --- Validate token ---
        $userId = $this->sessionRepo->validate($token);
        if ($userId === null) {
            return $this->fail($request, $loginPath, true);
        }

        // --- User still active? ---
        $user = $this->userRepo->findById($userId);
        if ($user === null || !$user->active) {
            $this->sessionRepo->destroy($token);
            return $this->fail($request, $loginPath, true);
        }

        $request->withAttr('crm_user', $user);
        $request->withAttr('crm_session_token', $token);

        return $next($request);
    }

    /**
     * Login destination for a protected HTML page, or null for API paths.
     * /llaves* reuses the CRM session but has its own login page (F98).
     */
    private function loginPathFor(string $path): ?string
    {
        if ($path === '/panel' || strncmp($path, '/panel/', 7) === 0) {
            return '/panel/login';
        }
        if ($path === '/llaves' || strncmp($path, '/llaves/', 8) === 0) {
            return '/llaves/login';
        }
        return null;
    }

    /**
     * Fail: redirect browser to login, or throw for API calls.
     */
    private function fail(Request $request, ?string $loginPath, bool $clearCookie = false): Response
    {
        if ($loginPath !== null && $request->path === $loginPath) {
            // Don't redirect the login page itself; that would cause a loop
            throw new \App\Support\Errors\UnauthorizedException('CRM session required');
        }

        if ($loginPath !== null) {
            // Browser page view → redirect to the matching login page
            $response = new Response(302, ['Location' => $loginPath], '');
            if ($clearCookie) {
                $response->withCookie('crm_session', '', time() - 3600, '/');
            }
            return $response;
        }

        // API call → JSON error
        throw new \App\Support\Errors\UnauthorizedException('CRM session required');
    }
}
