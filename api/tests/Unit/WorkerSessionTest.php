<?php
declare(strict_types=1);

/**
 * Unit tests for WorkerSession entity and repository (TSK-W20).
 *
 * Run:
 *   php tests/Unit/WorkerSessionTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Workers\WorkerSession;
use App\Domain\Workers\WorkerSessionRepository;
use App\Support\Clock;
use App\Support\Config;
use App\Infrastructure\Db\PdoFactory;

Config::load(__DIR__ . '/../../.env');

$passed = 0; $failed = 0;
function ok(string $l): void { global $passed; $passed++; echo "  PASS  {$l}\n"; }
function bad(string $l, string $w = ''): void { global $failed; $failed++; echo "  FAIL  {$l} {$w}\n"; }

echo "WorkerSessionTest\n";

// ── Entity tests ──

// T1: New session is active
$ws = new WorkerSession(0, 1, 1, '2026-01-01 10:00:00.000', null, null, null, '', '');
if ($ws->isActive()) ok('T1: new session is active');
else bad('T1: new session is active');

// T2: Closed session is not active
$ws->close('EXIT_RULE', '2026-01-01 11:00:00.000');
if (!$ws->isActive()) ok('T2: closed session is not active');
else bad('T2: closed session is not active');

// T3: Close sets exitKind and exitedAt
if ($ws->exitKind === 'EXIT_RULE' && $ws->exitedAt === '2026-01-01 11:00:00.000') {
    ok('T3: close sets exitKind and exitedAt');
} else bad('T3: close sets exitKind and exitedAt');

// T4: Session constants defined
if (WorkerSession::EXIT_KIND_EXIT_RULE === 'EXIT_RULE'
    && WorkerSession::EXIT_KIND_DOOR_EVENT === 'DOOR_EVENT') {
    ok('T4: exit kind constants defined');
} else bad('T4: exit kind constants defined');

// ── Repository tests (in-memory via DB) ──
try {
    $pdo = PdoFactory::make();
    $repo = new WorkerSessionRepository($pdo);

    // T5: Insert creates session with ID
    $now = gmdate('Y-m-d H:i:s') . '.000';
    $ws2 = new WorkerSession(0, 1, 1, $now, null, null, 'corr-1', '', '');
    $id = $repo->insert($ws2);
    if ($id > 0) ok('T5: insert returns positive ID');
    else bad('T5: insert returns positive ID', "got {$id}");

    // T6: findById returns session
    $found = $repo->findById($id);
    if ($found !== null && $found->workerId === 1) ok('T6: findById returns session');
    else bad('T6: findById returns session');

    // T7: findActiveForRoom
    $active = $repo->findActiveForRoom(1);
    if (count($active) >= 1) ok('T7: findActiveForRoom finds active session');
    else bad('T7: findActiveForRoom finds active session', 'got ' . count($active));

    // T8: findActiveForWorker
    $activeW = $repo->findActiveForWorker(1);
    if (count($activeW) >= 1) ok('T8: findActiveForWorker finds active session');
    else bad('T8: findActiveForWorker finds active session');

    // T9: Close session
    $closed = $repo->close($id, 'DOOR_EVENT', $now);
    if ($closed > 0) ok('T9: close returns rowCount > 0');
    else bad('T9: close returns rowCount > 0');

    // T10: After close, findActiveForRoom is empty
    $active2 = $repo->findActiveForRoom(1);
    if (count($active2) === 0) ok('T10: findActiveForRoom empty after close');
    else bad('T10: findActiveForRoom empty after close', 'got ' . count($active2));

    // T11: After close, findActiveForWorker is empty
    $activeW2 = $repo->findActiveForWorker(1);
    if (count($activeW2) === 0) ok('T11: findActiveForWorker empty after close');
    else bad('T11: findActiveForWorker empty after close');

    // T12: findForWorker includes closed
    $all = $repo->findForWorker(1);
    if (count($all) >= 1) ok('T12: findForWorker includes closed sessions');
    else bad('T12: findForWorker includes closed sessions');

    // Clean up
    $pdo->exec("DELETE FROM worker_sessions WHERE worker_id = 1");

} catch (\Throwable $e) {
    bad('DB-TEST', $e->getMessage());
}

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
