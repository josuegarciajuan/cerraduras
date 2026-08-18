<?php
declare(strict_types=1);

namespace Ws\Http\Controllers;

use Ws\Http\Request;
use Ws\Http\Response;
use Ws\Support\Clock;
use Ws\Support\Config;
use Ws\Support\Db\PdoFactory;

/**
 * HealthController (TSK-123): GET /ws-vb6/v1/health
 *
 * Verifies:
 *   - VB6 DB (bs2026) connectivity
 *   - AUX DB connectivity
 *
 * Returns 200 when both are reachable, 503 otherwise.
 */
final class HealthController
{
    public function show(Request $request): Response
    {
        $status  = 'ok';
        $checks  = [];
        $code    = 200;

        // VB6 DB check
        try {
            $vb6 = PdoFactory::vb6();
            $v   = $vb6->query('SELECT VERSION() AS v')->fetchColumn();
            $checks['vb6_db'] = ['status' => 'ok', 'server_version' => $v];
        } catch (\Throwable $e) {
            $checks['vb6_db'] = ['status' => 'error', 'error' => $e->getMessage()];
            $status = 'degraded';
            $code   = 503;
        }

        // AUX DB check
        try {
            $aux = PdoFactory::aux();
            $v   = $aux->query('SELECT VERSION() AS v')->fetchColumn();
            $checks['aux_db'] = ['status' => 'ok', 'server_version' => $v];
        } catch (\Throwable $e) {
            $checks['aux_db'] = ['status' => 'error', 'error' => $e->getMessage()];
            $status = 'degraded';
            $code   = 503;
        }

        return Response::json($code, [
            'status'  => $status,
            'service' => 'ws-vb6',
            'version' => '1.0.0',
            'time'    => Clock::nowUtc()->format(DATE_ATOM),
            'checks'  => $checks,
        ]);
    }
}
