<?php
declare(strict_types=1);

/**
 * Unit tests for ExitRuleEvaluator (TSK-100, TSK-101, F31).
 *
 * Criteria of acceptance:
 *   - No fire before N seconds of sustained absence.
 *   - Fire at exactly N seconds.
 *   - Fire after N seconds.
 *   - Respects room_type.exit_presence_gap_seconds.
 *   - Does NOT fire if door-close is older than DOOR_CLOSE_WINDOW_S (F31: anchored to close).
 *   - Does NOT fire if presence is PRESENT.
 *   - Does NOT fire if no last_close_at (and no last_open_at fallback) (F31).
 *   - F31: uses last_close_at as anchor; falls back to last_open_at if last_close_at is null.
 *   - shouldExit() returns false for unknown room/session.
 *
 * Run:
 *   php tests/Unit/ExitRuleEvaluatorTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\ExitRuleEvaluator;
use App\Domain\Presence\IotSession;
use App\Domain\Presence\IotSessionRepositoryInterface;
use App\Domain\Rooms\Room;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Rooms\RoomType;
use App\Domain\Rooms\RoomTypeRepositoryInterface;

// ============================================================================
// Minimal fakes
// ============================================================================

final class FakeIotSessionRepoEval implements IotSessionRepositoryInterface
{
    public ?IotSession $session = null;
    public function findByRoomId(int $roomId): ?IotSession { return $this->session; }
    public function upsert(IotSession $s): void { $this->session = $s; }
}

final class FakeRoomRepoEval implements RoomRepositoryInterface
{
    public ?Room $room = null;
    public function listFiltered(array $f, int $l=50, int $o=0): array { return []; }
    public function findById(int $id): ?Room { return $this->room; }
    public function findByCode(string $c): ?Room { return null; }
    public function findByPackId(int $pk): ?Room { return null; }
    public function insert(string $c, int $rt, ?bool $s): int { return 0; }
    public function update(int $id, array $f, ?string $expectedStatus = null): int { return 0; }
    public function resetAfterPackRemoval(int $roomId): void {}
}

final class FakeRoomTypeRepoEval implements RoomTypeRepositoryInterface
{
    public ?RoomType $rt = null;
    public function listAll(): array { return []; }
    public function findById(int $id): ?RoomType { return $this->rt; }
    public function findByCode(string $c): ?RoomType { return null; }
    public function insert(string $a, string $b, int $c, int $d, int $e, int $f): int { return 0; }
    public function update(int $id, array $fields, ?string $expectedStatus = null): int { return 0; }
}

// ============================================================================
// Helpers
// ============================================================================
$PASS = 0; $FAIL = 0;
function ok(string $l): void  { global $PASS; $PASS++; echo "  PASS  {$l}\n"; }
function bad(string $l, string $w=''): void { global $FAIL; $FAIL++; echo "  FAIL  {$l} {$w}\n"; }

/**
 * Build a session with door recently closed at $lastCloseAt and
 * presence absent since $lastAbsentSince.
 * F31: $lastCloseAt is the primary anchor; $lastOpenAt is kept for fallback.
 */
function makeSession(?string $lastOpenAt, string $presenceState, ?string $lastAbsentSince,
                     string $doorState = IotSession::DOOR_CLOSED,
                     ?string $lastCloseAt = null): IotSession
{
    // $lastCloseAt defaults to same value as $lastOpenAt for convenience in tests
    if ($lastCloseAt === null && $lastOpenAt !== null) {
        $lastCloseAt = $lastOpenAt;
    }
    return new IotSession(
        1, 1, null,
        $doorState,
        $presenceState,
        $lastOpenAt,
        $lastCloseAt,
        $lastAbsentSince,
        null, ''
    );
}

// ============================================================================
// Build evaluator
// ============================================================================
$iotRepo  = new FakeIotSessionRepoEval();
$roomRepo = new FakeRoomRepoEval();
$rtRepo   = new FakeRoomTypeRepoEval();
$eval     = new ExitRuleEvaluator($iotRepo, $roomRepo, $rtRepo);

echo "ExitRuleEvaluator\n";

// Reference timestamp: 2026-04-28 10:00:00 UTC = epoch T0
$t0 = mktime(10, 0, 0, 4, 28, 2026); // UTC

// ============================================================================
// Pure evaluate() tests
// ============================================================================

// T1: gap=5, absent for 4s → NO fire
$sess = makeSession(
    gmdate('Y-m-d H:i:s', $t0 - 10) . '.000',  // door opened 10s ago
    IotSession::PRESENCE_ABSENT,
    gmdate('Y-m-d H:i:s', $t0 - 4) . '.000'    // absent since 4s ago
);
if ($eval->evaluate($sess, 5, $t0) === false) ok('T1: gap=5, absent 4s → no fire');
else bad('T1: gap=5, absent 4s → no fire');

// T1b (F28): gap satisfied but door still OPEN → NO fire
$sess = makeSession(
    gmdate('Y-m-d H:i:s', $t0 - 10) . '.000',  // door opened 10s ago
    IotSession::PRESENCE_ABSENT,
    gmdate('Y-m-d H:i:s', $t0 - 10) . '.000',  // absent since 10s ago (gap satisfied)
    IotSession::DOOR_OPEN                        // but door is still OPEN!
);
if ($eval->evaluate($sess, 5, $t0) === false) ok('T1b: door OPEN → no fire (F28)');
else bad('T1b: door OPEN → no fire (F28)');

// T2: gap=5, absent for exactly 5s → FIRE
$sess = makeSession(
    gmdate('Y-m-d H:i:s', $t0 - 10) . '.000',
    IotSession::PRESENCE_ABSENT,
    gmdate('Y-m-d H:i:s', $t0 - 5) . '.000'
);
if ($eval->evaluate($sess, 5, $t0) === true) ok('T2: gap=5, absent 5s → fire');
else bad('T2: gap=5, absent 5s → fire');

// T3: gap=5, absent for 10s → FIRE
$sess = makeSession(
    gmdate('Y-m-d H:i:s', $t0 - 15) . '.000',
    IotSession::PRESENCE_ABSENT,
    gmdate('Y-m-d H:i:s', $t0 - 10) . '.000'
);
if ($eval->evaluate($sess, 5, $t0) === true) ok('T3: gap=5, absent 10s → fire');
else bad('T3: gap=5, absent 10s → fire');

// T4: gap=15 (different room_type), absent for 10s → NO fire
if ($eval->evaluate($sess, 15, $t0) === false) ok('T4: gap=15, absent 10s → no fire (respects gap)');
else bad('T4: gap=15, absent 10s → no fire (respects gap)');

// T5: gap=15, absent for 15s → FIRE
$sess5 = makeSession(
    gmdate('Y-m-d H:i:s', $t0 - 20) . '.000',
    IotSession::PRESENCE_ABSENT,
    gmdate('Y-m-d H:i:s', $t0 - 15) . '.000'
);
if ($eval->evaluate($sess5, 15, $t0) === true) ok('T5: gap=15, absent 15s → fire');
else bad('T5: gap=15, absent 15s → fire');

// T6 (F31): door closed > DOOR_CLOSE_WINDOW_S ago → NO fire (stale door event)
$sessStale = makeSession(
    gmdate('Y-m-d H:i:s', $t0 - (ExitRuleEvaluator::DOOR_CLOSE_WINDOW_S + 1)) . '.000',
    IotSession::PRESENCE_ABSENT,
    gmdate('Y-m-d H:i:s', $t0 - 30) . '.000'
);
if ($eval->evaluate($sessStale, 5, $t0) === false) ok('T6: stale door-close → no fire');
else bad('T6: stale door-close → no fire');

// T7: presence=PRESENT → NO fire
$sessPresent = makeSession(
    gmdate('Y-m-d H:i:s', $t0 - 10) . '.000',
    IotSession::PRESENCE_PRESENT,
    null
);
if ($eval->evaluate($sessPresent, 5, $t0) === false) ok('T7: presence=PRESENT → no fire');
else bad('T7: presence=PRESENT → no fire');

// T8: lastCloseAt and lastOpenAt are both null → NO fire (F31: fallback chain exhausted)
$sessNoOpen = new IotSession(
    1, 1, null,
    IotSession::DOOR_CLOSED,
    IotSession::PRESENCE_ABSENT,
    null,                                                         // no last_open_at
    null,                                                         // no last_close_at
    gmdate('Y-m-d H:i:s', $t0 - 10) . '.000',
    null, ''
);
if ($eval->evaluate($sessNoOpen, 5, $t0) === false) ok('T8: no closeAt (no openAt fallback) → no fire');
else bad('T8: no closeAt (no openAt fallback) → no fire');

// T9: lastAbsentSince is null → NO fire (ABSENT set but no timestamp)
$sessNoAbsent = makeSession(
    gmdate('Y-m-d H:i:s', $t0 - 10) . '.000',
    IotSession::PRESENCE_ABSENT,
    null   // absent but no timestamp (shouldn't happen in practice)
);
if ($eval->evaluate($sessNoAbsent, 5, $t0) === false) ok('T9: ABSENT with no timestamp → no fire');
else bad('T9: ABSENT with no timestamp → no fire');

// T10: presence=UNKNOWN → NO fire
$sessUnknown = makeSession(
    gmdate('Y-m-d H:i:s', $t0 - 10) . '.000',
    IotSession::PRESENCE_UNKNOWN,
    null
);
if ($eval->evaluate($sessUnknown, 5, $t0) === false) ok('T10: presence=UNKNOWN → no fire');
else bad('T10: presence=UNKNOWN → no fire');

// ============================================================================
// shouldExit() via repos
// ============================================================================

// T11: room not in DB → false
if ($eval->shouldExit(9999, $t0) === false) ok('T11: shouldExit unknown room → false');
else bad('T11: shouldExit unknown room → false');

// T12: no session in DB → false
$roomRepo->room = new Room(1,'101',1,null,Room::STATUS_OCCUPIED,true,null);
$rtRepo->rt     = new RoomType(1,'STANDARD','Std',5,5,10,30);
$iotRepo->session = null;
if ($eval->shouldExit(1, $t0) === false) ok('T12: shouldExit no session → false');
else bad('T12: shouldExit no session → false');

// T13: shouldExit with valid session that satisfies rule → true
$iotRepo->session = makeSession(
    gmdate('Y-m-d H:i:s', $t0 - 10) . '.000',
    IotSession::PRESENCE_ABSENT,
    gmdate('Y-m-d H:i:s', $t0 - 6) . '.000'  // absent 6s >= gap=5s
);
if ($eval->shouldExit(1, $t0) === true) ok('T13: shouldExit with meeting conditions → true');
else bad('T13: shouldExit with meeting conditions → true');

// T14: shouldExit with session that does NOT satisfy rule → false
$iotRepo->session = makeSession(
    gmdate('Y-m-d H:i:s', $t0 - 10) . '.000',
    IotSession::PRESENCE_ABSENT,
    gmdate('Y-m-d H:i:s', $t0 - 3) . '.000'  // absent 3s < gap=5s
);
if ($eval->shouldExit(1, $t0) === false) ok('T14: shouldExit not meeting conditions → false');
else bad('T14: shouldExit not meeting conditions → false');

// ============================================================================
// F31: last_close_at anchor tests
// ============================================================================

// T15 (F31): lastCloseAt within window, ABSENT + gap satisfied → FIRE (primary anchor)
$sessF31a = makeSession(
    gmdate('Y-m-d H:i:s', $t0 - 120) . '.000',   // door opened 2min ago (stale)
    IotSession::PRESENCE_ABSENT,
    gmdate('Y-m-d H:i:s', $t0 - 10) . '.000',    // absent 10s >= gap=5
    IotSession::DOOR_CLOSED,
    gmdate('Y-m-d H:i:s', $t0 - 2) . '.000'      // door closed 2s ago (fresh!)
);
if ($eval->evaluate($sessF31a, 5, $t0) === true) ok('T15: lastCloseAt fresh, open stale → fire');
else bad('T15: lastCloseAt fresh, open stale → fire');

// T16 (F31): lastCloseAt null → fallback to lastOpenAt (legacy row)
$sessF31b = new IotSession(
    1, 1, null,
    IotSession::DOOR_CLOSED,
    IotSession::PRESENCE_ABSENT,
    gmdate('Y-m-d H:i:s', $t0 - 2) . '.000',     // open 2s ago
    null,                                          // lastCloseAt NOT set (legacy)
    gmdate('Y-m-d H:i:s', $t0 - 10) . '.000',
    null, ''
);
if ($eval->evaluate($sessF31b, 5, $t0) === true) ok('T16: no lastCloseAt → fallback to lastOpenAt → fire');
else bad('T16: no lastCloseAt → fallback to lastOpenAt → fire');

// T17 (F31): lastCloseAt outside window → NO fire (verification window expired)
$sessF31c = makeSession(
    gmdate('Y-m-d H:i:s', $t0 - 5) . '.000',
    IotSession::PRESENCE_ABSENT,
    gmdate('Y-m-d H:i:s', $t0 - 10) . '.000',
    IotSession::DOOR_CLOSED,
    gmdate('Y-m-d H:i:s', $t0 - (ExitRuleEvaluator::DOOR_CLOSE_WINDOW_S + 1)) . '.000'
);
if ($eval->evaluate($sessF31c, 5, $t0) === false) ok('T17: lastCloseAt stale → no fire');
else bad('T17: lastCloseAt stale → no fire');

// T18 (F31): lastCloseAt within window but presence PRESENT → NO fire
$sessF31d = makeSession(
    gmdate('Y-m-d H:i:s', $t0 - 10) . '.000',
    IotSession::PRESENCE_PRESENT,
    null,
    IotSession::DOOR_CLOSED,
    gmdate('Y-m-d H:i:s', $t0 - 2) . '.000'
);
if ($eval->evaluate($sessF31d, 5, $t0) === false) ok('T18: closeAt fresh but presence=PRESENT → no fire');
else bad('T18: closeAt fresh but presence=PRESENT → no fire');

echo "\nTotal: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
