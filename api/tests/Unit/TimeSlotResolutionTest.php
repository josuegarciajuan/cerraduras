<?php
declare(strict_types=1);

/**
 * Unit test: TimeSlotService::currentKind() resolves the RENTABLE/FREE kind
 * at a given UTC instant, converting to the hotel local TZ first.
 *
 * Uses a fake repository to isolate the service from the database.
 *
 * Run:
 *   php tests/Unit/TimeSlotResolutionTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\TimeSlots\TimeSlot;
use App\Domain\TimeSlots\TimeSlotRepositoryInterface;
use App\Domain\TimeSlots\TimeSlotService;
use App\Support\Config;

// Force the test to think the hotel lives in Europe/Madrid.
putenv('APP_TZ=Europe/Madrid');
Config::load('/dev/null'); // loads nothing; getenv fallback still works

// Fake repo: returns a fixed list of slots regardless of room_type_id.
$fakeRepo = new class implements TimeSlotRepositoryInterface {
    /** @var list<TimeSlot> */
    public array $slots = [];

    public function listForRoomType(int $roomTypeId): array
    {
        return $this->slots;
    }

    public function replaceAll(int $roomTypeId, array $slots): void
    {
        $this->slots = array_values($slots);
    }
};

$service = new TimeSlotService($fakeRepo);

// Slot set: FREE 00:00-06:00, RENTABLE 06:00-24:00 (local time).
$fakeRepo->slots = [
    new TimeSlot(0, 360, 'FREE'),
    new TimeSlot(360, 1440, 'RENTABLE'),
];

$failed = 0;
$passed = 0;
function check(string $label, ?string $got, ?string $expected): void {
    global $failed, $passed;
    if ($got === $expected) {
        echo "  PASS  {$label} (got " . var_export($got, true) . ")\n";
        $passed++;
    } else {
        echo "  FAIL  {$label}: got " . var_export($got, true) . ", expected " . var_export($expected, true) . "\n";
        $failed++;
    }
}

// Europe/Madrid in winter is UTC+1. Local 03:30 == UTC 02:30.
$utcWinter_0330local = new DateTimeImmutable('2026-02-01T02:30:00+00:00');
check('winter local 03:30 -> FREE', $service->currentKind(1, $utcWinter_0330local), 'FREE');

// Winter local 08:00 == UTC 07:00.
$utcWinter_0800local = new DateTimeImmutable('2026-02-01T07:00:00+00:00');
check('winter local 08:00 -> RENTABLE', $service->currentKind(1, $utcWinter_0800local), 'RENTABLE');

// Summer (DST): Europe/Madrid is UTC+2. Local 05:30 == UTC 03:30.
$utcSummer_0530local = new DateTimeImmutable('2026-07-01T03:30:00+00:00');
check('summer local 05:30 -> FREE', $service->currentKind(1, $utcSummer_0530local), 'FREE');

// Summer local 06:00 == UTC 04:00 -> boundary, should be RENTABLE (inclusive start).
$utcSummer_0600local = new DateTimeImmutable('2026-07-01T04:00:00+00:00');
check('summer local 06:00 -> RENTABLE (boundary)', $service->currentKind(1, $utcSummer_0600local), 'RENTABLE');

// No slots defined -> null.
$fakeRepo->slots = [];
check('no slots -> null', $service->currentKind(1, $utcWinter_0330local), null);

// Slots with a gap -> null inside the gap, correct outside.
$fakeRepo->slots = [
    new TimeSlot(0, 300, 'FREE'),      // 00:00-05:00
    new TimeSlot(420, 1440, 'RENTABLE') // 07:00-24:00 (gap 05:00-07:00)
];
// Local 06:00 in winter == UTC 05:00 -> in the gap.
$utcInGap = new DateTimeImmutable('2026-02-01T05:00:00+00:00');
check('in gap -> null', $service->currentKind(1, $utcInGap), null);

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
