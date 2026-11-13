<?php
declare(strict_types=1);

/**
 * bin/exit-scan.php  (F28 / TSK-F28-4)
 *
 * Periodic background worker that evaluates the exit rule for all rooms
 * with an OCCUPIED stay. Runs in a loop every ~5 seconds.
 *
 * This is necessary because Tuya/ESP32 sensors only send events on state
 * changes. After the last "ABSENT" event, no further events arrive, and
 * the gap expiry would never be detected without a periodic evaluator.
 *
 * Usage:
 *   php bin/exit-scan.php
 *   (added to start-all.sh for automatic startup)
 *
 * Dependencies:
 *   - ExitRuleEvaluator::shouldExit() (loads IoT session + room type from repos)
 *   - ExitActionService::execute() (side-effects: lock, room FREE, light OFF, ...)
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Domain\Devices\DeviceRepository;
use App\Domain\Devices\SwitchService;
use App\Domain\Locks\AccessEventRepository;
use App\Domain\Presence\ExitActionService;
use App\Domain\Presence\ExitRuleEvaluator;
use App\Domain\Presence\IotSessionRepository;
use App\Domain\Presence\IotSessionService;
use App\Domain\Presence\PresenceEventRepository;
use App\Domain\Sensors\RadarPresenceReportRepository;
use App\Domain\Rooms\RoomRepository;
use App\Domain\Rooms\RoomTypeRepository;
use App\Domain\Stays\StayRepository;
use App\Domain\Stays\StayStateMachine;
use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$pdo = PdoFactory::make();

// Repositories
$roomRepo        = new RoomRepository($pdo);
$iotSessionRepo  = new IotSessionRepository($pdo);
$roomTypeRepo    = new RoomTypeRepository($pdo);
$stayRepo        = new StayRepository($pdo);
$accessEventRepo = new AccessEventRepository($pdo);
$deviceRepo      = new DeviceRepository($pdo);

// Services
$stateMachine = new StayStateMachine($stayRepo);
$exitEval     = new ExitRuleEvaluator($iotSessionRepo, $roomRepo, $roomTypeRepo, $stayRepo);
$switchSvc    = new SwitchService($deviceRepo, $roomRepo, new \App\Domain\Presence\RoomAutomationPauseRepository($pdo));
// F41: exit-scan only delegates the idempotent evaluation; it never writes
// the sensor state itself (RF-48.3 / RF-43.2).
$exitAction   = new ExitActionService(
    $roomRepo, $iotSessionRepo, $stateMachine, $accessEventRepo, $switchSvc,
    null, null, $stayRepo, $exitEval, $roomTypeRepo
);

$tickInterval = 1; // Bug 1: 1 s para que la salida no sume hasta 5 s al guard de 3 s
$running = true;

// Graceful shutdown (Fase 2 — T2.8): finish current tick before exiting
pcntl_signal(SIGTERM, function () use (&$running) {
    echo "[exit-scan] SIGTERM received — finishing current tick...\n";
    $running = false;
});
pcntl_signal(SIGINT, function () use (&$running) {
    echo "[exit-scan] SIGINT received — finishing current tick...\n";
    $running = false;
});

echo "[exit-scan] Starting (tick={$tickInterval}s)...\n";

while ($running) {
    $ts = date('Y-m-d H:i:s');
    $scanned = 0;
    $exited  = 0;

    try {
        // Fetch all OCCUPIED stays
        $stmt = $pdo->query(
            "SELECT s.id, s.room_id FROM stays s WHERE s.status = 'OCCUPIED'"
        );
        $occupiedRows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        foreach ($occupiedRows as $row) {
            $scanned++;
            $roomId = (int) $row['room_id'];

            try {
                // F41: re-locks and re-validates the precondition atomically;
                // returns true only for the worker that claims the cycle.
                if ($exitAction->executeIfPending($roomId, 'exit-scan-' . uniqid())) {
                    $exited++;
                    echo "[exit-scan] [$ts] EXIT confirmed: room={$roomId}\n";
                }
            } catch (\Throwable $e) {
                echo "[exit-scan] [$ts] ERROR room={$roomId}: " . $e->getMessage() . "\n";
            }
        }
    } catch (\Throwable $e) {
        echo "[exit-scan] [$ts] FATAL: " . $e->getMessage() . "\n";
    }

    // F132: resolver transiciones de presencia pendientes (entrada/ausencia)
    // cuyo plazo ya venció. No consulta el dispositivo: revalida la evidencia
    // persistida y aplica el estado con el mismo bloqueo que la ingesta.
    try {
        $pendingRows = $pdo->query(
            "SELECT room_id FROM iot_sessions
              WHERE presence_pending_state IS NOT NULL
                AND presence_pending_due_at IS NOT NULL
                AND presence_pending_due_at <= UTC_TIMESTAMP(3)"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if ($pendingRows !== []) {
            $pendingService = new IotSessionService(
                new PresenceEventRepository($pdo),
                $iotSessionRepo,
                $roomRepo,
                $roomTypeRepo,
                $stayRepo,
                $stateMachine,
                $accessEventRepo,
                $exitEval,
                null,
                $exitAction,
                null,
                null,
                null,
                null,
                new RadarPresenceReportRepository($pdo)
            );
            foreach ($pendingRows as $pendingRow) {
                $pendingRoom = (int) $pendingRow['room_id'];
                try {
                    $resolved = $pendingService->resolvePendingPresence(
                        $pendingRoom,
                        'exit-scan-pending-' . uniqid()
                    );
                    if (($resolved['result'] ?? '') === 'applied') {
                        echo "[exit-scan] [$ts] PRESENCE PENDING aplicado room={$pendingRoom}\n";
                    }
                } catch (\Throwable $e) {
                    echo "[exit-scan] [$ts] PENDING ERROR room={$pendingRoom}: " . $e->getMessage() . "\n";
                }
            }
        }
    } catch (\Throwable $e) {
        echo "[exit-scan] [$ts] PENDING FATAL: " . $e->getMessage() . "\n";
    }

    // F114+: apagado diferido de la luz del almacén. Solo BD local; el
    // comando de luz se envía una vez vencido el plazo y si la sesión sigue
    // en ABSENT. Un PRESENT/OPEN cancela el plazo antes de que venza.
    try {
        if (\App\Support\Config::getBool('WAREHOUSE_LIGHT_AUTOMATION', true)) {
            $due = $pdo->query(
                "SELECT ws.room_id, s.presence_state
                   FROM warehouse_state ws
                   LEFT JOIN iot_sessions s ON s.room_id = ws.room_id
                  WHERE ws.light_off_at IS NOT NULL
                    AND ws.light_off_at <= UTC_TIMESTAMP(3)"
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($due as $lightRow) {
                $lightRoom = (int) $lightRow['room_id'];
                $presence = (string) ($lightRow['presence_state'] ?? '');
                if ($presence === 'ABSENT') {
                    $result = $switchSvc->turnOff($lightRoom);
                    echo "[exit-scan] [$ts] LIGHT OFF room={$lightRoom} ok=" . (($result['ok'] ?? false) ? '1' : '0') . "\n";
                } else {
                    echo "[exit-scan] [$ts] LIGHT OFF cancelado room={$lightRoom} presence={$presence}\n";
                }
                $pdo->prepare('UPDATE warehouse_state SET light_off_at=NULL WHERE room_id=:r')
                    ->execute([':r' => $lightRoom]);
            }
        } else {
            // Rollback: sin automatización, no debe quedar ningún plazo vivo.
            $pdo->exec('UPDATE warehouse_state SET light_off_at=NULL WHERE light_off_at IS NOT NULL');
        }
    } catch (\Throwable $e) {
        echo "[exit-scan] [$ts] LIGHT ERROR: " . $e->getMessage() . "\n";
    }

    if ($scanned > 0) {
        echo "[exit-scan] [$ts] Scanned {$scanned} room(s), {$exited} exit(s) confirmed\n";
    }

    sleep($tickInterval);
    // Reconnect if the DB connection was lost (MySQL restart, timeout, etc.)
    PdoFactory::ping();
}

echo "[exit-scan] Shutdown complete.\n";
