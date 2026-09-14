<?php
declare(strict_types=1);

/**
 * Unit tests for DebtsService (TSK-111, TSK-115).
 *
 * CA:
 *   - createIfNeeded returns null when no overstay.
 *   - createIfNeeded creates debt and enqueues outbox topics when overstay.
 *   - createIfNeeded is idempotent (second call returns existing debt).
 *   - stay transitions to OVERSTAY after debt creation (TSK-115).
 *   - rescheduleDebt returns null for unknown debt.
 *   - rescheduleDebt resets FAILED debt to PENDING_SYNC.
 *
 * Run:
 *   php tests/Unit/DebtsServiceTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Debts\Debt;
use App\Domain\Debts\DebtRepositoryInterface;
use App\Domain\Debts\DebtsService;
use App\Domain\Debts\OutboxVb6RepositoryInterface;
use App\Domain\Debts\OverstayCalculator;
use App\Domain\Rooms\RoomType;
use App\Domain\Stays\Stay;
use App\Domain\Stays\StayRepositoryInterface;
use App\Domain\Stays\StayStateMachine;
use App\Support\Clock;

// ============================================================================
// Fakes
// ============================================================================

final class FakeDebtRepo implements DebtRepositoryInterface
{
    public array $rows   = [];
    public int   $nextId = 1;
    public array $updates = [];

    public function insert(int $stayId, int $roomId, int $exceso): int
    {
        $id = $this->nextId++;
        $now = '2026-04-28 10:00:00.000';
        $this->rows[$id] = new Debt($id, $stayId, $roomId, $exceso, null,
            Debt::STATUS_PENDING_SYNC, 0, null, null, $now, $now);
        return $id;
    }
    public function findById(int $id): ?Debt { return $this->rows[$id] ?? null; }
    public function findActiveForStay(int $stayId): ?Debt
    {
        foreach ($this->rows as $d) {
            if ($d->stayId === $stayId
                && in_array($d->status, [Debt::STATUS_PENDING_SYNC, Debt::STATUS_SYNCING], true)) {
                return $d;
            }
        }
        return null;
    }
    public function listFiltered(array $f, int $l=50, int $o=0): array { return []; }
    public function update(int $id, array $fields, ?string $expectedStatus = null): int
    {
        $this->updates[] = [$id, $fields];
        if (isset($this->rows[$id])) {
            foreach ($fields as $k => $v) {
                if ($k === 'status') $this->rows[$id]->status = (string)$v;
            }
        }
        return 1;
    }
    public function findOverdueOccupied(): array { return []; }
}

final class FakeStayRepoDebts implements StayRepositoryInterface
{
    public array $byId = [];
    public array $updates = [];
    public function insertReserved(int $r, int $d, array $refs): int { return 0; }
    public function findById(int $id): ?Stay { return $this->byId[$id] ?? null; }
    public function findActiveForRoom(int $r): ?Stay { return null; }
    public function listFiltered(array $f, int $l=50, int $o=0): array { return []; }
    public function update(int $id, array $fields, ?string $expectedStatus = null): int
    {
        $this->updates[] = [$id, $fields];
        if (isset($this->byId[$id])) {
            if ($expectedStatus !== null && $this->byId[$id]->status !== $expectedStatus) {
                return 0;
            }
            foreach ($fields as $k => $v) {
                if ($k === 'status') $this->byId[$id]->status = (string)$v;
            }
        }
        return 1;
    }
}

final class FakeOutbox implements OutboxVb6RepositoryInterface
{
    public array $enqueued = [];
    public array $retried  = [];

    public function enqueue(string $topic, array $payload, string $idemKey): int
    {
        $this->enqueued[] = compact('topic', 'idemKey');
        return count($this->enqueued);
    }
    public function scheduleRetry(string $idemKey): bool
    {
        $this->retried[] = $idemKey;
        foreach ($this->enqueued as $e) {
            if ($e['idemKey'] === $idemKey) return true;
        }
        return false;
    }
}

// ============================================================================
// Helpers
// ============================================================================
$PASS = 0; $FAIL = 0;
function ok(string $l): void  { global $PASS; $PASS++; echo "  PASS  {$l}\n"; }
function bad(string $l, string $w=''): void { global $FAIL; $FAIL++; echo "  FAIL  {$l} {$w}\n"; }

function makeStayForDebt(
    int $duracion,
    ?string $firstEntryAt,
    string $status = Stay::STATUS_OCCUPIED,
    int $id = 1,
    ?int $codtic = null,
    ?int $codcli = null
): Stay {
    return new Stay(
        $id, 1, $status, $duracion,
        '2026-04-28 09:00:00.000',
        $firstEntryAt, null, null,
        null, $codtic, $codcli, null, null, null, null, null, null,
        '2026-04-28 09:00:00.000', '2026-04-28 09:00:00.000'
    );
}

// Reference: now = 2026-04-28 12:00:00 UTC
$now = gmmktime(12, 0, 0, 4, 28, 2026);
Clock::freeze(new DateTimeImmutable('2026-04-28T12:00:00Z', new DateTimeZone('UTC')));

$debtRepo   = new FakeDebtRepo();
$stayRepo   = new FakeStayRepoDebts();
$outbox     = new FakeOutbox();
$calculator = new OverstayCalculator();

function buildService(FakeDebtRepo $dr, FakeStayRepoDebts $sr, FakeOutbox $ob): DebtsService
{
    $calc = new OverstayCalculator();
    $sm   = new StayStateMachine($sr);
    return new DebtsService($dr, $sr, $sm, $calc, $ob);
}

$rt = new RoomType(1, 'STANDARD', 'Standard', 5, 15, 20, 30); // grace=5

echo "DebtsService\n";

// ============================================================================
// T1: No overstay (30 min actual, 60 contracted) → null
// ============================================================================
$stay1 = makeStayForDebt(60, gmdate('Y-m-d H:i:s', $now - 30*60) . '.000', Stay::STATUS_OCCUPIED, 10);
$stayRepo->byId[10] = $stay1;
$svc = buildService($debtRepo, $stayRepo, $outbox);
$result = $svc->createIfNeeded($stay1, $rt);
if ($result === null) ok('T1: no overstay → null');
else bad('T1: no overstay → null', 'got debt id=' . ($result->id ?? '?'));

// ============================================================================
// T2: Overstay (80 min actual, 60 contracted, grace=5) → debt created
// ============================================================================
$stay2 = makeStayForDebt(60, gmdate('Y-m-d H:i:s', $now - 80*60) . '.000', Stay::STATUS_OCCUPIED, 20, 12345, 67890);
$stayRepo->byId[20] = $stay2;
$svc2 = buildService($debtRepo, $stayRepo, $outbox);
$debt = $svc2->createIfNeeded($stay2, $rt);
if ($debt !== null) ok('T2: overstay detected → debt created');
else bad('T2: overstay detected → debt created');

if ($debt !== null && $debt->excesoMinutos === 20) // 80-60=20
    ok('T2: exceso_minutos correct (20)');
else bad('T2: exceso_minutos', 'got ' . ($debt?->excesoMinutos ?? 'null'));

if ($debt !== null && $debt->status === Debt::STATUS_PENDING_SYNC)
    ok('T2: debt status=PENDING_SYNC');
else bad('T2: debt status=PENDING_SYNC');

// ============================================================================
// T3: Outbox has both 'debt.created' and 'stay.overstay' topics
// ============================================================================
$topics = array_column($outbox->enqueued, 'topic');
if (in_array('debt.created',  $topics, true)) ok('T3: outbox enqueued debt.created');
else bad('T3: outbox enqueued debt.created', json_encode($topics));
if (in_array('stay.overstay', $topics, true)) ok('T3: outbox enqueued stay.overstay');
else bad('T3: outbox enqueued stay.overstay', json_encode($topics));

// ============================================================================
// T3b: stay without VB6 refs → debt.created NOT enqueued (WS-VB6 would 422);
//      once refs exist, rescheduleDebt rebuilds and enqueues it.
// ============================================================================
$debtRepoB = new FakeDebtRepo();
$outboxB   = new FakeOutbox();
$stayNoRefs = makeStayForDebt(60, gmdate('Y-m-d H:i:s', $now - 80*60) . '.000', Stay::STATUS_OCCUPIED, 40);
$stayRepo->byId[40] = $stayNoRefs;
$svcB  = buildService($debtRepoB, $stayRepo, $outboxB);
$debtB = $svcB->createIfNeeded($stayNoRefs, $rt);
$topicsB = array_column($outboxB->enqueued, 'topic');
if ($debtB !== null && !in_array('debt.created', $topicsB, true) && in_array('stay.overstay', $topicsB, true))
    ok('T3b: sin refs VB6 → no se encola debt.created (sí stay.overstay)');
else bad('T3b: sin refs VB6', json_encode($topicsB));

$stayNoRefs->vb6Codtic = 12345;
$stayNoRefs->vb6Codcli = 67890;
$svcB->rescheduleDebt($debtB->id);
$topicsB2 = array_column($outboxB->enqueued, 'topic');
if (in_array('debt.created', $topicsB2, true))
    ok('T3b: con refs → rescheduleDebt reencola debt.created');
else bad('T3b: rescheduleDebt rebuild', json_encode($topicsB2));

// ============================================================================
// T4: TSK-115 — stay transitioned to OVERSTAY
// ============================================================================
if ($stay2->status === Stay::STATUS_OVERSTAY)
    ok('T4: stay→OVERSTAY after debt creation (TSK-115)');
else bad('T4: stay→OVERSTAY', "got status={$stay2->status}");

// ============================================================================
// T5: Idempotency — second call for same stay returns existing debt
// ============================================================================
$outboxCountBefore = count($outbox->enqueued);
$debt2 = $svc2->createIfNeeded($stay2, $rt); // stay already OVERSTAY
if ($debt2 !== null && $debt2->id === $debt->id)
    ok('T5: idempotent — returns existing debt on second call');
else bad('T5: idempotent', 'debt ids differ or null');
if (count($outbox->enqueued) === $outboxCountBefore)
    ok('T5: outbox not re-enqueued on duplicate');
else bad('T5: outbox not re-enqueued', 'extra enqueues: ' . (count($outbox->enqueued) - $outboxCountBefore));

// ============================================================================
// T6: rescheduleDebt unknown id → null
// ============================================================================
$res = $svc2->rescheduleDebt(9999);
if ($res === null) ok('T6: rescheduleDebt unknown id → null');
else bad('T6: rescheduleDebt unknown id → null');

// ============================================================================
// T7: rescheduleDebt on FAILED debt → PENDING_SYNC
// ============================================================================
$debtId = $debt->id;
$debtRepo->rows[$debtId]->status = Debt::STATUS_FAILED;
$res = $svc2->rescheduleDebt($debtId);
if ($res !== null && $res->status === Debt::STATUS_PENDING_SYNC)
    ok('T7: FAILED debt rescheduled → PENDING_SYNC');
else bad('T7: FAILED→PENDING_SYNC', 'status=' . ($res?->status ?? 'null'));

// ============================================================================
// T8: Stay in EXITED state also gets OVERSTAY transition
// ============================================================================
$stay3 = makeStayForDebt(60, gmdate('Y-m-d H:i:s', $now - 80*60) . '.000', Stay::STATUS_EXITED, 30);
$stayRepo->byId[30] = $stay3;
$dr3 = new FakeDebtRepo();
$svc3 = buildService($dr3, $stayRepo, new FakeOutbox());
$svc3->createIfNeeded($stay3, $rt);
if ($stay3->status === Stay::STATUS_OVERSTAY)
    ok('T8: EXITED stay also transitions to OVERSTAY');
else bad('T8: EXITED→OVERSTAY', "got status={$stay3->status}");

Clock::unfreeze();

echo "\nTotal: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
