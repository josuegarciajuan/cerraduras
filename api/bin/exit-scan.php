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
use App\Domain\Presence\PresenceEventRepository;
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
$exitEval     = new ExitRuleEvaluator($iotSessionRepo, $roomRepo, $roomTypeRepo);
$switchSvc    = new SwitchService($deviceRepo, $roomRepo);
$exitAction   = new ExitActionService(
    $roomRepo, $iotSessionRepo, $stateMachine, $accessEventRepo, $switchSvc
);

$tickInterval = 5; // seconds between scans
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
            $stayId = (int) $row['id'];

            try {
                if ($exitEval->shouldExit($roomId)) {
                    // Load fresh objects for the side-effect execution
                    $room    = $roomRepo->findById($roomId);
                    $session = $iotSessionRepo->findByRoomId($roomId);
                    $stay    = $stayRepo->findById($stayId);
                    $rt      = $roomTypeRepo->findById($room->roomTypeId ?? 0);

                    if ($room !== null && $session !== null && $stay !== null
                        && $stay->status === 'OCCUPIED'
                    ) {
                        $exitAction->execute(
                            $room, $session, $stay, $rt,
                            'exit-scan-' . uniqid()
                        );
                        $exited++;
                        echo "[exit-scan] [$ts] EXIT confirmed: room={$roomId} stay={$stayId}\n";
                    }
                }
            } catch (\Throwable $e) {
                echo "[exit-scan] [$ts] ERROR room={$roomId}: " . $e->getMessage() . "\n";
            }
        }
    } catch (\Throwable $e) {
        echo "[exit-scan] [$ts] FATAL: " . $e->getMessage() . "\n";
    }

    if ($scanned > 0) {
        echo "[exit-scan] [$ts] Scanned {$scanned} room(s), {$exited} exit(s) confirmed\n";
    }

    sleep($tickInterval);
    // Reconnect if the DB connection was lost (MySQL restart, timeout, etc.)
    PdoFactory::ping();
}

echo "[exit-scan] Shutdown complete.\n";
