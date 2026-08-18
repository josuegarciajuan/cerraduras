<?php
declare(strict_types=1);

/**
 * bin/overstay-scan.php  (TSK-114)
 *
 * Periodic CLI job (run via cron every minute) that detects OCCUPIED stays
 * whose predicted end time + grace period has passed, and creates overstay
 * debts via DebtsService.
 *
 * Idempotent: running twice for the same stay produces at most one debt
 * (DebtsService::createIfNeeded() is idempotent by design).
 *
 * Usage:
 *   php bin/overstay-scan.php
 *
 * Crontab example (every minute):
 *   * * * * * cd /path/to/api && php bin/overstay-scan.php >> logs/overstay-scan.log 2>&1
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Domain\Debts\DebtRepository;
use App\Domain\Debts\DebtsService;
use App\Domain\Debts\OutboxVb6Repository;
use App\Domain\Debts\OverstayCalculator;
use App\Domain\Rooms\RoomTypeRepository;
use App\Domain\Stays\StayRepository;
use App\Domain\Stays\StayStateMachine;
use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$pdo = PdoFactory::make();

$debtRepo    = new DebtRepository($pdo);
$stayRepo    = new StayRepository($pdo);
$rtRepo      = new RoomTypeRepository($pdo);
$outbox      = new OutboxVb6Repository($pdo);
$calculator  = new OverstayCalculator();
$stateMachine = new StayStateMachine($stayRepo);
$service     = new DebtsService($debtRepo, $stayRepo, $stateMachine, $calculator, $outbox, $pdo);

$ts      = date('Y-m-d H:i:s');
$created = 0;
$skipped = 0;
$errors  = 0;

echo "[{$ts}] overstay-scan: starting\n";

// Fetch OCCUPIED stays whose first_entry_at + duracion_minutos is in the past.
// Grace is applied per room_type inside DebtsService.
$overdue = $debtRepo->findOverdueOccupied();
echo "[{$ts}] overstay-scan: " . count($overdue) . " overdue stay(s) found\n";

foreach ($overdue as $row) {
    $stayId    = (int) $row['stay_id'];
    $roomTypeId = (int) $row['room_type_id'];

    try {
        $stay     = $stayRepo->findById($stayId);
        $roomType = $rtRepo->findById($roomTypeId);

        if ($stay === null || $roomType === null) {
            echo "[{$ts}] overstay-scan: SKIP stay={$stayId} (stay or room_type not found)\n";
            $skipped++;
            continue;
        }

        $debt = $service->createIfNeeded($stay, $roomType);

        if ($debt !== null) {
            echo "[{$ts}] overstay-scan: DEBT created stay={$stayId} debt_id={$debt->id} exceso={$debt->excesoMinutos}min\n";
            $created++;
        } else {
            echo "[{$ts}] overstay-scan: SKIP stay={$stayId} (no overstay or debt already exists)\n";
            $skipped++;
        }
    } catch (\Throwable $e) {
        echo "[{$ts}] overstay-scan: ERROR stay={$stayId}: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "[{$ts}] overstay-scan: done — created={$created} skipped={$skipped} errors={$errors}\n";
exit($errors > 0 ? 1 : 0);
