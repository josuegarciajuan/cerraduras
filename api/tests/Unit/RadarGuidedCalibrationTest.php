<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarGuidedCalibration as W;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }
function near(string $m, float $a, float $e, float $tol = 0.001): void { abs($a - $e) <= $tol ? ok($m) : ko($m . ' — expected ' . $e . ', got ' . $a); }

const T0 = '2026-11-11T10:00:00Z';
const T1 = '2026-11-11T10:00:04Z';
const T2 = '2026-11-11T10:00:07Z';
const T3 = '2026-11-11T10:00:10Z';
const T4 = '2026-11-11T10:00:11Z';
const T5 = '2026-11-11T10:00:13Z';
const T6 = '2026-11-11T10:00:20Z';
const T7 = '2026-11-11T10:00:36Z';

$SETTINGS = [
    'maximum_range' => 3.0,
    'minimum_range' => 0.0,
    'radar_sensitivity' => 7,
    'detection_delay' => 0.1,
    'fading_time' => 15.5,
];

function baseState(): array
{
    $initial = W::initial(12, $GLOBALS['SETTINGS'], 'cfg-1', T0);
    return W::markProfileApplied($initial, 12, $GLOBALS['SETTINGS'], 'cfg-1', T0);
}

function atStep(string $stepId, string $startAt): array
{
    $state = baseState();
    $ids = [W::SEES_YOU, W::WALK, W::SENSITIVITY, W::OUTSIDE_1, W::INSIDE_1, W::OUTSIDE_2];
    foreach ($ids as $id) {
        if ($id === $stepId) {
            return W::startStep($state, $id, $startAt, ['authentic_marker' => 'base']);
        }
        $state = W::markPassed($state, $id, $startAt, ['message' => 'ok']);
    }
    return $state;
}

function ctx(string $now, string $authentic, string $marker = 'm1', array $presence = [], array $distances = [], bool $stale = false): array
{
    return [
        'now' => $now,
        'authentic_state' => $authentic,
        'authentic_marker' => $marker,
        'stale' => $stale,
        'presence_reports' => $presence,
        'distance_reports' => $distances,
        'settings' => $GLOBALS['SETTINGS'],
    ];
}

echo "Radar guided calibration\n";

// ── Vista inicial y bloqueo ─────────────────────────────────────────────
$view = W::view(null);
same('sin estado → paso 1 activo', $view['steps'][0]['status'], W::STEP_ACTIVE);
same('sin estado → paso 2 bloqueado', $view['steps'][1]['status'], W::STEP_LOCKED);
same('sin estado → current profile', $view['current_step'], W::PROFILE);
check('hay 8 pasos', count($view['steps']) === 8);

$state = baseState();
$view = W::view($state);
same('perfil aplicado → perfil pasado', $view['steps'][0]['status'], W::STEP_PASSED);
same('perfil aplicado → te ve activo/pendiente', $view['steps'][1]['status'], W::STEP_PENDING);
same('perfil aplicado → paseo bloqueado', $view['steps'][2]['status'], W::STEP_LOCKED);
same('perfil aplicado → current sees_you', $state['current_step'], W::SEES_YOU);

// ── ¿El radar te ve? ────────────────────────────────────────────────────
$s = atStep(W::SEES_YOU, T0);
$s = W::evaluateAuto($s, T1, ctx(T1, 'PRESENT', 'p1'));
same('te ve: primera lectura → esperando', $s['steps'][W::SEES_YOU]['status'], W::STEP_ACTIVE);
$s = W::evaluateAuto($s, T2, ctx(T2, 'PRESENT', 'p1'));
same('te ve: aún dentro de la ventana de 10 s', $s['steps'][W::SEES_YOU]['status'], W::STEP_ACTIVE);
$s = W::evaluateAuto($s, T3, ctx(T3, 'PRESENT', 'p1'));
same('te ve: ventana de 10 s → pasado', $s['steps'][W::SEES_YOU]['status'], W::STEP_PASSED);
same('te ve: avanza a paseo', $s['current_step'], W::WALK);

$s = atStep(W::SEES_YOU, T0);
$s = W::evaluateAuto($s, T4, ctx(T4, 'ABSENT', 'a1'));
same('te ve: sin presencia → falla', $s['steps'][W::SEES_YOU]['status'], W::STEP_FAILED);
same('te ve: motivo no_presence', $s['steps'][W::SEES_YOU]['detail']['reason'], 'no_presence');

// ── Fuera ───────────────────────────────────────────────────────────────
$s = atStep(W::OUTSIDE_1, T0);
$s = W::evaluateAuto($s, '2026-11-11T10:00:01Z', ctx('2026-11-11T10:00:01Z', 'PRESENT', 'p1'));
same('fuera: espera ausencia', $s['steps'][W::OUTSIDE_1]['detail']['waiting_for'], 'absent');
$s = W::evaluateAuto($s, '2026-11-11T10:00:05Z', ctx('2026-11-11T10:00:05Z', 'ABSENT', 'a1'));
check('fuera: se arma al estar ausente', !empty($s['steps'][W::OUTSIDE_1]['armed_at']));
$s = W::evaluateAuto($s, T6, ctx(T6, 'ABSENT', 'a1'));
same('fuera: aún esperando', $s['steps'][W::OUTSIDE_1]['status'], W::STEP_ACTIVE);
$s = W::evaluateAuto($s, T7, ctx(T7, 'ABSENT', 'a1'));
same('fuera: 30 s sin detección → pasado', $s['steps'][W::OUTSIDE_1]['status'], W::STEP_PASSED);
same('fuera: avanza a dentro', $s['current_step'], W::INSIDE_1);

$s = atStep(W::OUTSIDE_1, T0);
$s = W::evaluateAuto($s, '2026-11-11T10:00:05Z', ctx('2026-11-11T10:00:05Z', 'ABSENT', 'a1'));
$s = W::evaluateAuto($s, T3, ctx(T3, 'PRESENT', 'p2', [
    ['received_at' => '2026-11-11T10:00:09Z', 'value' => 'PRESENT', 'marker' => 'p2'],
]));
same('fuera: PRESENT → falla', $s['steps'][W::OUTSIDE_1]['status'], W::STEP_FAILED);
same('fuera: motivo detected_outside', $s['steps'][W::OUTSIDE_1]['detail']['reason'], 'detected_outside');

// ── Dentro (reentrada) ──────────────────────────────────────────────────
$s = atStep(W::INSIDE_1, T0);
$s = W::evaluateAuto($s, '2026-11-11T10:00:01Z', ctx('2026-11-11T10:00:01Z', 'PRESENT', 'p1'));
same('dentro: pide salir primero', $s['steps'][W::INSIDE_1]['detail']['waiting_for'], 'absent');
$s = W::evaluateAuto($s, '2026-11-11T10:00:05Z', ctx('2026-11-11T10:00:05Z', 'ABSENT', 'a1'));
check('dentro: se arma al estar ausente', !empty($s['steps'][W::INSIDE_1]['armed_at']));
$s = W::evaluateAuto($s, T3, ctx(T3, 'PRESENT', 'p2', [
    ['received_at' => '2026-11-11T10:00:10Z', 'value' => 'PRESENT', 'marker' => 'p2'],
]));
same('dentro: detectado, esperando estabilidad', $s['steps'][W::INSIDE_1]['status'], W::STEP_ACTIVE);
$s = W::evaluateAuto($s, T5, ctx(T5, 'PRESENT', 'p2'));
same('dentro: estable → pasado', $s['steps'][W::INSIDE_1]['status'], W::STEP_PASSED);
same('dentro: avanza a fuera final', $s['current_step'], W::OUTSIDE_2);

$s = atStep(W::INSIDE_1, T0);
$s = W::evaluateAuto($s, '2026-11-11T10:00:05Z', ctx('2026-11-11T10:00:05Z', 'ABSENT', 'a1'));
$s = W::evaluateAuto($s, T7, ctx(T7, 'ABSENT', 'a1'));
same('dentro: sin presencia → falla', $s['steps'][W::INSIDE_1]['status'], W::STEP_FAILED);
same('dentro: motivo no_presence', $s['steps'][W::INSIDE_1]['detail']['reason'], 'no_presence');

// ── Paseo manual ────────────────────────────────────────────────────────
$s = atStep(W::WALK, T0);
$presence = [
    ['received_at' => '2026-11-11T10:00:00Z', 'value' => 'PRESENT', 'marker' => 'p1'],
    ['received_at' => '2026-11-11T10:00:10Z', 'value' => 'ABSENT', 'marker' => 'a1'],
];
$distances = [
    ['received_at' => '2026-11-11T10:00:00Z', 'distance_m' => 1.0],
    ['received_at' => '2026-11-11T10:00:03Z', 'distance_m' => 1.2],
    ['received_at' => '2026-11-11T10:00:06Z', 'distance_m' => 1.5],
    ['received_at' => '2026-11-11T10:00:09Z', 'distance_m' => 1.8],
];
$s = W::evaluateWalk($s, '2026-11-11T10:00:12Z', ctx('2026-11-11T10:00:12Z', 'ABSENT', 'a1', $presence, $distances));
same('paseo: no se auto-supera', $s['steps'][W::WALK]['status'], W::STEP_ACTIVE);
near('paseo: última distancia', (float) $s['steps'][W::WALK]['detail']['last_distance'], 1.8);
near('paseo: pérdida detectada', (float) $s['steps'][W::WALK]['detail']['loss_distance'], 1.5);
near('paseo: alcance configurado', (float) $s['steps'][W::WALK]['detail']['configured_max'], 3.0);
$s = W::markPassed($s, W::WALK, '2026-11-11T10:00:13Z', ['message' => 'El operador marca prueba buena']);
same('paseo: marcado por el operador → pasado', $s['steps'][W::WALK]['status'], W::STEP_PASSED);
same('paseo: avanza a sensibilidad', $s['current_step'], W::SENSITIVITY);

// ── Sensibilidad ────────────────────────────────────────────────────────
$s = atStep(W::SENSITIVITY, T0);
$s = W::sensitivityAwaiting($s, ['value' => 8, 'reason' => 'test', 'confidence' => 'high'], T1);
same('sensibilidad: espera decisión', $s['steps'][W::SENSITIVITY]['status'], W::STEP_AWAITING);
same('sensibilidad: guarda recomendación', $s['steps'][W::SENSITIVITY]['recommendation']['value'], 8);
$s = W::sensitivityDecision($s, 'keep', T2);
same('sensibilidad: mantener → pasado', $s['steps'][W::SENSITIVITY]['status'], W::STEP_PASSED);
same('sensibilidad: avanza a fuera', $s['current_step'], W::OUTSIDE_1);

$s = atStep(W::SENSITIVITY, T0);
$s = W::sensitivityAwaiting($s, ['value' => 8], T1);
$s = W::sensitivityDecision($s, 'accept', T2);
same('sensibilidad: aceptar → pasado', $s['steps'][W::SENSITIVITY]['status'], W::STEP_PASSED);
same('sensibilidad: decisión accept', $s['steps'][W::SENSITIVITY]['decision'], 'accept');

// ── Reintentar y configuración cambiada ─────────────────────────────────
$s = atStep(W::SEES_YOU, T0);
$s = W::evaluateAuto($s, T4, ctx(T4, 'ABSENT', 'a1'));
$s = W::retryStep($s, '2026-11-11T10:00:12Z');
same('reintentar: vuelve a pendiente', $s['steps'][W::SEES_YOU]['status'], W::STEP_PENDING);
same('reintentar: mismo paso', $s['current_step'], W::SEES_YOU);

$s = baseState();
$s = W::markConfigChanged($s, $GLOBALS['SETTINGS'], T1);
same('config cambiada: estado', $s['status'], W::STATUS_CONFIG_CHANGED);
same('config cambiada: vuelve al perfil', $s['current_step'], W::PROFILE);
same('config cambiada: perfil activo', $s['steps'][W::PROFILE]['status'], W::STEP_ACTIVE);

// ── Secuencia completa hasta done ───────────────────────────────────────
$s = baseState();
foreach ([W::SEES_YOU, W::WALK, W::SENSITIVITY, W::OUTSIDE_1, W::INSIDE_1, W::OUTSIDE_2] as $id) {
    $s = W::markPassed($s, $id, '2026-11-11T10:01:00Z', ['message' => 'ok']);
}
same('secuencia completa: done', $s['status'], W::STATUS_DONE);
same('secuencia completa: current done', $s['current_step'], W::DONE);
same('secuencia completa: paso done pasado', $s['steps'][W::DONE]['status'], W::STEP_PASSED);
check('secuencia completa: summary', is_array($s['summary']));
same('secuencia completa: pasos pasados', count(array_filter($s['steps'], static fn (array $x): bool => ($x['status'] ?? '') === W::STEP_PASSED)), 8);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
