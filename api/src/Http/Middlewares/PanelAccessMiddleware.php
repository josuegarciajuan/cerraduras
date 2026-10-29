<?php
declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Domain\Crm\CrmUser;
use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\ForbiddenException;
use App\Support\Errors\UnauthorizedException;

/**
 * PanelAccessMiddleware: restricts the technical panel (/panel) to the roles
 * that already had access (admin, operator, viewer).
 *
 * F98/RF-178.4/178.5: a key_manager must not open /panel. For browser
 * navigation it is redirected to /llaves keeping the session cookie intact;
 * JSON callers get a standard 403 envelope.
 *
 * Runs after CrmSessionMiddleware, so crm_user is normally present. The
 * defensive branch still redirects to the login page if it is missing.
 */
final class PanelAccessMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $user = $request->attr('crm_user');

        if (!$user instanceof CrmUser) {
            if ($this->wantsJson($request)) {
                throw new UnauthorizedException('CRM session required');
            }
            return new Response(302, ['Location' => '/panel/login'], '');
        }

        if (!$user->canAccessTechnicalPanel()) {
            if ($this->wantsJson($request)) {
                throw new ForbiddenException(
                    'forbidden',
                    'This role cannot access the technical panel'
                );
            }
            return new Response(302, ['Location' => '/llaves'], '');
        }

        return $next($request);
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('accept'));
        return strpos($accept, 'application/json') !== false;
    }
}
