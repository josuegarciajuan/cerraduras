<?php
declare(strict_types=1);

/**
 * Unit tests for IotSessionService (TSK-091).
 *
 * Covers:
 *   - State transitions: door open/closed, presence present/absent.
 *   - Exit rule evaluation: fires only when both conditions are met.
 *   - Auto-exit side-effects: stay transition, lock, cooldown.
 *   - Duplicate source_event_id returns accepted=true without re-processing.
 *
 * Run:
 *   php tests/Unit/IotSessionServiceTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Locks\AccessEvent;
use App\Domain\Locks\AccessEventRepositoryInterface;
use App\Domain\Presence\ExitActionService;
use App\Domain\Presence\ExitRuleEvaluator;
use App\Domain\Presence\IotSession;
use App\Domain\Presence\IotSessionRepositoryInterface;
use App\Domain\Presence\IotSessionService;
use App\Domain\Presence\PresenceEvent;
use App\Domain\Presence\PresenceEventRepositoryInterface;
use App\Domain\Rooms\Room;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Rooms\RoomType;
use App\Domain\Rooms\RoomTypeRepositoryInterface;
use App\Domain\Stays\Stay;
use App\Domain\Stays\StayRepositoryInterface;
use App\Domain\Stays\StayStateMachine;
use App\Infrastructure\Gateways\Lock\LockGatewayInterface;
use App\Support\Clock;

// ============================================================================
// Fakes
// ============================================================================

final class FakePresenceEventRepo implements PresenceEventRepositoryInterface
{
    public array $events   = [];
    public bool  $nextDuplicate = false;   // simulate duplicate source_event_id

    public function insert(int $roomId, string $sensor, string $value, string $provider,
                           string $occurredAt, ?string $sourceEventId, ?array $meta): ?int
    {
        if ($this->nextDuplicate) { $this->nextDuplicate = false; return null; }
        $id = count($this->events) + 1;
        $this->events[] = compact('roomId','sensor','value','provider','occurredAt','sourceEventId');
        return $id;
    }

    public function insertOrGet(int $roomId, string $sensor, string $value, string $provider,
                                string $occurredAt, ?string $sourceEventId, ?array $meta): array
    {
        $isNew = true;
        if ($this->nextDuplicate) { $this->nextDuplicate = false; $isNew = false; }
        $id = count($this->events) + 1;
        $this->events[] = compact('roomId','sensor','value','provider','occurredAt','sourceEventId');
        return ['event' => new \App\Domain\Presence\PresenceEvent(
            $id, $roomId, $sensor, $value, $provider, $occurredAt, $occurredAt,
            $sourceEventId, $meta,
            \App\Domain\Presence\SensorEventDecision::fingerprint($roomId, $sensor, $value, $occurredAt)
        ), 'is_new' => $isNew];
    }

    public function markAudit(int $id, bool $applied, ?string $discardReason): void {}

    public function listForRoom(int $roomId, int $limit = 20): array { return []; }
}

final class FakeIotSessionRepo implements IotSessionRepositoryInterface
{
    /** @var array<int,IotSession> */
    public array $sessions = [];
    public array $upserts  = [];

    public function findByRoomId(int $roomId): ?IotSession
    {
        return $this->sessions[$roomId] ?? null;
    }
    public function upsert(IotSession $s): void
    {
        $this->upserts[] = clone $s;
        $this->sessions[$s->roomId] = $s;
    }
    // F41 interface additions
    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollBack(): void {}
    public function lockByRoomId(int $roomId): IotSession
    {
        if (!isset($this->sessions[$roomId])) {
            $this->sessions[$roomId] = new IotSession(
                0, $roomId, null,
                IotSession::DOOR_UNKNOWN, IotSession::PRESENCE_UNKNOWN,
                null, null, null, null, ''
            );
        }
        return $this->sessions[$roomId];
    }
    public function updateState(IotSession $s): void
    {
        $this->upserts[] = clone $s;
        $this->sessions[$s->roomId] = $s;
    }
    public function markExitEvaluated(int $roomId, string $closeAtUtc): bool
    {
        if (isset($this->sessions[$roomId])) {
            $this->sessions[$roomId]->exitEvaluatedAt = $closeAtUtc;
        }
        return true;
    }
}

final class FakeRoomRepoF8 implements RoomRepositoryInterface
{
    /** @var array<int,Room> */
    public array $byId = [];
    public array $updates = [];

    public function listFiltered(array $f, int $l=50, int $o=0): array { return []; }
    public function findById(int $id): ?Room { return $this->byId[$id] ?? null; }
    public function findByCode(string $c): ?Room { return null; }
    public function findByPackId(int $pk): ?Room { return null; }
    public function insert(string $c, int $rt, ?bool $s): int { return 0; }
    public function update(int $id, array $f, ?string $expectedStatus = null): int { $this->updates[] = [$id,$f]; return 1; }
    public function resetAfterPackRemoval(int $roomId): void {}
}

final class FakeRoomTypeRepoF8 implements RoomTypeRepositoryInterface
{
    /** @var array<int,RoomType> */
    public array $byId = [];
    public function listAll(): array { return []; }
    public function findById(int $id): ?RoomType { return $this->byId[$id] ?? null; }
    public function findByCode(string $c): ?RoomType { return null; }
    public function insert(string $a, string $b, int $c, int $d, int $e, int $f): int { return 0; }
    public function update(int $id, array $fields, ?string $expectedStatus = null): int { return 0; }
}

final class FakeStayRepoF8 implements StayRepositoryInterface
{
    /** @var array<int,Stay> */
    public array $byId = [];
    /** @var array<int,Stay|null> */
    public array $activeByRoom = [];
    public array $updates = [];

    public function insertReserved(int $r, int $d, array $refs): int { return 0; }
    public function findById(int $id): ?Stay { return $this->byId[$id] ?? null; }
    public function findActiveForRoom(int $r): ?Stay
    {
        $s = $this->activeByRoom[$r] ?? null;
        return ($s !== null && $s->isActive()) ? $s : null;
    }
    public function lockActiveForRoom(int $r): ?Stay { return $this->findActiveForRoom($r); }
    public function listFiltered(array $f, int $l=50, int $o=0): array { return []; }
    public function update(int $id, array $fields, ?string $expectedStatus = null): int
    {
        $this->updates[] = [$id, $fields];
        if (isset($this->byId[$id])) {
            $s = $this->byId[$id];
            foreach ($fields as $k => $v) {
                if ($k === 'status') $s->status = (string)$v;
                if ($k === 'exit_detected_at') $s->exitDetectedAt = $v;
                if ($k === 'entry_confirmed_at') $s->entryConfirmedAt = $v;
            }
        }
        return 1;
    }
}

final class FakeAccessEventRepoF8 implements AccessEventRepositoryInterface
{
    public array $events = [];
    public function insert(int $r, ?int $s, string $k, string $res, ?string $reason,
                           string $prov, string $corr, ?array $meta, ?int $workerSessionId = null): int
    {
        $this->events[] = compact('r','s','k','res','reason','prov');
        return count($this->events);
    }
}

// ============================================================================
// Build service under test
// ============================================================================

$presRepo  = new FakePresenceEventRepo();
$iotRepo   = new FakeIotSessionRepo();
$roomRepo  = new FakeRoomRepoF8();
$rtRepo    = new FakeRoomTypeRepoF8();
$stayRepo  = new FakeStayRepoF8();
$evRepo    = new FakeAccessEventRepoF8();
$smRepo    = new FakeStayRepoF8(); // separate copy for StayStateMachine

// Room 1, RoomType 1 (exit_gap=5s, cooldown=10s)
$rt = new RoomType(1,'STANDARD','Standard', 5, 5, 10, 30);
$rtRepo->byId[1] = $rt;
$roomRepo->byId[1] = new Room(1, '101', 1, null, Room::STATUS_OCCUPIED, true, null);

$stayMachine   = new StayStateMachine($stayRepo);
$exitEvaluator = new ExitRuleEvaluator($iotRepo, $roomRepo, $rtRepo, $stayRepo);
$exitAction    = new ExitActionService(
    $roomRepo, $iotRepo, $stayMachine, $evRepo, null, null, null,
    $stayRepo, $exitEvaluator, $rtRepo
);

$svc = new IotSessionService(
    $presRepo, $iotRepo, $roomRepo, $rtRepo, $stayRepo, $stayMachine, $evRepo, $exitEvaluator,
    null, $exitAction
);

// ============================================================================
// Helpers
// ============================================================================
$PASS = 0; $FAIL = 0;

function ok(string $l): void  { global $PASS; $PASS++; echo "  PASS  {$l}\n"; }
function bad(string $l, string $w=''): void { global $FAIL; $FAIL++; echo "  FAIL  {$l} {$w}\n"; }

function makeEvent(int $roomId, string $sensor, string $value, string $occurredAt='2026-04-28T10:00:00Z', ?string $srcId=null): array {
    return ['room_id'=>$roomId,'sensor'=>$sensor,'value'=>$value,
            'provider'=>'SIMULATED','occurred_at'=>$occurredAt,
            'source_event_id'=>$srcId,'meta'=>null];
}

echo "IotSessionService\n";

// ============================================================================
// T1: PROXIMITY=OPEN → door_state=OPEN, last_open_at set
// ============================================================================
Clock::freeze(new DateTimeImmutable('2026-04-28T10:00:00Z', new DateTimeZone('UTC')));

$r1 = $svc->processEvent(makeEvent(1, PresenceEvent::SENSOR_PROXIMITY, PresenceEvent::VALUE_OPEN, '2026-04-28T10:00:00Z', 'evt-1'), 'corr-1');
$sess = $iotRepo->sessions[1];
if ($r1['accepted'] === true) ok('T1: accepted=true');
else bad('T1: accepted=true');
if ($sess->doorState === IotSession::DOOR_OPEN) ok('T1: door_state=OPEN');
else bad('T1: door_state=OPEN', "got {$sess->doorState}");
if ($sess->lastOpenAt !== null) ok('T1: last_open_at set');
else bad('T1: last_open_at set');

// ============================================================================
// T2: PRESENCE=ABSENT → presence_state=ABSENT, last_absent_since set
// ============================================================================
$r2 = $svc->processEvent(makeEvent(1, PresenceEvent::SENSOR_PRESENCE, PresenceEvent::VALUE_ABSENT, '2026-04-28T10:00:01Z', 'evt-2'), 'corr-2');
$sess = $iotRepo->sessions[1];
if ($sess->presenceState === IotSession::PRESENCE_ABSENT) ok('T2: presence_state=ABSENT');
else bad('T2: presence_state=ABSENT', "got {$sess->presenceState}");
if ($sess->lastAbsentSince !== null) ok('T2: last_absent_since set');
else bad('T2: last_absent_since set');

// ============================================================================
// T3: PRESENCE=PRESENT → presence_state=PRESENT, last_absent_since cleared
// ============================================================================
$r3 = $svc->processEvent(makeEvent(1, PresenceEvent::SENSOR_PRESENCE, PresenceEvent::VALUE_PRESENT, '2026-04-28T10:00:02Z', 'evt-3'), 'corr-3');
$sess = $iotRepo->sessions[1];
if ($sess->presenceState === IotSession::PRESENCE_PRESENT) ok('T3: presence_state=PRESENT');
else bad('T3: presence_state=PRESENT', "got {$sess->presenceState}");
if ($sess->lastAbsentSince === null) ok('T3: last_absent_since cleared');
else bad('T3: last_absent_since cleared', "got {$sess->lastAbsentSince}");

// ============================================================================
// T4: PROXIMITY=CLOSED → door_state=CLOSED, last_close_at set (F31)
// ============================================================================
$r4 = $svc->processEvent(makeEvent(1, PresenceEvent::SENSOR_PROXIMITY, PresenceEvent::VALUE_CLOSED, '2026-04-28T10:00:03Z', 'evt-4'), 'corr-4');
$sess = $iotRepo->sessions[1];
if ($sess->doorState === IotSession::DOOR_CLOSED) ok('T4: door_state=CLOSED');
else bad('T4: door_state=CLOSED', "got {$sess->doorState}");
if ($sess->lastCloseAt !== null) ok('T4: last_close_at set on PROXIMITY CLOSED (F31)');
else bad('T4: last_close_at set on PROXIMITY CLOSED (F31)');

// ============================================================================
// T5: Duplicate source_event_id → accepted=true, state NOT mutated
// ============================================================================
$presRepo->nextDuplicate = true;
$doorBefore = $iotRepo->sessions[1]->doorState;
$r5 = $svc->processEvent(makeEvent(1, PresenceEvent::SENSOR_PROXIMITY, PresenceEvent::VALUE_OPEN, '2026-04-28T10:00:04Z', 'evt-dup'), 'corr-5');
if ($r5['accepted'] === true) ok('T5: duplicate accepted=true');
else bad('T5: duplicate accepted=true');
if ($iotRepo->sessions[1]->doorState === $doorBefore) ok('T5: state not mutated on duplicate');
else bad('T5: state not mutated on duplicate');

// ============================================================================
// T6: Exit rule fires after door close + sustained absence >= gap (5s)
//     Setup: door open at T=0, close at T=1, absence starts at T=2, now at T=8
// ============================================================================

// Add an active OCCUPIED stay already confirmed inside (F41). The exit rule
// needs a new opening AFTER entry_confirmed_at, so the OPEN below is the exit.
$stay = new Stay(99, 1, Stay::STATUS_OCCUPIED, 60,
    '2026-04-28 09:00:00.000', '2026-04-28 09:01:00.000', null, null,
    null, null, null, null, null, null, null, null, null,
    '2026-04-28 09:00:00.000', '2026-04-28 09:00:00.000',
    '2026-04-28 10:00:00.000'   // entry_confirmed_at (before the exit opening)
);
$stayRepo->byId[99]     = $stay;
$stayRepo->activeByRoom[1] = $stay;

// Freeze clock at T=0, send door-open
Clock::freeze(new DateTimeImmutable('2026-04-28T10:01:00Z', new DateTimeZone('UTC')));
$svc->processEvent(makeEvent(1, PresenceEvent::SENSOR_PROXIMITY, PresenceEvent::VALUE_OPEN, '2026-04-28T10:01:00Z', 'evt-exit-1'), 'corr-exit-1');

// T=1: close door (F31: last_close_at recorded)
Clock::freeze(new DateTimeImmutable('2026-04-28T10:01:01Z', new DateTimeZone('UTC')));
$svc->processEvent(makeEvent(1, PresenceEvent::SENSOR_PROXIMITY, PresenceEvent::VALUE_CLOSED, '2026-04-28T10:01:01Z', 'evt-exit-close'), 'corr-exit-close');

// T=2: send ABSENT event (absence starts now)
Clock::freeze(new DateTimeImmutable('2026-04-28T10:01:02Z', new DateTimeZone('UTC')));
$svc->processEvent(makeEvent(1, PresenceEvent::SENSOR_PRESENCE, PresenceEvent::VALUE_ABSENT, '2026-04-28T10:01:02Z', 'evt-exit-2'), 'corr-exit-2');

// Advance clock to T=8 (>= gap=5s from ABSENT). A repeated ABSENT is a NOOP
// (same value), so the periodic exit-scan path is what fires here (F41).
Clock::freeze(new DateTimeImmutable('2026-04-28T10:01:08Z', new DateTimeZone('UTC')));
$evCountBefore = count($evRepo->events);
$exitAction->executeIfPending(1, 'corr-exit-3');

if ($stay->status === Stay::STATUS_EXITED) ok('T6: exit rule: stay→EXITED');
else bad('T6: exit rule: stay→EXITED', "got status={$stay->status}");

$autoLockEvents = array_filter($evRepo->events, fn($e) => $e['k'] === AccessEvent::KIND_AUTO_LOCK);
if (count($autoLockEvents) > 0) ok('T6: exit rule: AUTO_LOCK event written');
else bad('T6: exit rule: AUTO_LOCK event written');

$cooldownUpdates = array_filter($roomRepo->updates, fn($u) => isset($u[1]['cooldown_until']));
if (count($cooldownUpdates) > 0) ok('T6: exit rule: room cooldown_until set');
else bad('T6: exit rule: room cooldown_until set');

$sess = $iotRepo->sessions[1];
if ($sess->exitEvaluatedAt !== null) ok('T6: exit rule: exit_evaluated_at set in session');
else bad('T6: exit rule: exit_evaluated_at set in session');

// ============================================================================
// T7: Exit rule does NOT fire again once stay is EXITED
// ============================================================================
$evCountAfter = count($evRepo->events);
Clock::freeze(new DateTimeImmutable('2026-04-28T10:01:10Z', new DateTimeZone('UTC')));
$svc->processEvent(makeEvent(1, PresenceEvent::SENSOR_PRESENCE, PresenceEvent::VALUE_ABSENT, '2026-04-28T10:01:10Z', 'evt-exit-4'), 'corr-exit-4');
// Periodic worker runs again: the stay is already EXITED, so nothing fires.
$exitAction->executeIfPending(1, 'corr-exit-4b');
$newAutoLock = array_filter($evRepo->events, fn($e) => $e['k'] === AccessEvent::KIND_AUTO_LOCK);
if (count($newAutoLock) === count($autoLockEvents)) ok('T7: exit rule idempotent (no re-fire when EXITED)');
else bad('T7: exit rule idempotent', 'AUTO_LOCK fired again');

// ============================================================================
// T8: Room not found → NotFoundException
// ============================================================================
try {
    $svc->processEvent(makeEvent(9999, PresenceEvent::SENSOR_PRESENCE, PresenceEvent::VALUE_ABSENT), 'corr-8');
    bad('T8: unknown room → NotFoundException', '(no exception)');
} catch (\App\Support\Errors\NotFoundException $e) {
    ok('T8: unknown room → NotFoundException');
} catch (\Throwable $e) {
    bad('T8: unknown room → NotFoundException', get_class($e).': '.$e->getMessage());
}

Clock::unfreeze();

echo "\nTotal: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
