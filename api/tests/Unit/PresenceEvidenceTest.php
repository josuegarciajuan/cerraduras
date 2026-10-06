<?php
declare(strict_types=1);

/**
 * Unit tests for the warehouse radar evidence classifier (F84, RF-107).
 *
 * Pure logic; no DB, no time, no camera. Grounded on the 2026-10-06 phantom
 * analysis: a ghost episode is a single `move`+`presence` blip while real
 * activity re-emits transitions.
 *
 * Run:
 *   php tests/Unit/PresenceEvidenceTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Warehouse\PresenceEvidence as P;

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }

echo "PresenceEvidence Unit Tests\n";
echo str_repeat("=", 60) . "\n\n";

// ── Fantasma típico: 1 move + 1 presence (2 eventos) en 25 s, sin puerta ──
if (P::isConfirmed(1, 2, 25.0, false) === false) {
    pass('fantasma m1 p1 / 25 s / sin puerta → ruido');
} else {
    fail('fantasma m1 p1 debería ser ruido');
}

// ── Movimiento repetido: 2 moves → real ────────────────────────────────────
if (P::isConfirmed(2, 3, 25.0, false) === true) {
    pass('moves=2 → real');
} else {
    fail('moves=2 debería confirmar');
}

// ── Muchos eventos aunque moves=1 (p. ej. m1 p3) → real ────────────────────
if (P::isConfirmed(1, 3, 20.0, false) === true) {
    pass('events=3 → real');
} else {
    fail('events=3 debería confirmar');
}

// ── Presencia estática sostenida > 300 s → real ────────────────────────────
if (P::isConfirmed(1, 2, 301.0, false) === true) {
    pass('estático 301 s (m1 p1) → real');
} else {
    fail('estático >=300 s debería confirmar');
}
if (P::isConfirmed(1, 2, 299.0, false) === false) {
    pass('estático 299 s (m1 p1) → ruido');
} else {
    fail('estático <300 s debería ser ruido');
}

// ── Evento de puerta dentro del episodio → evidencia extra ────────────────
if (P::isConfirmed(1, 2, 20.0, true) === true) {
    pass('m1 p1 + evento de puerta → real');
} else {
    fail('evento de puerta debería confirmar');
}

// ── Umbrales configurables ─────────────────────────────────────────────────
if (P::isConfirmed(1, 2, 25.0, false, ['min_moves' => 1]) === true) {
    pass('config min_moves=1 → confirma m1');
} else {
    fail('config min_moves=1 debería confirmar');
}
if (P::isConfirmed(2, 3, 400.0, true, [
        'min_moves' => 5, 'min_events' => 5, 'static_seconds' => 9999,
    ]) === true) {
    pass('config estricta + puerta → confirma solo por puerta');
} else {
    fail('la puerta debe confirmar aunque los umbrales sean altos');
}

// ── Config desde fila de room_types (defaults y normalización) ─────────────
$cfg = P::configFromRow([]);
if ($cfg['min_moves'] === 2 && $cfg['min_events'] === 3 && $cfg['static_seconds'] === 300) {
    pass('configFromRow vacío → defaults 2/3/300');
} else {
    fail('defaults inesperados: ' . json_encode($cfg));
}
$cfg = P::configFromRow([
    'warehouse_presence_min_moves' => 4,
    'warehouse_presence_min_events' => 0,
    'warehouse_presence_static_seconds' => 60,
]);
if ($cfg['min_moves'] === 4 && $cfg['min_events'] === 3 && $cfg['static_seconds'] === 60) {
    pass('configFromRow normaliza 0 al default y respeta el resto');
} else {
    fail('configFromRow inesperado: ' . json_encode($cfg));
}

// ── Episodio vacío / sin datos nunca confirma ─────────────────────────────
if (P::isConfirmed(0, 0, 0.0, false) === false) {
    pass('0 eventos → ruido');
} else {
    fail('0 eventos debería ser ruido');
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
