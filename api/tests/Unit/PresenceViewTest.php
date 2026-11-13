<?php
declare(strict_types=1);

/**
 * F136 — Vista única de presencia para Radar Studio y dashboard.
 *
 * Guard tests (sin BD): garantizan que ambos paneles consumen la misma vista,
 * que el SSE dejó de omitir el resultado operativo/ventanas y que el dashboard
 * muestra la detección inmediata solo en salas radar.
 *
 * Run:
 *   php tests/Unit/PresenceViewTest.php
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

function requires(string $source, array $needles, string $label): void
{
    foreach ($needles as $needle) {
        if (str_contains($source, $needle)) {
            pass("{$label} contiene {$needle}");
        } else {
            fail("{$label} NO contiene {$needle}");
        }
    }
}

$view = source('src/Infrastructure/Sensors/PresenceView.php');
if ($view === null) {
    fail('PresenceView.php existe');
} else {
    pass('PresenceView.php existe');
    requires($view, [
        'presence_outcome_json',
        'presence_report',
        'RadarFreshness',
        'presence_mode_override',
        'presence_pending_state',
        'presence_episode_started_at',
        'radar_stale',
    ], 'PresenceView');
}

$live = source('src/Http/Controllers/RoomLiveController.php');
if ($live === null) {
    fail('RoomLiveController.php existe');
} else {
    requires($live, [
        'new PresenceView',
        "'presence_outcome'",
        "'presence_mode_override'",
        "'radar' =>",
        "'radar_stale'",
    ], 'RoomLiveController');
}

$sse = source('src/Http/Controllers/EventStreamController.php');
if ($sse === null) {
    fail('EventStreamController.php existe');
} else {
    requires($sse, [
        'new PresenceView',
        'presence_outcome_json',
        "'entry_window_seconds'",
        "'presence_outcome'",
        "'radar' =>",
    ], 'EventStreamController');
}

$dashboard = source('public/dashboard.html');
if ($dashboard === null) {
    fail('dashboard.html existe');
} else {
    requires($dashboard, [
        's-radar-row',
        'presence_mode_override',
        'presence_outcome',
        'DETECTA · COMPROBANDO',
        'crossingHoldMs: radarRoom',
        'closeDecisionMs: radarRoom',
    ], 'dashboard');
}

$total = $PASS + $FAIL;
echo "\n" . ($FAIL === 0 ? '✅' : '❌') . " PresenceView: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
