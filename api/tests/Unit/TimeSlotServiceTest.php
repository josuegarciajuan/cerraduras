<?php
declare(strict_types=1);

/**
 * Simple test harness for TimeSlotService::validateSet() and friends.
 *
 * No PHPUnit dependency yet (kept intentionally zero-vendor). Each assert
 * prints its result; exit code is non-zero on failure.
 *
 * Run:
 *   php tests/Unit/TimeSlotServiceTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\TimeSlots\TimeSlot;
use App\Domain\TimeSlots\TimeSlotService;
use App\Support\Errors\UnprocessableException;

// Minimal stub of the repository; validateSet doesn't need DB access.
$repoStub = new class {
    public function listForRoomType(int $rt): array { return []; }
    public function replaceAll(int $rt, array $s): void {}
};

// We can't pass a stub unless TimeSlotService uses an interface. For this
// unit test we only exercise the validator, which doesn't touch the repo.
// Build service with a real (unused) repo would need a PDO mock; instead we
// reach the validator via the instantiation through reflection.
$service = (new ReflectionClass(TimeSlotService::class))->newInstanceWithoutConstructor();

$failed = 0;
$passed = 0;
function assertTrue(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) {
        echo "  PASS  {$label}\n";
        $passed++;
    } else {
        echo "  FAIL  {$label}\n";
        $failed++;
    }
}

function expectThrows(callable $fn, string $expectedCode, string $label): void {
    global $passed, $failed;
    try {
        $fn();
        echo "  FAIL  {$label} (no exception)\n";
        $failed++;
    } catch (UnprocessableException $e) {
        if ($e->errorCode() === $expectedCode) {
            echo "  PASS  {$label} ({$expectedCode})\n";
            $passed++;
        } else {
            echo "  FAIL  {$label} (got code {$e->errorCode()}, expected {$expectedCode})\n";
            $failed++;
        }
    } catch (\Throwable $e) {
        echo "  FAIL  {$label} (unexpected " . get_class($e) . ": " . $e->getMessage() . ")\n";
        $failed++;
    }
}

echo "TimeSlotService::validateSet\n";

// Full-day RENTABLE is valid.
$slots = [new TimeSlot(0, 1440, 'RENTABLE')];
try {
    $service->validateSet($slots);
    assertTrue(true, 'single full-day RENTABLE accepted');
} catch (\Throwable $e) {
    assertTrue(false, 'single full-day RENTABLE accepted (got ' . $e->getMessage() . ')');
}

// Two adjacent slots that cover the day.
$slots = [
    new TimeSlot(0, 360, 'FREE'),      // 00:00-06:00
    new TimeSlot(360, 1440, 'RENTABLE'), // 06:00-24:00
];
try {
    $service->validateSet($slots);
    assertTrue(true, 'two adjacent full-day slots accepted');
} catch (\Throwable $e) {
    assertTrue(false, 'two adjacent full-day slots rejected: ' . $e->getMessage());
}

// Empty list rejected.
expectThrows(
    function () use ($service) { $service->validateSet([]); },
    'slots_not_full_day',
    'empty list rejected'
);

// Does not start at 00:00.
expectThrows(
    function () use ($service) {
        $service->validateSet([new TimeSlot(60, 1440, 'RENTABLE')]);
    },
    'slots_not_full_day',
    'non-zero start rejected'
);

// Does not end at 24:00.
expectThrows(
    function () use ($service) {
        $service->validateSet([new TimeSlot(0, 1380, 'RENTABLE')]);
    },
    'slots_not_full_day',
    'non-24 end rejected'
);

// Overlap detected.
expectThrows(
    function () use ($service) {
        $service->validateSet([
            new TimeSlot(0, 420, 'FREE'),       // 00:00-07:00
            new TimeSlot(360, 1440, 'RENTABLE'),// 06:00-24:00
        ]);
    },
    'slots_overlap',
    'overlap rejected'
);

// Gap detected.
expectThrows(
    function () use ($service) {
        $service->validateSet([
            new TimeSlot(0, 300, 'FREE'),       // 00:00-05:00
            new TimeSlot(420, 1440, 'RENTABLE'),// 07:00-24:00
        ]);
    },
    'slots_not_full_day',
    'gap rejected'
);

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
