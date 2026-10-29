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
 * KeyDeskAccessMiddleware: restricts the key desk (/llaves) to key_manager
 * and admin (F98/RF-178.3).
 *
 * Runs after CrmSessionMiddleware: a missing/invalid session has already been
 * redirected to /llaves/login there. An authenticated role that is not allowed
 * here is redirected to /panel (/llaves pages only) or receives a standard 403
 * JSON envelope when the request explicitly accepts JSON.
 *
 * This middleware must NOT be applied to /llaves/login or /llaves/assets/*.
 */
final class KeyDeskAccessMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $user = $request->attr('crm_user');

        if (!$user instanceof CrmUser) {
            if ($this->wantsJson($request)) {
                throw new UnauthorizedException('CRM session required');
            }
            return new Response(302, ['Location' => '/llaves/login'], '');
        }

        if (!$user->canAccessKeyDesk()) {
            if ($this->wantsJson($request)) {
                throw new ForbiddenException(
                    'forbidden',
                    'This role cannot access the key desk'
                );
            }
            // Send roles that live in the technical panel there. Never
            // redirect to /llaves from here: it would be a self-loop.
            return new Response(302, ['Location' => '/panel'], '');
        }

        return $next($request);
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('accept'));
        return strpos($accept, 'application/json') !== false;
    }
}
