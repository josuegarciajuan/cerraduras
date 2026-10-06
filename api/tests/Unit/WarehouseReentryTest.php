<?php
declare(strict_types=1);

/**
 * F85 (RF-109.4 / RF-110.1): contrato de re-entrada y fallbacks del pack almacén.
 *
 * Pruebas PURAS de la máquina de estados (sin BD, sin cámara, sin tiempo):
 *  - una reaparición de presencia tras un `ABSENT` acreditado crea una VISITA NUEVA
 *    (no reabre la anterior) y para su clip EXTERIOR;
 *  - `QR_OK`/`DOOR_OPEN` sin presencia dentro de `X` descartan TODAS las grabaciones;
 *  - un segundo `DOOR_OPEN` dentro del ciclo no duplica la visita.
 *
 * Run:
 *   php tests/Unit/WarehouseReentryTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Warehouse\WarehouseRecordingDecision as D;

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }

/** @param array<string,string|null|bool> $state */
function st85(string $state, ?string $trigger = null, bool $confirmed = false): array
{
    return ['state' => $state, 'entry_trigger' => $trigger, 'presence_confirmed' => $confirmed];
}

function hasAll85(array $actions, array $expected): bool
{
    foreach ($expected as $e) {
        if (!in_array($e, $actions, true)) {
            return false;
        }
    }
    return true;
}

function hasNone85(array $actions, array $forbidden): bool
{
    foreach ($forbidden as $f) {
        if (in_array($f, $actions, true)) {
            return false;
        }
    }
    return true;
}

echo "WarehouseReentry Unit Tests (F85)\n";
echo str_repeat("=", 60) . "\n\n";

// ── RF-109.4: re-entrada por presencia tras ABSENT → visita nueva ─────────
$r = D::decide(st85(D::STATE_EXIT_PENDING, 'PRESENCE', true), D::EV_PRESENT);
if ($r['state'] === D::STATE_RECORDING_INSIDE
    && $r['entry_trigger'] === 'PRESENCE'
    && hasAll85($r['actions'], [D::A_STOP_EXT, D::A_CREATE_VISIT, D::A_CONFIRM_ENTRY, D::A_START_EXT, D::A_START_INT, D::A_CLEAR_DEADLINES])
    && hasNone85($r['actions'], [D::A_MARK_EXIT])) {
    pass('RF-109.4: EXIT_PENDING + PRESENT → visita nueva (para EXT previo, crea EXT+INT ENTRY)');
} else {
    fail('RF-109.4: EXIT_PENDING + PRESENT inesperado: ' . json_encode($r));
}

// La visita anterior NO se reabre: no se vuelve a confirmar sobre ella.
$r = D::decide(st85(D::STATE_EXIT_PENDING, 'QR', true), D::EV_PRESENT);
if ($r['state'] === D::STATE_RECORDING_INSIDE && $r['entry_trigger'] === 'PRESENCE'
    && hasAll85($r['actions'], [D::A_CREATE_VISIT])) {
    pass('RF-109.4: re-entrada desde visita QR también crea visita nueva (PRESENCE)');
} else {
    fail('RF-109.4: re-entrada desde QR inesperado: ' . json_encode($r));
}

// ── RF-110.1/110.3: QR sin entrada → descarta TODO y NO_SHOW ──────────────
$r = D::decide(st85(D::STATE_QR_PENDING, 'QR'), D::EV_X_EXPIRED);
if ($r['state'] === D::STATE_IDLE
    && hasAll85($r['actions'], [D::A_STOP_EXT, D::A_STOP_INT, D::A_DISCARD_EXT, D::A_DISCARD_INT, D::A_VISIT_NO_SHOW, D::A_CLOSE_VISIT])
    && hasNone85($r['actions'], [D::A_SET_DEADLINE_M])) {
    pass('RF-110.1: QR sin entrada → IDLE, descarta EXT+INT, NO_SHOW');
} else {
    fail('RF-110.1: QR sin entrada inesperado: ' . json_encode($r));
}

// ── RF-110.2: puerta sin presencia → descarta ambas y NO_SHOW ─────────────
$r = D::decide(st85(D::STATE_QR_PENDING, 'DOOR'), D::EV_X_EXPIRED);
if ($r['state'] === D::STATE_IDLE
    && hasAll85($r['actions'], [D::A_DISCARD_EXT, D::A_DISCARD_INT, D::A_VISIT_NO_SHOW, D::A_CLOSE_VISIT])) {
    pass('RF-110.2: DOOR sin presencia → IDLE, descarta EXT+INT, NO_SHOW');
} else {
    fail('RF-110.2: DOOR sin presencia inesperado: ' . json_encode($r));
}

// ── RF-109.2/110.5: dedupe de disparadores dentro del ciclo ───────────────
foreach ([
    ['DOOR_OPEN', D::STATE_QR_PENDING, 'DOOR'],
    ['DOOR_OPEN', D::STATE_RECORDING_INSIDE, 'DOOR'],
    ['QR_OK',     D::STATE_QR_PENDING, 'QR'],
    ['QR_OK',     D::STATE_RECORDING_INSIDE, 'QR'],
] as $case) {
    [$ev, $state, $trg] = $case;
    $r = D::decide(st85($state, $trg, true), $ev);
    if ($r['state'] === $state && $r['actions'] === []) {
        pass("RF-109.2: $state + $ev → no-op (no duplica)");
    } else {
        fail("RF-109.2: $state + $ev inesperado: " . json_encode($r));
    }
}

// ── Presencia válida sin QR ni puerta (RF-110.4) ──────────────────────────
$r = D::decide(st85(D::STATE_IDLE), D::EV_PRESENT);
if ($r['state'] === D::STATE_RECORDING_INSIDE && $r['entry_trigger'] === 'PRESENCE'
    && hasAll85($r['actions'], [D::A_CREATE_VISIT, D::A_CONFIRM_ENTRY, D::A_START_EXT, D::A_START_INT])) {
    pass('RF-110.4: IDLE + PRESENT → visita PRESENCE válida con ambas cámaras');
} else {
    fail('RF-110.4: IDLE + PRESENT inesperado: ' . json_encode($r));
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
