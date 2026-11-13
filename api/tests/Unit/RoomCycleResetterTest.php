<?php
declare(strict_types=1);

/**
 * F136 — Reset de ciclo limpia también el estado transitorio del radar.
 *
 * Guard tests (sin BD): verifican que `RoomCycleResetter` limpia las columnas
 * F132/F133. Sin esta limpieza el primer `PRESENT` del ciclo nuevo reutiliza el
 * episodio anterior y se descarta como `marker_already_used`.
 *
 * Run:
 *   php tests/Unit/RoomCycleResetterTest.php
 */

$PASS = 0; $FAIL = 0;

function pass(string $msg): void { global $PASS; $PASS++; echo "  PASS  {$msg}\n"; }
function fail(string $msg): void { global $FAIL; $FAIL++; echo "  FAIL  {$msg}\n"; }

function source(string $relative): ?string
{
    $path = __DIR__ . '/../../' . $relative;
    if (!is_file($path)) {
        return null;
    }
    $contents = file_get_contents($path);
    return $contents === false ? null : $contents;
}

$resetter = source('src/Domain/Rooms/RoomCycleResetter.php');

if ($resetter === null) {
    fail('RoomCycleResetter.php legible');
} else {
    pass('RoomCycleResetter.php legible');

    $columns = [
        'presence_pending_state',
        'presence_pending_since',
        'presence_pending_due_at',
        'presence_pending_marker',
        'presence_pending_config_key',
        'presence_outcome_json',
        'presence_episode_marker',
        'presence_episode_session',
        'presence_episode_started_at',
        'presence_episode_distance_marker',
        'light_off_at',
    ];
    foreach ($columns as $column) {
        if (str_contains($resetter, $column . ' = NULL')) {
            pass("reset limpia {$column}");
        } else {
            fail("reset NO limpia {$column}");
        }
    }

    if (str_contains($resetter, "presence_state = 'UNKNOWN'")) {
        pass('reset sigue dejando presence_state en UNKNOWN');
    } else {
        fail('reset perdió presence_state = UNKNOWN');
    }
}

// Todos los flujos de ciclo nuevo deben pasar por el resetter compartido.
$flows = [
    'src/Domain/Qr/QrIssueService.php',
    'src/Http/Controllers/QrTestController.php',
    'src/Domain/Rooms/RoomResetService.php',
];
foreach ($flows as $flow) {
    $src = source($flow);
    if ($src !== null && str_contains($src, 'cycleResetter') && str_contains($src, '->reset(')) {
        pass(basename($flow) . ' usa el resetter compartido');
    } else {
        fail(basename($flow) . ' NO usa el resetter compartido');
    }
}

$total = $PASS + $FAIL;
echo "\n" . ($FAIL === 0 ? '✅' : '❌') . " RoomCycleResetter: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
