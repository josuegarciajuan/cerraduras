<?php
declare(strict_types=1);

/**
 * Unit tests for WorkerSessionReusePolicy (F123/RF-338/RF-339).
 *
 * Run: php tests/Unit/WorkerSessionReusePolicyTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\AccessKeys\WorkerSessionReusePolicy;
use App\Domain\Workers\WorkerSession;

$pass = 0;
$fail = 0;

function ok(string $label): void
{
    global $pass;
    $pass++;
    echo "  PASS  {$label}\n";
}

function bad(string $label, string $detail = ''): void
{
    global $fail;
    $fail++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function session(int $id, int $roomId, int $enteredEpoch, int $workerId = 172): WorkerSession
{
    return new WorkerSession(
        $id,
        $workerId,
        $roomId,
        gmdate('Y-m-d H:i:s', $enteredEpoch) . '.000',
        null,
        null,
        null,
        '',
        ''
    );
}

$now = 1700000000;

// T1: no active session in the room → none.
$d = WorkerSessionReusePolicy::decide([], 12, $now, 43200);
($d['action'] === 'none' && $d['session'] === null)
    ? ok('T1: sin sesión activa → none')
    : bad('T1: sin sesión activa', json_encode($d));

// T2: active session in another room → none.
$d = WorkerSessionReusePolicy::decide([session(1, 14, $now - 60)], 12, $now, 43200);
($d['action'] === 'none' && $d['session'] === null)
    ? ok('T2: sesión en otra sala → none')
    : bad('T2: sesión en otra sala', json_encode($d));

// T3: recent active session → reuse.
$recent = session(7, 12, $now - 60);
$d = WorkerSessionReusePolicy::decide([$recent], 12, $now, 43200);
($d['action'] === 'reuse' && $d['session'] === $recent)
    ? ok('T3: sesión reciente → reuse')
    : bad('T3: sesión reciente', json_encode($d));

// T4: session exactly at the TTL → stale.
$d = WorkerSessionReusePolicy::decide([session(8, 12, $now - 43200)], 12, $now, 43200);
($d['action'] === 'stale')
    ? ok('T4: sesión en el límite TTL → stale')
    : bad('T4: sesión en el límite TTL', json_encode($d));

// T5: older than TTL → stale.
$d = WorkerSessionReusePolicy::decide([session(9, 12, $now - 43201)], 12, $now, 43200);
($d['action'] === 'stale')
    ? ok('T5: sesión antigua → stale')
    : bad('T5: sesión antigua', json_encode($d));

// T6: with several sessions in the room, the newest is selected.
$old = session(10, 12, $now - 3600);
$new = session(11, 12, $now - 30);
$d = WorkerSessionReusePolicy::decide([$old, $new], 12, $now, 43200);
($d['action'] === 'reuse' && $d['session'] === $new)
    ? ok('T6: con varias sesiones elige la más reciente')
    : bad('T6: con varias sesiones elige la más reciente', json_encode($d));

// T7: TTL disabled (0) never marks a session as stale.
$d = WorkerSessionReusePolicy::decide([session(12, 12, $now - 999999)], 12, $now, 0);
($d['action'] === 'reuse')
    ? ok('T7: TTL=0 desactiva el autocierre')
    : bad('T7: TTL=0 desactiva el autocierre', json_encode($d));

// T8: a session with an unparsable entered_at is reused (fail-safe).
$badSession = new WorkerSession(13, 172, 12, 'not-a-date', null, null, null, '', '');
$d = WorkerSessionReusePolicy::decide([$badSession], 12, $now, 43200);
($d['action'] === 'reuse')
    ? ok('T8: entered_at inválido → reuse (fail-safe)')
    : bad('T8: entered_at inválido', json_encode($d));

echo "\n  Result: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
