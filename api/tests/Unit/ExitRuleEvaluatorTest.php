<?php
declare(strict_types=1);

/**
 * Unit tests for ExitRuleEvaluator (F31, Fase 41 — RF-47).
 *
 * Fase 41 semantics (contracts.md §3):
 *   - The rule no longer depends on the current door state; it requires a
 *     *credited door cycle* posterior to the entry confirmation:
 *       stay.entry_confirmed_at set
 *       last_open_at >= entry_confirmed_at
 *       last_close_at >= last_open_at
 *       now - last_close_at <= DOOR_CYCLE_MAX_S (300 s)
 *       presence_state = ABSENT sustained >= gap
 *   - A new PRESENT clears last_absent_since (cancellation) => rule false.
 *
 * Traces of the migrated old cases (pre-F41):
 *   T1b "door OPEN => no fire" is now T7 (door OPEN still fires with a cycle).
 *   T2/T3/T5/T6 "DOOR_CLOSE_WINDOW_S=60" became DOOR_CYCLE_MAX_S=300 (T8/T12).
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
use App\Domain\Stays\Stay;
use App\Domain\Stays\StayRepositoryInterface;

// ============================================================================
// Minimal fakes
// ============================================================================

final class FakeIotSessionRepoEval implements IotSessionRepositoryInterface
{
    public ?IotSession $session = null;
    public function findByRoomId(int $roomId): ?IotSession { return $this->session; }
    public function upsert(IotSession $s): void { $this->session = $s; }
    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollBack(): void {}
    public function lockByRoomId(int $roomId): IotSession { return $this->session; }
    public function updateState(IotSession $s): void { $this->session = $s; }
    public function markExitEvaluated(int $roomId, string $closeAtUtc): bool { return true; }
}

final class FakeStayRepoEval implements StayRepositoryInterface
{
    public ?Stay $stay = null;
    public function insertReserved(int $roomId, int $duracionMinutos, array $vb6Refs): int { return 0; }
    public function findById(int $id): ?Stay { return $this->stay; }
    public function findActiveForRoom(int $roomId): ?Stay { return $this->stay; }
    public function lockActiveForRoom(int $roomId): ?Stay { return $this->stay; }
    public function listFiltered(array $f, int $l = 50, int $o = 0): array { return []; }
    public function update(int $id, array $fields, ?string $expectedStatus = null): int { return 1; }
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

function makeSession(
    ?string $lastOpenAt,
    ?string $lastCloseAt,
    string  $presenceState,
    ?string $lastAbsentSince,
    string  $doorState = IotSession::DOOR_CLOSED
): IotSession {
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

function makeStay(?string $entryConfirmedAt): Stay
{
    $base = '2026-04-28 09:00:00.000';
    return new Stay(
        500, 1, Stay::STATUS_OCCUPIED, 60,
        $base, $base, null, null,
        null, null, null, null, null, null, null, null, null,
        $base, $base,
        $entryConfirmedAt
    );
}

// ============================================================================
// Build evaluator
// ============================================================================
$iotRepo  = new FakeIotSessionRepoEval();
$roomRepo = new FakeRoomRepoEval();
$rtRepo   = new FakeRoomTypeRepoEval();
$stayRepo = new FakeStayRepoEval();
$eval     = new ExitRuleEvaluator($iotRepo, $roomRepo, $rtRepo, $stayRepo);

echo "ExitRuleEvaluator (Fase 41)\n";

// Reference timestamp: 2026-04-28 10:00:00 UTC
$t0 = mktime(10, 0, 0, 4, 28, 2026);

// A credited cycle: entry confirmed long ago, opened 30s ago, closed 3s ago.
$entryOld = gmdate('Y-m-d H:i:s', $t0 - 400) . '.000';
$open30   = gmdate('Y-m-d H:i:s', $t0 - 30) . '.000';
$close3   = gmdate('Y-m-d H:i:s', $t0 - 3) . '.000';
$absent10 = gmdate('Y-m-d H:i:s', $t0 - 10) . '.000';

$stayOk = makeStay($entryOld);

// T1: open without close → no fire
if ($eval->evaluate(makeSession($open30, null, IotSession::PRESENCE_ABSENT, $absent10), $stayOk, 5, $t0) === false) {
    ok('T1: open without close → no fire');
} else { bad('T1: open without close → no fire'); }

// T2: close before open (no credited cycle) → no fire
$open10 = gmdate('Y-m-d H:i:s', $t0 - 10) . '.000';
$close20 = gmdate('Y-m-d H:i:s', $t0 - 20) . '.000';
if ($eval->evaluate(makeSession($open10, $close20, IotSession::PRESENCE_ABSENT, $absent10), $stayOk, 5, $t0) === false) {
    ok('T2: close < open (no cycle) → no fire');
} else { bad('T2: close < open (no cycle) → no fire'); }

// T3: no stay → no fire
if ($eval->evaluate(makeSession($open30, $close3, IotSession::PRESENCE_ABSENT, $absent10), null, 5, $t0) === false) {
    ok('T3: no active stay → no fire');
} else { bad('T3: no active stay → no fire'); }

// T3b: stay without entry_confirmed_at → no fire
if ($eval->evaluate(makeSession($open30, $close3, IotSession::PRESENCE_ABSENT, $absent10), makeStay(null), 5, $t0) === false) {
    ok('T3b: entry_confirmed_at null → no fire');
} else { bad('T3b: entry_confirmed_at null → no fire'); }

// T4: no new opening after entry confirmation → no fire
$entryAfterOpen = gmdate('Y-m-d H:i:s', $t0 - 5) . '.000';
if ($eval->evaluate(makeSession($open30, $close3, IotSession::PRESENCE_ABSENT, $absent10), makeStay($entryAfterOpen), 5, $t0) === false) {
    ok('T4: last_open_at < entry_confirmed_at → no fire');
} else { bad('T4: last_open_at < entry_confirmed_at → no fire'); }

// T4b: same-second entry-close / exit-open (open == entry) → fire (F41 granularity)
if ($eval->evaluate(makeSession($open30, $close3, IotSession::PRESENCE_ABSENT, $absent10), makeStay($open30), 5, $t0) === true) {
    ok('T4b: last_open_at == entry_confirmed_at → fire (second granularity)');
} else { bad('T4b: last_open_at == entry_confirmed_at → fire (second granularity)'); }

// T5: credited cycle + ABSENT + gap met → fire
if ($eval->evaluate(makeSession($open30, $close3, IotSession::PRESENCE_ABSENT, $absent10), $stayOk, 5, $t0) === true) {
    ok('T5: credited cycle + absent + gap → fire');
} else { bad('T5: credited cycle + absent + gap → fire'); }

// T6: credited cycle + ABSENT but gap not met → no fire
$absent2 = gmdate('Y-m-d H:i:s', $t0 - 2) . '.000';
if ($eval->evaluate(makeSession($open30, $close3, IotSession::PRESENCE_ABSENT, $absent2), $stayOk, 5, $t0) === false) {
    ok('T6: gap not met → no fire');
} else { bad('T6: gap not met → no fire'); }

// T7: door_state=OPEN with credited cycle → still fires (no longer depends on door state)
if ($eval->evaluate(makeSession($open30, $close3, IotSession::PRESENCE_ABSENT, $absent10, IotSession::DOOR_OPEN), $stayOk, 5, $t0) === true) {
    ok('T7: door OPEN with credited cycle → fire (F41)');
} else { bad('T7: door OPEN with credited cycle → fire (F41)'); }

// T8: close older than DOOR_CYCLE_MAX_S → no fire
$staleClose = gmdate('Y-m-d H:i:s', $t0 - (ExitRuleEvaluator::DOOR_CYCLE_MAX_S + 1)) . '.000';
$staleOpen  = gmdate('Y-m-d H:i:s', $t0 - (ExitRuleEvaluator::DOOR_CYCLE_MAX_S + 10)) . '.000';
if ($eval->evaluate(makeSession($staleOpen, $staleClose, IotSession::PRESENCE_ABSENT, $absent10), $stayOk, 5, $t0) === false) {
    ok('T8: cycle older than DOOR_CYCLE_MAX_S → no fire');
} else { bad('T8: cycle older than DOOR_CYCLE_MAX_S → no fire'); }

// T9: presence PRESENT → no fire
if ($eval->evaluate(makeSession($open30, $close3, IotSession::PRESENCE_PRESENT, null), $stayOk, 5, $t0) === false) {
    ok('T9: presence PRESENT → no fire');
} else { bad('T9: presence PRESENT → no fire'); }

// T10: cancellation (PRESENT cleared last_absent_since) → no fire
if ($eval->evaluate(makeSession($open30, $close3, IotSession::PRESENCE_ABSENT, null), $stayOk, 5, $t0) === false) {
    ok('T10: last_absent_since null (cancelled) → no fire');
} else { bad('T10: last_absent_since null (cancelled) → no fire'); }

// T11: presence UNKNOWN → no fire
if ($eval->evaluate(makeSession($open30, $close3, IotSession::PRESENCE_UNKNOWN, null), $stayOk, 5, $t0) === false) {
    ok('T11: presence UNKNOWN → no fire');
} else { bad('T11: presence UNKNOWN → no fire'); }

// ============================================================================
// shouldExit() via repos
// ============================================================================

// T12: unknown room → false
if ($eval->shouldExit(9999, $t0) === false) ok('T12: shouldExit unknown room → false');
else bad('T12: shouldExit unknown room → false');

// T13: no session → false
$roomRepo->room  = new Room(1, '101', 1, null, Room::STATUS_OCCUPIED, true, null);
$rtRepo->rt      = new RoomType(1, 'STANDARD', 'Std', 5, 5, 10, 30);
$iotRepo->session = null;
$stayRepo->stay   = $stayOk;
if ($eval->shouldExit(1, $t0) === false) ok('T13: shouldExit no session → false');
else bad('T13: shouldExit no session → false');

// T14: valid session + stay satisfying rule → true
$iotRepo->session = makeSession($open30, $close3, IotSession::PRESENCE_ABSENT, $absent10);
if ($eval->shouldExit(1, $t0) === true) ok('T14: shouldExit meeting conditions → true');
else bad('T14: shouldExit meeting conditions → true');

// T15: valid session but stay not confirmed → false
$stayRepo->stay = makeStay(null);
if ($eval->shouldExit(1, $t0) === false) ok('T15: shouldExit unconfirmed stay → false');
else bad('T15: shouldExit unconfirmed stay → false');

// ============================================================================
// Bug 1: resolveGuardSeconds() — guarda de SALIDA desacoplada de gap_seconds
// ============================================================================

// T16: per-room override wins over the global override.
if (ExitRuleEvaluator::resolveGuardSeconds(7, 5) === 7) {
    ok('T16: resolveGuardSeconds sala override gana (7 > 5)');
} else { bad('T16: resolveGuardSeconds sala override gana', (string) ExitRuleEvaluator::resolveGuardSeconds(7, 5)); }

// T17: global override applies when there is no room override.
if (ExitRuleEvaluator::resolveGuardSeconds(null, 5) === 5) {
    ok('T17: resolveGuardSeconds global sin override de sala (5)');
} else { bad('T17: resolveGuardSeconds global sin override', (string) ExitRuleEvaluator::resolveGuardSeconds(null, 5)); }

// T18: default is 3 s when neither override is present.
if (ExitRuleEvaluator::resolveGuardSeconds(null, null) === 3
    && ExitRuleEvaluator::DEFAULT_GUARD_SECONDS === 3
) {
    ok('T18: resolveGuardSeconds default 3 s');
} else { bad('T18: resolveGuardSeconds default 3 s', (string) ExitRuleEvaluator::resolveGuardSeconds(null, null)); }

// T19: non-positive overrides are treated as "not set" (legacy rows / empty env).
if (ExitRuleEvaluator::resolveGuardSeconds(0, null) === 3) {
    ok('T19a: resolveGuardSeconds sala 0 se ignora → 3');
} else { bad('T19a: resolveGuardSeconds sala 0 se ignora'); }
if (ExitRuleEvaluator::resolveGuardSeconds(-1, 5) === 5) {
    ok('T19b: resolveGuardSeconds sala negativa se ignora → global 5');
} else { bad('T19b: resolveGuardSeconds sala negativa se ignora'); }

echo "\nTotal: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
