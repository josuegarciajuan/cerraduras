<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Crm\CrmUserService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Config;
use App\Support\Json\JsonBody;

final class CrmController
{
    private CrmUserService $userService;

    public function __construct(CrmUserService $userService)
    {
        $this->userService = $userService;
    }

    // -----------------------------------------------------------------------
    // Auth
    // -----------------------------------------------------------------------

    /**
     * POST /api/v1/crm/login
     * Public: no auth required.
     */
    public function login(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);
        $username = JsonBody::string($body, 'username', 64);
        $password = JsonBody::string($body, 'password', 128);

        $result = $this->userService->login($username, $password, $request->remoteIp);

        $ttl = 28800; // 8 hours
        $response = Response::json(200, [
            'user'       => $result['user'],
            'expires_in' => $ttl,
        ]);

        // Set session cookie
        $response->withCookie(
            'crm_session',
            $result['token'],
            time() + $ttl,
            '/',
            '',
            false,   // secure (false for LAN HTTP)
            true,    // httpOnly
            'Lax'
        );

        return $response;
    }

    /**
     * POST /api/v1/crm/logout
     * Requires valid CRM session cookie.
     */
    public function logout(Request $request): Response
    {
        $token = $request->cookie('crm_session');
        if ($token !== null) {
            $this->userService->logout($token);
        }

        $response = Response::json(200, ['ok' => true]);
        // Clear the cookie
        $response->withCookie('crm_session', '', time() - 3600, '/');
        return $response;
    }

    /**
     * GET /api/v1/crm/me
     * Returns current logged-in CRM user info.
     */
    public function me(Request $request): Response
    {
        /** @var \App\Domain\Crm\CrmUser|null $user */
        $user = $request->attr('crm_user');
        if ($user === null) {
            return Response::json(401, ['error' => ['code' => 'unauthorized', 'message' => 'Not authenticated']]);
        }
        return Response::json(200, ['user' => $user->toArray()]);
    }

    // -----------------------------------------------------------------------
    // Panel User CRUD (admin only — enforced via scopes)
    // -----------------------------------------------------------------------

    /**
     * GET /api/v1/crm/users
     */
    public function listUsers(Request $request): Response
    {
        $users = array_map(fn($u) => $u->toArray(), $this->userService->listAll());
        return Response::json(200, ['items' => $users]);
    }

    /**
     * GET /api/v1/crm/users/{id}
     */
    public function showUser(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        return Response::json(200, $this->userService->getOrFail($id)->toArray());
    }

    /**
     * POST /api/v1/crm/users
     */
    public function createUser(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);
        $user = $this->userService->create(
            JsonBody::string($body, 'username', 64),
            JsonBody::string($body, 'password', 128),
            JsonBody::string($body, 'role', 20),
            JsonBody::stringOpt($body, 'display_name', 128)
        );
        return Response::json(201, $user->toArray());
    }

    /**
     * PATCH /api/v1/crm/users/{id}
     */
    public function updateUser(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $body = JsonBody::require($request->jsonBody);

        $fields = [];
        foreach (['username', 'password', 'role', 'display_name', 'active'] as $k) {
            if (array_key_exists($k, $body)) {
                $fields[$k] = $body[$k];
            }
        }

        return Response::json(200, $this->userService->update($id, $fields)->toArray());
    }

    /**
     * DELETE /api/v1/crm/users/{id}
     */
    public function deleteUser(Request $request): Response
    {
        $id = (int) $request->routeParam('id');

        // Prevent deleting self
        $currentUser = $request->attr('crm_user');
        if ($currentUser && (int) $currentUser->id === $id) {
            return Response::json(400, ['error' => [
                'code' => 'bad_request',
                'message' => 'No puedes eliminar tu propio usuario'
            ]]);
        }

        $this->userService->delete($id);
        return Response::json(200, ['deleted' => true]);
    }

    // -----------------------------------------------------------------------
    // Panel HTML pages
    // -----------------------------------------------------------------------

    /**
     * GET /panel — Main CRM panel (SPA entry point)
     */
    public function panel(Request $request): Response
    {
        $html = $this->servePanelHtml('index.html');
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    /**
     * GET /panel/login — Login page
     */
    public function loginPage(Request $request): Response
    {
        $html = $this->servePanelHtml('login.html');
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    /**
     * GET /panel/* — Catch-all for panel static assets
     * Falls back to index.html for SPA routing
     */
    public function panelAsset(Request $request): Response
    {
        // Try to serve the requested file from public/panel/
        $path = $request->routeParam('path') ?? '';
        // Basic path traversal prevention
        if (strpos($path, '..') !== false) {
            return new Response(404, [], 'Not found');
        }

        $filePath = __DIR__ . '/../../../public/panel/' . $path;

        if ($path === '' || !is_file($filePath)) {
            // SPA fallback: serve index.html
            return $this->panel($request);
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $mime = [
            'html' => 'text/html; charset=utf-8',
            'css'  => 'text/css; charset=utf-8',
            'js'   => 'application/javascript; charset=utf-8',
            'svg'  => 'image/svg+xml',
            'png'  => 'image/png',
            'ico'  => 'image/x-icon',
        ][$ext] ?? 'application/octet-stream';

        return new Response(200, ['Content-Type' => $mime], @file_get_contents($filePath) ?: '');
    }

    private function servePanelHtml(string $filename): string
    {
        $filePath = __DIR__ . '/../../../public/panel/' . $filename;
        if (!is_file($filePath)) {
            return '<html><body><h1>Panel not found</h1><p>Make sure panel files exist in api/public/panel/</p></body></html>';
        }
        return @file_get_contents($filePath) ?: '';
    }
}
