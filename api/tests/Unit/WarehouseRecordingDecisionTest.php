<?php
declare(strict_types=1);

/**
 * Unit tests for the warehouse recording state machine (F63, RF-71).
 *
 * Pure logic; no DB, no camera, no time. Covers use cases A–D and edge cases.
 *
 * Run:
 *   php tests/Unit/WarehouseRecordingDecisionTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Warehouse\WarehouseRecordingDecision as D;

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }

/** @param array<string,string|null|bool> $state */
function st(string $state, ?string $trigger = null, bool $confirmed = false): array
{
    return ['state' => $state, 'entry_trigger' => $trigger, 'presence_confirmed' => $confirmed];
}

function hasAll(array $actions, array $expected): bool
{
    foreach ($expected as $e) {
        if (!in_array($e, $actions, true)) {
            return false;
        }
    }
    return true;
}

function hasNone(array $actions, array $forbidden): bool
{
    foreach ($forbidden as $f) {
        if (in_array($f, $actions, true)) {
            return false;
        }
    }
    return true;
}

echo "WarehouseRecordingDecision Unit Tests\n";
echo str_repeat("=", 60) . "\n\n";

// ── Caso A: QR con entrada normal ────────────────────────────────────────
$r = D::decide(st(D::STATE_IDLE), D::EV_QR_OK);
if ($r['state'] === D::STATE_QR_PENDING && $r['entry_trigger'] === 'QR'
    && hasAll($r['actions'], [D::A_CREATE_VISIT, D::A_START_EXT, D::A_START_INT, D::A_SET_DEADLINE_X])) {
    pass('A: IDLE + QR_OK → QR_PENDING, crea visita e inicia ambas + deadline X');
} else {
    fail('A: IDLE + QR_OK inesperado: ' . json_encode($r));
}

$r = D::decide(st(D::STATE_QR_PENDING, 'QR'), D::EV_PRESENT);
if ($r['state'] === D::STATE_RECORDING_INSIDE && $r['presence_confirmed'] === true
    && hasAll($r['actions'], [D::A_CONFIRM_ENTRY, D::A_CLEAR_DEADLINES])) {
    pass('A: QR_PENDING + PRESENT → RECORDING_INSIDE, consolida entrada');
} else {
    fail('A: QR_PENDING + PRESENT inesperado: ' . json_encode($r));
}

$r = D::decide(st(D::STATE_RECORDING_INSIDE, 'QR', true), D::EV_ABSENT);
if ($r['state'] === D::STATE_EXIT_PENDING
    && hasAll($r['actions'], [D::A_STOP_INT, D::A_MARK_EXIT, D::A_SET_DEADLINE_M])
    && hasNone($r['actions'], [D::A_DISCARD_INT])) {
    pass('D: RECORDING_INSIDE + ABSENT → EXIT_PENDING (para INT, marca salida, deadline M)');
} else {
    fail('D: RECORDING_INSIDE + ABSENT inesperado: ' . json_encode($r));
}

$r = D::decide(st(D::STATE_EXIT_PENDING, 'QR', true), D::EV_M_EXPIRED);
if ($r['state'] === D::STATE_IDLE
    && hasAll($r['actions'], [D::A_STOP_EXT, D::A_CLOSE_VISIT])) {
    pass('D: EXIT_PENDING + M_EXPIRED → IDLE (para EXT y cierra visita)');
} else {
    fail('D: EXIT_PENDING + M_EXPIRED inesperado: ' . json_encode($r));
}

// ── Caso A': QR sin presencia (NO_SHOW): INT descartada, EXT conservada ──
$r = D::decide(st(D::STATE_QR_PENDING, 'QR'), D::EV_X_EXPIRED);
if ($r['state'] === D::STATE_EXTERIOR_ONLY
    && hasAll($r['actions'], [D::A_STOP_INT, D::A_DISCARD_INT, D::A_SET_DEADLINE_M, D::A_VISIT_NO_SHOW])
    && hasNone($r['actions'], [D::A_DISCARD_EXT, D::A_STOP_EXT])) {
    pass('A\': QR_PENDING(QR) + X_EXPIRED → EXTERIOR_ONLY (descarta INT, conserva EXT)');
} else {
    fail('A\': QR_PENDING(QR) + X_EXPIRED inesperado: ' . json_encode($r));
}

$r = D::decide(st(D::STATE_EXTERIOR_ONLY, 'QR'), D::EV_DOOR_CLOSE);
if ($r['state'] === D::STATE_EXTERIOR_ONLY && hasAll($r['actions'], [D::A_SET_DEADLINE_M])) {
    pass('A\': EXTERIOR_ONLY + DOOR_CLOSE → reinicia margen M');
} else {
    fail('A\': EXTERIOR_ONLY + DOOR_CLOSE inesperado: ' . json_encode($r));
}

$r = D::decide(st(D::STATE_EXTERIOR_ONLY, 'QR'), D::EV_M_EXPIRED);
if ($r['state'] === D::STATE_IDLE && hasAll($r['actions'], [D::A_STOP_EXT, D::A_CLOSE_VISIT])) {
    pass('A\': EXTERIOR_ONLY + M_EXPIRED → IDLE (guarda EXT)');
} else {
    fail('A\': EXTERIOR_ONLY + M_EXPIRED inesperado: ' . json_encode($r));
}

// Late entry during EXTERIOR_ONLY resumes INTERIOR.
$r = D::decide(st(D::STATE_EXTERIOR_ONLY, 'QR'), D::EV_PRESENT);
if ($r['state'] === D::STATE_RECORDING_INSIDE
    && hasAll($r['actions'], [D::A_CONFIRM_ENTRY, D::A_START_INT, D::A_CLEAR_DEADLINES])) {
    pass('A\': EXTERIOR_ONLY + PRESENT → RECORDING_INSIDE (entra tarde)');
} else {
    fail('A\': EXTERIOR_ONLY + PRESENT inesperado: ' . json_encode($r));
}

// ── Caso B: puerta sin QR y sin presencia → se cortan y descartan ambas ──
$r = D::decide(st(D::STATE_IDLE), D::EV_DOOR_OPEN);
if ($r['state'] === D::STATE_QR_PENDING && $r['entry_trigger'] === 'DOOR'
    && hasAll($r['actions'], [D::A_CREATE_VISIT, D::A_START_EXT, D::A_START_INT, D::A_SET_DEADLINE_X])) {
    pass('B: IDLE + DOOR_OPEN → QR_PENDING (trigger DOOR)');
} else {
    fail('B: IDLE + DOOR_OPEN inesperado: ' . json_encode($r));
}

$r = D::decide(st(D::STATE_QR_PENDING, 'DOOR'), D::EV_X_EXPIRED);
if ($r['state'] === D::STATE_IDLE
    && hasAll($r['actions'], [D::A_STOP_EXT, D::A_STOP_INT, D::A_DISCARD_EXT, D::A_DISCARD_INT, D::A_CLOSE_VISIT, D::A_VISIT_NO_SHOW])) {
    pass('B: QR_PENDING(DOOR) + X_EXPIRED → IDLE (corta y descarta ambas)');
} else {
    fail('B: QR_PENDING(DOOR) + X_EXPIRED inesperado: ' . json_encode($r));
}

// ── Caso C: presencia sin QR / puerta ya abierta ─────────────────────────
$r = D::decide(st(D::STATE_IDLE), D::EV_PRESENT);
if ($r['state'] === D::STATE_RECORDING_INSIDE && $r['entry_trigger'] === 'PRESENCE'
    && hasAll($r['actions'], [D::A_CREATE_VISIT, D::A_CONFIRM_ENTRY, D::A_START_EXT, D::A_START_INT])) {
    pass('C: IDLE + PRESENT → RECORDING_INSIDE (visita confirmada ENTERED, F71)');
} else {
    fail('C: IDLE + PRESENT inesperado: ' . json_encode($r));
}

// Reappearance during EXIT_PENDING resumes INTERIOR without reopening the visit.
$r = D::decide(st(D::STATE_EXIT_PENDING, 'PRESENCE', true), D::EV_PRESENT);
if ($r['state'] === D::STATE_RECORDING_INSIDE
    && hasAll($r['actions'], [D::A_START_INT, D::A_CLEAR_DEADLINES])
    && hasNone($r['actions'], [D::A_CREATE_VISIT])) {
    pass('C: EXIT_PENDING + PRESENT → RECORDING_INSIDE (sin reabrir visita)');
} else {
    fail('C: EXIT_PENDING + PRESENT inesperado: ' . json_encode($r));
}

// ── No-op events keep state ──────────────────────────────────────────────
$r = D::decide(st(D::STATE_IDLE), D::EV_ABSENT);
if ($r['state'] === D::STATE_IDLE && $r['actions'] === []) {
    pass('no-op: IDLE + ABSENT → sin acciones');
} else {
    fail('no-op: IDLE + ABSENT inesperado: ' . json_encode($r));
}

$r = D::decide(st(D::STATE_QR_PENDING, 'QR'), D::EV_DOOR_CLOSE);
if ($r['state'] === D::STATE_QR_PENDING && $r['actions'] === []) {
    pass('no-op: QR_PENDING + DOOR_CLOSE → sigue esperando X');
} else {
    fail('no-op: QR_PENDING + DOOR_CLOSE inesperado: ' . json_encode($r));
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
