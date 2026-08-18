<?php
declare(strict_types=1);

/**
 * bin/anomaly-scanner.php  (F35 / TSK-35.10)
 *
 * Periodic background worker that evaluates time-window-based anomalies
 * (A4, A7) and auto-resolves anomalies whose triggering condition has cleared.
 *
 * Runs in a loop every ~15 seconds.
 *
 * A4: Presence persistent without door activity.
 * A7: Door open without presence for too long.
 * Auto-resolve: close anomalies when condition is no longer met.
 *
 * Usage:
 *   php bin/anomaly-scanner.php
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Domain\Anomalies\AnomalyPipeline;
use App\Domain\Anomalies\AnomalyRepository;
use App\Domain\Anomalies\AnomalyService;
use App\Domain\Anomalies\Detectors\PresenceWithoutDoorActivity;
use App\Domain\Anomalies\Detectors\DoorOpenWithoutPresence;
use App\Domain\Anomalies\Detectors\ExitWithoutDoorOpen;
use App\Domain\Presence\IotSession;
use App\Domain\Presence\IotSessionRepository;
use App\Domain\Presence\PresenceEvent;
use App\Domain\Stays\StayRepository;
use App\Infrastructure\Db\PdoFactory;
use App\Support\Clock;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$pdo = PdoFactory::make();

// Repositories
$anomalyRepo   = new AnomalyRepository($pdo);
$iotSessionRepo = new IotSessionRepository($pdo);
$stayRepo       = new StayRepository($pdo);

// Read enabled anomaly types from system_settings (default: all enabled)
$anomalyEnabledTypes = ['A1','A2','A3','A4','A5','A6','A7','A8'];
$settingRow = $pdo->query("SELECT value FROM system_settings WHERE service='api' AND setting_key='anomaly.enabled_types'")->fetch(PDO::FETCH_ASSOC);
if ($settingRow && !empty($settingRow['value'])) {
    $decoded = json_decode($settingRow['value'], true);
    if (is_array($decoded)) $anomalyEnabledTypes = $decoded;
}
$enabled = function(string $type) use ($anomalyEnabledTypes): bool { return in_array($type, $anomalyEnabledTypes, true); };

// Periodic-only detectors (evaluated by this worker)
$a4Detector = new PresenceWithoutDoorActivity($pdo);
$a7Detector = new DoorOpenWithoutPresence();
$a5Detector = new ExitWithoutDoorOpen();

// Pipeline with A4 + A7 for periodic detection (only if enabled)
// (A1-A3, A6, A8 are event-driven, evaluated in IotSessionService)
$periodicDetectors = [];
if ($enabled('A4')) $periodicDetectors[] = $a4Detector;
if ($enabled('A7')) $periodicDetectors[] = $a7Detector;
$pipeline = new AnomalyPipeline($periodicDetectors);

$anomalyService = new AnomalyService($pipeline, $anomalyRepo, $a5Detector);

$tickInterval = 15;
$running = true;

// Graceful shutdown (Fase 2 — T2.8): finish current tick before exiting
pcntl_signal(SIGTERM, function () use (&$running) {
    echo "[anomaly-scanner] SIGTERM received — finishing current tick...\n";
    $running = false;
});
pcntl_signal(SIGINT, function () use (&$running) {
    echo "[anomaly-scanner] SIGINT received — finishing current tick...\n";
    $running = false;
});

echo "[anomaly-scanner] Starting (tick={$tickInterval}s)...\n";

while ($running) {
    $ts = date('Y-m-d H:i:s');
    $detected = 0;
    $resolved = 0;

    try {
        // Find all rooms with an active IoT session
        $rows = $pdo->query(
            "SELECT s.room_id, s.stay_id, s.door_state, s.presence_state,
                    s.last_open_at, s.last_close_at, s.last_absent_since, s.exit_evaluated_at
             FROM iot_sessions s
             INNER JOIN rooms r ON r.id = s.room_id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $roomId = (int) $row['room_id'];
            $stayId = isset($row['stay_id']) ? (int) $row['stay_id'] : null;

            try {
                // Build IotSession from DB row
                $session = new IotSession(
                    0, $roomId, $stayId,
                    (string) ($row['door_state'] ?? IotSession::DOOR_UNKNOWN),
                    (string) ($row['presence_state'] ?? IotSession::PRESENCE_UNKNOWN),
                    $row['last_open_at'] ?? null,
                    $row['last_close_at'] ?? null,
                    $row['last_absent_since'] ?? null,
                    $row['exit_evaluated_at'] ?? null,
                    date('Y-m-d H:i:s')
                );

                // Find active stay if any
                $stay = null;
                if ($stayId !== null) {
                    $stay = $stayRepo->findById($stayId);
                }

                // Evaluate periodic detectors (A4 + A7) using a dummy trigger event.
                // These detectors only look at the IotSession state + stay, not the trigger.
                $dummyTrigger = new PresenceEvent(
                    0, $roomId, 'SCANNER', 'SCAN', 'SYSTEM',
                    date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
                    null, null
                );

                $results = $pipeline->evaluate($session, $dummyTrigger, $stay);
                $now = Clock::nowUtc()->format('Y-m-d H:i:s.v');

                foreach ($results as $r) {
                    $existing = $anomalyRepo->findOpenByRoomAndType($roomId, $r->type);
                    if ($existing !== null) {
                        continue; // Already detected
                    }

                    $anomaly = new \App\Domain\Anomalies\Anomaly(
                        0, $roomId, $stayId, $r->type, $r->severity,
                        \App\Domain\Anomalies\Anomaly::STATUS_OPEN,
                        $r->contextData, $now,
                        null, null, null, null, $now, $now
                    );
                    $anomalyRepo->save($anomaly);
                    $detected++;
                    echo "[anomaly-scanner] [$ts] DETECTED {$r->type} room={$roomId}\n";
                }

                // Auto-resolve open anomalies
                $resolved += $anomalyService->autoResolveForRoom($roomId, $session, $stay);

            } catch (\Throwable $e) {
                echo "[anomaly-scanner] [$ts] ERROR room={$roomId}: " . $e->getMessage() . "\n";
            }
        }
    } catch (\Throwable $e) {
        echo "[anomaly-scanner] [$ts] FATAL: " . $e->getMessage() . "\n";
    }

    if ($detected > 0 || $resolved > 0) {
        echo "[anomaly-scanner] [$ts] Detected {$detected} new, resolved {$resolved}\n";
    }

    sleep($tickInterval);
    // Reconnect if the DB connection was lost (MySQL restart, timeout, etc.)
    PdoFactory::ping();
}

echo "[anomaly-scanner] Shutdown complete.\n";
