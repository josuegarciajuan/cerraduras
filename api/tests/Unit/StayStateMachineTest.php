<?php
declare(strict_types=1);

/**
 * Unit tests for StayStateMachine transitions.
 *
 * Uses an in-memory repository fake to avoid DB access.
 *
 * Run:
 *   php tests/Unit/StayStateMachineTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Stays\Stay;
use App\Domain\Stays\StayRepositoryInterface;
use App\Domain\Stays\StayStateMachine;
use App\Support\Clock;
use App\Support\Errors\ConflictException;

final class InMemoryStayRepo implements StayRepositoryInterface
{
    /** @var array<int, Stay> */
    public array $byId = [];
    public int $nextId = 1;
    /** @var list<array{id:int, fields: array<string,mixed>}> */
    public array $updates = [];

    public function insertReserved(int $roomId, int $duracionMinutos, array $vb6Refs): int
    {
        $id = $this->nextId++;
        $now = '2026-04-24 10:00:00.000';
        $this->byId[$id] = new Stay(
            $id, $roomId, Stay::STATUS_RESERVED, $duracionMinutos,
            $now, null, null, null,
            $vb6Refs['codalq'] ?? null, $vb6Refs['codtic'] ?? null,
            $vb6Refs['codcli'] ?? null, $vb6Refs['codart'] ?? null,
            $vb6Refs['codlot'] ?? null, $vb6Refs['codhab'] ?? null,
            $vb6Refs['temporada'] ?? null, $vb6Refs['empresa'] ?? null,
            $vb6Refs['departamento'] ?? null,
            $now, $now
        );
        return $id;
    }
    public function findById(int $id): ?Stay { return $this->byId[$id] ?? null; }
    public function findActiveForRoom(int $roomId): ?Stay
    {
        foreach ($this->byId as $s) {
            if ($s->roomId === $roomId && $s->isActive()) {
                return $s;
            }
        }
        return null;
    }
    public function lockActiveForRoom(int $roomId): ?Stay { return $this->findActiveForRoom($roomId); }
    public function listFiltered(array $filters, int $limit = 50, int $offset = 0): array
    {
        return array_values($this->byId);
    }
    public function update(int $id, array $fields, ?string $expectedStatus = null): int
    {
        $this->updates[] = ['id' => $id, 'fields' => $fields, 'expected' => $expectedStatus];
        if (!isset($this->byId[$id])) {
            return 0;
        }
        $s = $this->byId[$id];
        // Optimistic concurrency check
        if ($expectedStatus !== null && $s->status !== $expectedStatus) {
            return 0;
        }
        foreach ($fields as $k => $v) {
            switch ($k) {
                case 'status': $s->status = (string) $v; break;
                case 'first_entry_at': $s->firstEntryAt = $v === null ? null : (string) $v; break;
                case 'exit_detected_at': $s->exitDetectedAt = $v === null ? null : (string) $v; break;
                case 'closed_at': $s->closedAt = $v === null ? null : (string) $v; break;
            }
        }
        return 1;
    }
}

$passed = 0;
$failed = 0;

function checkTrue(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) { echo "  PASS  {$label}\n"; $passed++; }
    else       { echo "  FAIL  {$label}\n"; $failed++; }
}

function expectConflict(callable $fn, string $label): void {
    global $passed, $failed;
    try {
        $fn();
        echo "  FAIL  {$label} (no exception)\n"; $failed++;
    } catch (ConflictException $e) {
        if ($e->errorCode() === 'stay_wrong_state') {
            echo "  PASS  {$label}\n"; $passed++;
        } else {
            echo "  FAIL  {$label} (got code {$e->errorCode()})\n"; $failed++;
        }
    } catch (\Throwable $e) {
        echo "  FAIL  {$label} (unexpected " . get_class($e) . ": " . $e->getMessage() . ")\n"; $failed++;
    }
}

echo "StayStateMachine transitions\n";

Clock::freeze(new DateTimeImmutable('2026-04-24T10:00:00.000Z', new DateTimeZone('UTC')));

// Case 1: RESERVED -> OCCUPIED via firstEntry().
$repo = new InMemoryStayRepo();
$id = $repo->insertReserved(1, 60, []);
$stay = $repo->findById($id);
$sm = new StayStateMachine($repo);
$sm->firstEntry($stay);
checkTrue($stay->status === Stay::STATUS_OCCUPIED, 'firstEntry(): RESERVED -> OCCUPIED');
checkTrue($stay->firstEntryAt !== null, 'firstEntry(): first_entry_at set');

// Case 2: OCCUPIED -> EXITED via exitDetected().
$sm->exitDetected($stay);
checkTrue($stay->status === Stay::STATUS_EXITED, 'exitDetected(): OCCUPIED -> EXITED');
checkTrue($stay->exitDetectedAt !== null, 'exitDetected(): exit_detected_at set');

// Case 3: EXITED -> OVERSTAY.
$sm->overstayDetected($stay);
checkTrue($stay->status === Stay::STATUS_OVERSTAY, 'overstayDetected(): EXITED -> OVERSTAY');

// Case 3b: OVERSTAY -> OVERSTAY is a no-op (idempotent for the scan job).
$updateCountBefore = count($repo->updates);
$sm->overstayDetected($stay);
checkTrue(count($repo->updates) === $updateCountBefore, 'overstayDetected(): idempotent on OVERSTAY');

// Case 4: OVERSTAY -> CLOSED.
$sm->close($stay);
checkTrue($stay->status === Stay::STATUS_CLOSED, 'close(): OVERSTAY -> CLOSED');

// Case 5: RESERVED -> CANCELED.
$id2 = $repo->insertReserved(2, 30, []);
$stay2 = $repo->findById($id2);
$sm->cancel($stay2);
checkTrue($stay2->status === Stay::STATUS_CANCELED, 'cancel(): RESERVED -> CANCELED');

// Case 6: invalid transition firstEntry from OCCUPIED.
$id3 = $repo->insertReserved(3, 60, []);
$stay3 = $repo->findById($id3);
$sm->firstEntry($stay3); // -> OCCUPIED
expectConflict(function () use ($sm, $stay3) {
    $sm->firstEntry($stay3);
}, 'firstEntry() rejected from OCCUPIED');

// Case 7: invalid transition exitDetected from RESERVED.
$id4 = $repo->insertReserved(4, 60, []);
$stay4 = $repo->findById($id4);
expectConflict(function () use ($sm, $stay4) {
    $sm->exitDetected($stay4);
}, 'exitDetected() rejected from RESERVED');

// Case 8: invalid transition close() from CLOSED.
$id5 = $repo->insertReserved(5, 60, []);
$stay5 = $repo->findById($id5);
$sm->firstEntry($stay5);
$sm->close($stay5); // -> CLOSED
expectConflict(function () use ($sm, $stay5) {
    $sm->close($stay5);
}, 'close() rejected from CLOSED');

// Case 9: overstayDetected from RESERVED is invalid.
$id6 = $repo->insertReserved(6, 60, []);
$stay6 = $repo->findById($id6);
expectConflict(function () use ($sm, $stay6) {
    $sm->overstayDetected($stay6);
}, 'overstayDetected() rejected from RESERVED');

// Case 10: close() from RESERVED is allowed (abort-after-issue).
$id7 = $repo->insertReserved(7, 60, []);
$stay7 = $repo->findById($id7);
$sm->close($stay7);
checkTrue($stay7->status === Stay::STATUS_CLOSED, 'close() allowed from RESERVED');

// Case 11: cancel() from OCCUPIED rejected.
$id8 = $repo->insertReserved(8, 60, []);
$stay8 = $repo->findById($id8);
$sm->firstEntry($stay8);
expectConflict(function () use ($sm, $stay8) {
    $sm->cancel($stay8);
}, 'cancel() rejected from OCCUPIED');

Clock::unfreeze();

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
