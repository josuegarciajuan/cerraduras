<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Db\PdoFactory;
use App\Support\Clock;
use App\Support\Config;

/**
 * HealthController: exposes GET /api/v1/health and GET /api/v1/health/deep.
 *
 * - No authentication required (contracts.md §2.1).
 * - /health returns basic status + optional DB ping.
 * - /health/deep returns component-level status for monitoring.
 */
final class HealthController
{
    public function show(Request $request): Response
    {
        $health = [
            'status'  => 'ok',
            'version' => Config::get('APP_VERSION', '1.0.0-dev'),
            'time'    => Clock::nowIsoUtcMillis(),
            'app'     => Config::get('APP_NAME', 'api'),
        ];

        // Optional DB connectivity check (Fase 2 — T2.1)
        if (Config::getBool('HEALTH_DB_PING', false)) {
            try {
                $pdo = PdoFactory::make();
                $pdo->query('SELECT 1');
                $health['database'] = 'connected';
            } catch (\Throwable $e) {
                $health['database'] = 'error';
            }
        }

        return Response::json(200, $health);
    }

    /**
     * Deep health check: verifies each subsystem individually.
     * Used by monitoring dashboards and load balancers.
     *
     * GET /api/v1/health/deep (no auth)
     */
    public function deep(Request $request): Response
    {
        $checks = [];
        $allOk  = true;

        // 1. Database
        try {
            $pdo = PdoFactory::make();
            $pdo->query('SELECT 1');
            $checks['database'] = ['status' => 'ok'];
        } catch (\Throwable $e) {
            $checks['database'] = ['status' => 'error', 'message' => $e->getMessage()];
            $allOk = false;
        }

        // 2. Outbox failed items (T2.10 — alerta si hay FAILED items)
        try {
            $stmt = $pdo->query(
                "SELECT COUNT(*) AS cnt FROM outbox_vb6 WHERE status = 'FAILED' AND updated_at < NOW() - INTERVAL 1 HOUR"
            );
            $failedCount = (int) $stmt->fetchColumn();
            $checks['outbox_failed'] = [
                'status' => $failedCount === 0 ? 'ok' : 'warning',
                'count'  => $failedCount,
                'note'   => $failedCount > 0 ? "$failedCount items in FAILED state for >1h" : null,
            ];
            if ($failedCount > 0) { $allOk = false; }
        } catch (\Throwable $e) {
            $checks['outbox_failed'] = ['status' => 'error', 'message' => $e->getMessage()];
            $allOk = false;
        }

        // 3. Background workers (pgrep-based)
        $workerNames = [
            'exit-scan'        => 'php.*bin/exit-scan',
            'overstay-scan'    => 'php.*bin/overstay-scan',
            'anomaly-scanner'  => 'php.*bin/anomaly-scanner',
            'outbox-worker'    => 'php.*bin/outbox-worker',
            'pulsar-consumer'  => 'node.*index.js',
            'presence-poller'  => 'node.*tuya-presence-poller',
        ];

        foreach ($workerNames as $name => $pattern) {
            exec("pgrep -f '{$pattern}'", $output, $exitCode);
            $running = $exitCode === 0 && count($output) > 0;
            $checks['workers'][$name] = $running ? 'running' : 'stopped';
            if (!$running) { $allOk = false; }
        }

        // 4. WS-VB6 bridge
        $wsvb6Url = Config::get('WSVB6_BASE_URL', 'http://127.0.0.1:8081/ws-vb6/v1');
        $wsvb6Health = $wsvb6Url . '/health';
        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        $wsvb6Result = @file_get_contents($wsvb6Health, false, $ctx);
        $checks['ws_vb6'] = $wsvb6Result !== false ? 'reachable' : 'unreachable';
        if ($wsvb6Result === false) { $allOk = false; }

        // 5. Battery monitoring (Fase 3 — T3.2)
        // Alert if any PROXIMITY sensor (MC400D) has low battery (<20%)
        try {
            $totalStmt = $pdo->query(
                "SELECT COUNT(*) FROM devices WHERE kind = 'PROXIMITY' AND battery_pct IS NOT NULL"
            );
            $totalDevices = (int) $totalStmt->fetchColumn();

            $stmt = $pdo->query(
                "SELECT d.id, d.label, r.code AS room_code, d.battery_pct
                 FROM devices d
                 JOIN rooms r ON r.pack_id = d.pack_id
                 WHERE d.kind = 'PROXIMITY'
                   AND d.battery_pct IS NOT NULL
                   AND d.battery_pct < 20
                 ORDER BY d.battery_pct ASC"
            );
            $lowBattery = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $criticalCount = count(array_filter($lowBattery, fn($d) => ($d['battery_pct'] ?? 100) < 10));

            $checks['battery'] = [
                'status'        => $criticalCount > 0 ? 'error' : (count($lowBattery) > 0 ? 'warning' : 'ok'),
                'total_devices' => $totalDevices,
                'devices_low'   => $lowBattery,
            ];
            if ($criticalCount > 0 || count($lowBattery) > 0) { $allOk = false; }
        } catch (\Throwable $e) {
            $checks['battery'] = ['status' => 'error', 'message' => $e->getMessage()];
            $allOk = false;
        }

        // 6. Circuit breaker status (Fase 3 — T3.2)
        if (class_exists('App\Support\Resilience\CircuitBreaker')) {
            try {
                $breaker = new \App\Support\Resilience\CircuitBreaker('tuya-api');
                $checks['circuit_breaker'] = $breaker->getInfo();
            } catch (\Throwable $e) {
                $checks['circuit_breaker'] = ['state' => 'unknown', 'error' => $e->getMessage()];
            }
        }

        $httpStatus = 200;  // Always 200 — the 'status' field carries healthy/degraded
        return Response::json($httpStatus, [
            'status' => $allOk ? 'healthy' : 'degraded',
            'time'   => Clock::nowIsoUtcMillis(),
            'checks' => $checks,
        ]);
    }
}
