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
 *  - For browser page views (paths starting /panel): redirects to /panel/login on failure.
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
        $isPanelPage = strncmp($request->path, '/panel', 6) === 0;

        // --- No session cookie ---
        if ($token === null || $token === '') {
            return $this->fail($request, $isPanelPage);
        }

        // --- Validate token ---
        $userId = $this->sessionRepo->validate($token);
        if ($userId === null) {
            return $this->fail($request, $isPanelPage, true);
        }

        // --- User still active? ---
        $user = $this->userRepo->findById($userId);
        if ($user === null || !$user->active) {
            $this->sessionRepo->destroy($token);
            return $this->fail($request, $isPanelPage, true);
        }

        $request->withAttr('crm_user', $user);
        $request->withAttr('crm_session_token', $token);

        return $next($request);
    }

    /**
     * Fail: redirect browser to login, or throw for API calls.
     */
    private function fail(Request $request, bool $isPanelPage, bool $clearCookie = false): Response
    {
        if ($isPanelPage && $request->path === '/panel/login') {
            // Don't redirect the login page itself; that would cause a loop
            throw new \App\Support\Errors\UnauthorizedException('CRM session required');
        }

        if ($isPanelPage) {
            // Browser page view → redirect to login
            $response = new Response(302, ['Location' => '/panel/login'], '');
            if ($clearCookie) {
                $response->withCookie('crm_session', '', time() - 3600, '/');
            }
            return $response;
        }

        // API call → JSON error
        throw new \App\Support\Errors\UnauthorizedException('CRM session required');
    }
}
