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
use App\Domain\Sensors\RadarDistanceReportRepository;
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

// F133: sin async signals, pcntl_signal registra el handler pero no lo
// despacha dentro de sleep(); systemd agotaba el timeout y dejaba huérfanos.
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}

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
            "SELECT s.room_id FROM iot_sessions s
               JOIN rooms r ON r.id = s.room_id
               JOIN room_types rt ON rt.id = r.room_type_id
              WHERE s.presence_pending_state IS NOT NULL
                AND s.presence_pending_due_at IS NOT NULL
                AND s.presence_pending_due_at <= UTC_TIMESTAMP(3)
                AND rt.code <> 'STANDARD'"
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
                new RadarPresenceReportRepository($pdo),
                new RadarDistanceReportRepository($pdo)
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

    // F138: vencimientos del motor único de ocupación STANDARD.
    try {
        $standardDue = $pdo->query(
            "SELECT s.room_id, s.occupancy_state, s.light_desired
               FROM iot_sessions s
               JOIN rooms r ON r.id = s.room_id
               JOIN room_types rt ON rt.id = r.room_type_id
              WHERE rt.code = 'STANDARD'
                AND s.occupancy_state IS NOT NULL
                AND (
                  (s.entry_deadline_at IS NOT NULL AND s.entry_deadline_at <= UTC_TIMESTAMP(3))
                  OR (s.vacancy_deadline_at IS NOT NULL AND s.vacancy_deadline_at <= UTC_TIMESTAMP(3))
                  OR (s.light_off_at IS NOT NULL AND s.light_off_at <= UTC_TIMESTAMP(3)
                      AND s.light_desired = 'OFF')
                )"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if ($standardDue !== []) {
            $standardService = new IotSessionService(
                new PresenceEventRepository($pdo),
                $iotSessionRepo,
                $roomRepo,
                $roomTypeRepo,
                $stayRepo,
                $stateMachine,
                $accessEventRepo,
                $exitEval,
                $switchSvc,
                $exitAction,
                null,
                null,
                null,
                null,
                new RadarPresenceReportRepository($pdo),
                new RadarDistanceReportRepository($pdo)
            );
            foreach ($standardDue as $standardRow) {
                $standardRoom = (int) $standardRow['room_id'];
                try {
                    $resolved = $standardService->resolveStandardDeadlines(
                        $standardRoom,
                        'exit-scan-standard-' . uniqid()
                    );
                    $state = (string) ($resolved['occupancy_state'] ?? '');
                    $light = (string) ($resolved['light'] ?? '');
                    if ($state === 'VACANT_CONFIRMED' && $light === 'off') {
                        // Reclamo atómico: si llegó OPEN/PRESENT y la sesión
                        // dejó de estar vacía, no se publica el OFF.
                        $claim = $pdo->prepare(
                            "UPDATE iot_sessions
                                SET light_off_at = NULL,
                                    light_last_result = 'publishing_off',
                                    updated_at = UTC_TIMESTAMP(3)
                              WHERE room_id = :room
                                AND occupancy_state = 'VACANT_CONFIRMED'
                                AND light_desired = 'OFF'"
                        );
                        $claim->execute([':room' => $standardRoom]);
                        if ($claim->rowCount() === 0) {
                            echo "[exit-scan] [$ts] STANDARD LIGHT OFF cancelado room={$standardRoom} (estado cambió)\n";
                            continue;
                        }

                        $result = $switchSvc->turnOff($standardRoom);
                        $ok = (bool) ($result['ok'] ?? false);
                        $paused = (string) ($result['error'] ?? '') === 'automation_paused';
                        $pdo->prepare(
                            'UPDATE iot_sessions
                                SET light_last_result = :r,
                                    light_off_at = :off,
                                    updated_at = UTC_TIMESTAMP(3)
                              WHERE room_id = :room'
                        )->execute([
                            ':r' => $ok ? 'confirmed_off' : ($paused ? 'paused' : 'off_failed'),
                            ':off' => ($ok || $paused) ? null : gmdate('Y-m-d H:i:s', time() + 5) . '.000',
                            ':room' => $standardRoom,
                        ]);
                        echo "[exit-scan] [$ts] STANDARD LIGHT OFF room={$standardRoom} ok=" . ($ok ? '1' : '0') . "\n";
                    }
                } catch (\Throwable $e) {
                    echo "[exit-scan] [$ts] STANDARD DEADLINE ERROR room={$standardRoom}: " . $e->getMessage() . "\n";
                }
            }
        }
    } catch (\Throwable $e) {
        echo "[exit-scan] [$ts] STANDARD DEADLINE FATAL: " . $e->getMessage() . "\n";
    }

    // F137: apagado diferido de luz en salas normales con política radar.
    // IotSessionService escribe `iot_sessions.light_off_at` tras un ciclo de
    // puerta con ausencia; aquí se ejecuta el OFF y se limpia el plazo. Un
    // PRESENT/OPEN lo cancela antes de vencer y solo se limpia.
    try {
        if (\App\Support\Config::getBool('RADAR_ROOM_LIGHT_AUTOMATION', true)) {
            $dueRadar = $pdo->query(
                "SELECT s.room_id, s.presence_state
                   FROM iot_sessions s
                   JOIN rooms r ON r.id = s.room_id
                   JOIN room_types rt ON rt.id = r.room_type_id
                  WHERE s.light_off_at IS NOT NULL
                    AND s.light_off_at <= UTC_TIMESTAMP(3)
                     AND r.presence_mode_override IS NOT NULL
                     AND r.presence_mode_override <> ''
                     AND rt.code NOT IN ('ALMACEN_BEBIDAS','TIMBRADO','STANDARD')"
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($dueRadar as $lightRow) {
                $lightRoom = (int) $lightRow['room_id'];
                $presence = (string) ($lightRow['presence_state'] ?? '');
                if ($presence === 'ABSENT') {
                    $result = $switchSvc->turnOff($lightRoom);
                    echo "[exit-scan] [$ts] RADAR LIGHT OFF room={$lightRoom} ok=" . (($result['ok'] ?? false) ? '1' : '0') . "\n";
                } else {
                    echo "[exit-scan] [$ts] RADAR LIGHT OFF cancelado room={$lightRoom} presence={$presence}\n";
                }
                $pdo->prepare('UPDATE iot_sessions SET light_off_at=NULL WHERE room_id=:r')
                    ->execute([':r' => $lightRoom]);
            }
        } else {
            $pdo->exec('UPDATE iot_sessions SET light_off_at=NULL WHERE light_off_at IS NOT NULL');
        }
    } catch (\Throwable $e) {
        echo "[exit-scan] [$ts] RADAR LIGHT ERROR: " . $e->getMessage() . "\n";
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
