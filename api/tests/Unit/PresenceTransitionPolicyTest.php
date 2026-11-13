<?php
declare(strict_types=1);

/**
 * F132 — política pura de transiciones de presencia.
 *
 * Run: php tests/Unit/PresenceTransitionPolicyTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\PresenceTransitionPolicy as Policy;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✅ {$label}\n";
        return;
    }
    $fail++;
    echo "  ❌ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "PresenceTransitionPolicy (F132)\n";

// ── holds ────────────────────────────────────────────────────────────────
$special = Policy::holds(true, true, null, null);
check('almacén/timbrado: entrada inmediata', $special['entry_ms'] === 0);
check('almacén/timbrado: ausencia por defecto 0', $special['absent_ms'] === 0);

$specialConfigured = Policy::holds(true, true, '9', '2.5');
check('almacén/timbrado conserva gracia configurada', $specialConfigured['absent_ms'] === 2500);
check('almacén/timbrado ignora la espera de entrada', $specialConfigured['entry_ms'] === 0);

$normal = Policy::holds(true, false, null, null);
check('sala normal: entrada por defecto 0,5 s', $normal['entry_ms'] === 500);
check('sala normal: ausencia por defecto 2 s', $normal['absent_ms'] === 2000);

$normalOff = Policy::holds(true, false, '0', '0');
check('sala normal: 0 desactiva la espera de entrada', $normalOff['entry_ms'] === 0);
check('sala normal: 0 desactiva la gracia de ausencia', $normalOff['absent_ms'] === 0);

$noOverride = Policy::holds(false, false, '9', '9');
check('sin override: no hay transición diferida', $noOverride['entry_ms'] === 0 && $noOverride['absent_ms'] === 0);

$clamped = Policy::holds(true, false, '99', '-3');
check('valores fuera de rango se acotan', $clamped['entry_ms'] === 10000 && $clamped['absent_ms'] === 0);

// ── clasificación ────────────────────────────────────────────────────────
check('aplicado → applied', Policy::outcomeFor(null, true) === Policy::OUTCOME_APPLIED);
check('candidate_absent → pending_absent', Policy::outcomeFor('candidate_absent', false) === Policy::OUTCOME_PENDING_ABSENT);
check('entry_pending → pending_present', Policy::outcomeFor('entry_pending', false) === Policy::OUTCOME_PENDING_PRESENT);
check('no_context → blocked', Policy::outcomeFor('no_context', false) === Policy::OUTCOME_BLOCKED);
check('uncorroborated → blocked', Policy::outcomeFor('uncorroborated', false) === Policy::OUTCOME_BLOCKED);
check('candidate → blocked', Policy::outcomeFor('candidate', false) === Policy::OUTCOME_BLOCKED);
check('range_unverified → unverified', Policy::outcomeFor('range_unverified', false) === Policy::OUTCOME_UNVERIFIED);
check('range_exceeded → out_of_range', Policy::outcomeFor('range_exceeded', false) === Policy::OUTCOME_OUT_OF_RANGE);
check('noop → maintained', Policy::outcomeFor('noop', false) === Policy::OUTCOME_MAINTAINED);
check('refresh → maintained', Policy::outcomeFor('refresh', false) === Policy::OUTCOME_MAINTAINED);
check('duplicate → duplicate', Policy::outcomeFor('duplicate', false) === Policy::OUTCOME_DUPLICATE);
check('stale → stale', Policy::outcomeFor('stale', false) === Policy::OUTCOME_STALE);
check('motivo desconocido sin estado vigente → blocked', Policy::outcomeFor('otro', false) === Policy::OUTCOME_BLOCKED);
check('motivo desconocido con estado vigente → maintained', Policy::outcomeFor('otro', false, true) === Policy::OUTCOME_MAINTAINED);

// ── transiciones ─────────────────────────────────────────────────────────
$holds = ['entry_ms' => 1500, 'absent_ms' => 4000];

$t1 = Policy::transition('PRESENT', 'ABSENT', null, null, 10000, $holds);
check('PRESENT desde ABSENT → pendiente', $t1['action'] === 'pending' && $t1['due_at_ms'] === 11500);
check('PRESENT pendiente → pending_present', $t1['outcome'] === Policy::OUTCOME_PENDING_PRESENT);

$t2 = Policy::transition('PRESENT', 'ABSENT', 'PRESENT', 11500, 11000, $holds);
check('PRESENT dentro de la ventana conserva el plazo', $t2['action'] === 'pending' && $t2['due_at_ms'] === 11500);

$t3 = Policy::transition('PRESENT', 'ABSENT', 'PRESENT', 11500, 11500, $holds);
check('PRESENT al vencer el plazo → aplica', $t3['action'] === 'apply');

$t4 = Policy::transition('ABSENT', 'PRESENT', null, null, 10000, $holds);
check('ABSENT con presencia → pendiente de ausencia', $t4['action'] === 'pending' && $t4['due_at_ms'] === 14000);
check('ABSENT pendiente → pending_absent', $t4['outcome'] === Policy::OUTCOME_PENDING_ABSENT);

$t5 = Policy::transition('ABSENT', 'PRESENT', 'ABSENT', 14000, 12000, $holds);
check('ABSENT repetido no reinicia el plazo', $t5['action'] === 'pending' && $t5['due_at_ms'] === 14000);

$t6 = Policy::transition('ABSENT', 'PRESENT', 'ABSENT', 14000, 14000, $holds);
check('ABSENT al vencer el plazo → aplica', $t6['action'] === 'apply');

$t7 = Policy::transition('ABSENT', 'ABSENT', null, null, 10000, $holds);
check('ABSENT ya vigente → maintained', $t7['action'] === 'maintain' && $t7['reason'] === 'noop');

$t8 = Policy::transition('PRESENT', 'ABSENT', null, null, 10000, ['entry_ms' => 0, 'absent_ms' => 0]);
check('sin holds → no interfiere', $t8['action'] === 'ignore');

$t9 = Policy::transition('PRESENT', 'PRESENT', null, null, 10000, $holds);
check('PRESENT ya vigente → aplica sin espera extra', $t9['action'] === 'apply');

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
