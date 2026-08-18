<?php
declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Domain\Auth\ApiClient;
use App\Domain\Auth\ApiClientRepository;
use App\Domain\Crm\CrmSessionRepository;
use App\Domain\Crm\CrmUserRepository;
use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\ForbiddenException;
use App\Support\Errors\UnauthorizedException;

/**
 * AuthApiKeyMiddleware:
 *  - Priority 1: If a valid crm_session cookie exists, auto-authenticate as a
 *    synthetic ADMIN client with all scopes (CRM panel user).
 *  - Priority 2: Reads the X-API-Key header, hashes with SHA-256, looks up an
 *    active api_clients row.
 *  - Enforces the optional IP whitelist.
 *  - Stores the resolved ApiClient under the "api_client" request attribute
 *    so downstream middlewares/handlers (AuthScope, controllers, audit) can
 *    access it.
 *
 * This middleware only AUTHENTICATES. Authorization per-scope is the job of
 * AuthScopeMiddleware, layered on top.
 */
final class AuthApiKeyMiddleware implements Middleware
{
    private ApiClientRepository $apiClientRepo;
    private ?CrmSessionRepository $crmSessionRepo;
    private ?CrmUserRepository $crmUserRepo;

    public function __construct(
        ApiClientRepository $apiClientRepo,
        ?CrmSessionRepository $crmSessionRepo = null,
        ?CrmUserRepository $crmUserRepo = null
    ) {
        $this->apiClientRepo   = $apiClientRepo;
        $this->crmSessionRepo  = $crmSessionRepo;
        $this->crmUserRepo     = $crmUserRepo;
    }

    public function handle(Request $request, callable $next): Response
    {
        // Priority 1: CRM session cookie (panel auth bypass)
        $client = $this->tryCrmSession($request);
        if ($client !== null) {
            $request->withAttr('api_client', $client);
            return $next($request);
        }

        // Priority 2: X-API-Key header (standard auth)
        $key = $request->header('x-api-key');
        if ($key === null || $key === '') {
            throw new UnauthorizedException('Missing X-API-Key header');
        }

        $hash = hash('sha256', $key);
        $client = $this->apiClientRepo->findActiveByKeyHash($hash);
        if ($client === null) {
            throw new UnauthorizedException('Invalid API key');
        }

        if (!$client->ipAllowed($request->remoteIp)) {
            throw new ForbiddenException(
                'ip_not_allowed',
                'Client IP is not in the whitelist',
                ['remote_ip' => $request->remoteIp]
            );
        }

        $request->withAttr('api_client', $client);
        return $next($request);
    }

    /**
     * If a valid crm_session cookie is present, authenticate as synthetic ADMIN.
     * Returns ApiClient or null.
     */
    private function tryCrmSession(Request $request): ?ApiClient
    {
        if ($this->crmSessionRepo === null || $this->crmUserRepo === null) {
            return null;
        }

        $token = $request->cookie('crm_session');
        if ($token === null || $token === '') {
            return null;
        }

        $userId = $this->crmSessionRepo->validate($token);
        if ($userId === null) {
            return null;
        }

        $user = $this->crmUserRepo->findById($userId);
        if ($user === null || !$user->active) {
            return null;
        }

        // Store CRM user in request attributes for audit/logging
        $request->withAttr('crm_user', $user);
        $request->withAttr('crm_session_token', $token);

        // Map CRM roles to scopes
        $scopes = $this->scopesForRole($user->role);

        return new ApiClient(
            0, // synthetic, no real api_client row
            'CRM-' . $user->username,
            'ADMIN',
            $scopes,
            null, // no IP whitelist for CRM
            true
        );
    }

    /** @return list<string> */
    private function scopesForRole(string $role): array
    {
        switch ($role) {
            case 'admin':
                // Full access: all scopes
                return [
                    'qr:issue', 'qr:validate', 'qr:revoke',
                    'rooms:read', 'rooms:write', 'rooms:state',
                    'time-slots:read', 'time-slots:write',
                    'locks:open', 'locks:lock', 'locks:override',
                    'presence:read', 'presence:write',
                    'switches:write',
                    'stays:read', 'stays:write',
                    'debts:read', 'debts:sync',
                    'audit:read',
                    'sim:*',
                    'vb6-bridge:read', 'vb6-bridge:write',
                ];
            case 'operator':
                // Operational: manage rooms, stays, QRs, devices — no system config
                return [
                    'qr:issue', 'qr:validate', 'qr:revoke',
                    'rooms:read', 'rooms:write', 'rooms:state',
                    'time-slots:read',
                    'locks:open', 'locks:lock',
                    'presence:read',
                    'switches:write',
                    'stays:read', 'stays:write',
                    'debts:read', 'debts:sync',
                    'audit:read',
                ];
            case 'viewer':
                // Read-only
                return [
                    'rooms:read',
                    'time-slots:read',
                    'presence:read',
                    'stays:read',
                    'debts:read',
                    'audit:read',
                ];
            default:
                return [];
        }
    }
}

