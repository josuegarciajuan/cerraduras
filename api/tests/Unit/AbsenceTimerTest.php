<?php
declare(strict_types=1);

/**
 * Unit tests for absence timer (RF-30 / F28).
 *
 * Verifies IotSessionService sets lastAbsentSince when door closes
 * while presence is ABSENT, so the countdown starts and exit_deadline
 * can be computed by RoomLiveController.
 *
 * Run:
 *   php tests/Unit/AbsenceTimerTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\IotSession;
use App\Domain\Presence\IotSessionService;
use App\Domain\Presence\IotSessionRepositoryInterface;
use App\Domain\Presence\PresenceEvent;
use App\Domain\Presence\PresenceEventRepositoryInterface;
use App\Domain\Presence\ExitRuleEvaluator;
use App\Domain\Presence\ExitActionService;
use App\Domain\Rooms\Room;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Rooms\RoomType;
use App\Domain\Rooms\RoomTypeRepositoryInterface;
use App\Domain\Stays\Stay;
use App\Domain\Stays\StayRepositoryInterface;
use App\Domain\Stays\StayStateMachine;
use App\Domain\Locks\AccessEvent;
use App\Domain\Locks\AccessEventRepositoryInterface;
use App\Domain\Devices\SwitchService;
use App\Support\Clock;

// ============================================================================
// Minimal fakes
// ============================================================================

final class FakeIotSessionRepo implements IotSessionRepositoryInterface
{
    public ?IotSession $lastSaved = null;
    public ?IotSession $existing = null;

    private function makeSession(int $roomId): IotSession
    {
        return new IotSession(
            0, $roomId, null,
            IotSession::DOOR_CLOSED,
            IotSession::PRESENCE_ABSENT,
            null, null, null, '', ''
        );
    }

    public function findByRoomId(int $roomId): ?IotSession
    {
        if ($this->existing) return $this->existing;
        return $this->makeSession($roomId);
    }

    public function save(IotSession $session): void
    {
        $this->lastSaved = clone $session;
    }

    public function upsert(IotSession $session): void
    {
        $this->lastSaved = clone $session;
    }
}

final class FakePresenceEventRepo implements PresenceEventRepositoryInterface
{
    /** @var array<string,mixed>|null */
    public ?array $lastInserted = null;

    public function insert(
        int     $roomId,
        string  $sensor,
        string  $value,
        string  $provider,
        string  $occurredAt,
        ?string $sourceEventId,
        ?array  $meta
    ): ?int {
        $this->lastInserted = compact('roomId','sensor','value','occurredAt','sourceEventId');
        return 1;
    }

    public function listForRoom(int $roomId, int $limit = 20): array { return []; }
    /** @param array<string,mixed> $filters */
    public function listFiltered(array $filters, int $limit = 50): array { return []; }
}

final class FakeRoomRepo implements RoomRepositoryInterface
{
    public Room $room;

    public function findById(int $id): ?Room { return $this->room; }
    public function findByCode(string $code): ?Room { return null; }
    public function listFiltered(array $filters, int $limit = 50, int $offset = 0): array { return []; }
    public function insert(string $code, int $roomTypeId, ?bool $simulatedOverride): int { return 0; }
    public function update(int $id, array $fields, ?string $expectedStatus = null): int { return 0; }
    public function resetAfterPackRemoval(int $roomId): void {}
    public function count(array $filters): int { return 0; }
    public function findByPackId(int $packId): ?Room { return null; }
}

final class FakeRoomTypeRepo implements RoomTypeRepositoryInterface
{
    public RoomType $roomType;

    public function findById(int $id): ?RoomType { return $this->roomType; }
    public function findByCode(string $code): ?RoomType { return null; }
    public function listAll(): array { return []; }
    public function findWithSlots(int $id): ?RoomType { return null; }
    public function insert(string $code, string $name, int $graceMinutes, int $exitPresenceGapSeconds, int $reentryCooldownSeconds, int $qrUsageWindowMinutes): int { return 0; }
    public function update(int $id, array $fields, ?string $expectedStatus = null): int { return 0; }
    public function delete(int $id): void {}
}

final class FakeStayRepo implements StayRepositoryInterface
{
    public function insertReserved(int $roomId, int $duracionMinutos, array $vb6Refs): int { return 0; }
    public function findById(int $id): ?Stay { return null; }
    public function findActiveForRoom(int $roomId): ?Stay { return null; }
    public function listFiltered(array $filters, int $limit = 50, int $offset = 0): array { return []; }
    public function update(int $id, array $fields, ?string $expectedStatus = null): int { return 0; }
    public function count(array $filters): int { return 0; }
}

final class FakeAccessEventRepo implements AccessEventRepositoryInterface
{
    public function insert(int $roomId, ?int $stayId, string $kind, string $result, ?string $reason, string $provider, string $correlationId, ?array $meta, ?int $workerSessionId = null): int { return 0; }
    public function findById(int $id): ?AccessEvent { return null; }
    /** @return list<AccessEvent> */
    public function findByRoomId(int $roomId, int $limit = 50): array { return []; }
}

// ============================================================================
// Helpers
// ============================================================================

$passed = 0;
$failed = 0;

function pass(string $msg): void
{
    global $passed;
    $passed++;
    echo "  ✅ {$msg}\n";
}

function fail(string $msg): void
{
    global $failed;
    $failed++;
    echo "  ❌ {$msg}\n";
}

// ============================================================================
// Tests
// ============================================================================

echo "Absence Timer Tests (RF-30)\n";
echo str_repeat("=", 60) . "\n\n";

// Setup
$iotRepo = new FakeIotSessionRepo();
$presRepo = new FakePresenceEventRepo();
$roomRepo = new FakeRoomRepo();
$rtRepo = new FakeRoomTypeRepo();
$stayRepo = new FakeStayRepo();

// Room with presence_check_seconds=30 and room_type gap=15
$roomRepo->room = new Room(1, '101', 1, null, 'OCCUPIED', null, null, null, 30);
$rtRepo->roomType = new RoomType(1, 'STANDARD', '', 5, 15, 20, 30);

// Minimal ExitRuleEvaluator (real logic, fake repos)
$fakeSessionRepo = new class implements IotSessionRepositoryInterface {
    public ?IotSession $s = null;
    public function findByRoomId(int $roomId): ?IotSession { return $this->s; }
    public function save(IotSession $session): void {}
    public function upsert(IotSession $session): void {}
};
$exitEval = new ExitRuleEvaluator($fakeSessionRepo, $roomRepo, $rtRepo);

$svc = new IotSessionService(
    $presRepo, $iotRepo, $roomRepo, $rtRepo, $stayRepo,
    new StayStateMachine($stayRepo), new FakeAccessEventRepo(),
    $exitEval, null, null
);

// ─── Test 1: Door CLOSED + presence ABSENT → lastAbsentSince set ───
$event1 = [
    'room_id' => 1,
    'sensor' => PresenceEvent::SENSOR_PROXIMITY,
    'value' => PresenceEvent::VALUE_CLOSED,
    'provider' => 'TUYA',
    'occurred_at' => Clock::nowUtc()->format('Y-m-d\TH:i:s\Z'),
    'source_event_id' => 'test-1-' . uniqid(),
    'meta' => null,
];

$iotRepo->lastSaved = null;
$result = $svc->processEvent($event1, 'corr-1');
$saved = $iotRepo->lastSaved;

if ($saved !== null && $saved->lastAbsentSince !== null) {
    pass('Door CLOSED + ABSENT → lastAbsentSince set');
} else {
    fail('Door CLOSED + ABSENT → lastAbsentSince should be set, got ' . ($saved ? $saved->lastAbsentSince : 'null'));
}

// ─── Test 2: Door CLOSED + presence PRESENT → lastAbsentSince NOT set ───
$iotRepo->existing = null; // will create new session via findByRoomId
$iotRepo->lastSaved = null;

// Pre-set presence to PRESENT
$preSession = new IotSession(
    0, 1, null,
    IotSession::DOOR_OPEN,
    IotSession::PRESENCE_PRESENT,
    Clock::nowUtc()->format('Y-m-d H:i:s'),
    null, null, '', ''
);
$iotRepo->existing = $preSession;

$event2 = [
    'room_id' => 1,
    'sensor' => PresenceEvent::SENSOR_PROXIMITY,
    'value' => PresenceEvent::VALUE_CLOSED,
    'provider' => 'TUYA',
    'occurred_at' => Clock::nowUtc()->format('Y-m-d\TH:i:s\Z'),
    'source_event_id' => 'test-2-' . uniqid(),
    'meta' => null,
];

$result = $svc->processEvent($event2, 'corr-2');
$saved = $iotRepo->lastSaved;

if ($saved !== null && $saved->lastAbsentSince === null) {
    pass('Door CLOSED + PRESENT → lastAbsentSince NOT set');
} else {
    fail('Door CLOSED + PRESENT → lastAbsentSince should remain null, got ' . ($saved ? json_encode($saved->lastAbsentSince) : 'null'));
}

// ─── Test 3: PRESENT after door close clears the timer ───
$iotRepo->existing = null;
$iotRepo->lastSaved = null;
// Simulate door closed + ABSENT → timer was set
$existingWithTimer = new IotSession(
    0, 1, null,
    IotSession::DOOR_CLOSED,
    IotSession::PRESENCE_ABSENT,
    Clock::nowUtc()->format('Y-m-d H:i:s'),
    Clock::nowUtc()->format('Y-m-d H:i:s'),
    null, '', ''
);
$iotRepo->existing = $existingWithTimer;

$event3 = [
    'room_id' => 1,
    'sensor' => PresenceEvent::SENSOR_PRESENCE,
    'value' => PresenceEvent::VALUE_PRESENT,
    'provider' => 'TUYA',
    'occurred_at' => Clock::nowUtc()->format('Y-m-d\TH:i:s\Z'),
    'source_event_id' => 'test-3-' . uniqid(),
    'meta' => null,
];

$result = $svc->processEvent($event3, 'corr-3');
$saved = $iotRepo->lastSaved;

if ($saved !== null && $saved->lastAbsentSince === null) {
    pass('PRESENT event → lastAbsentSince cleared');
} else {
    fail('PRESENT event → lastAbsentSince should be null; got ' . ($saved ? json_encode($saved->lastAbsentSince) : 'null'));
}

// ─── Test 4: Room override presence_check_seconds is used in gap ───
// Room has presence_check_seconds=30, room_type has exitPresenceGapSeconds=15.
// Set session: door CLOSED, ABSENT for 25s.
// With room override (30s): should NOT fire (25 < 30)
// With room_type fallback (15s): WOULD fire (25 > 15) → but shouldn't because override takes priority
$testSession = new IotSession(
    0, 1, null,
    IotSession::DOOR_CLOSED,
    IotSession::PRESENCE_ABSENT,
    gmdate('Y-m-d H:i:s', time() - 5),
    gmdate('Y-m-d H:i:s', time() - 25),
    null, '', ''
);

// evaluate with room override 30s
$shouldFire = $exitEval->evaluate($testSession, 30, time());
if (!$shouldFire) {
    pass('Gap=30s override → 25s absent → does NOT fire');
} else {
    fail('Gap=30s override → 25s absent → should NOT fire but did');
}

// evaluate with room_type fallback 15s
$shouldFire = $exitEval->evaluate($testSession, 15, time());
if ($shouldFire) {
    pass('Gap=15s fallback → 25s absent → FIRE');
} else {
    fail('Gap=15s fallback → 25s absent → should FIRE but did not');
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
