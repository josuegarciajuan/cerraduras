#!/usr/bin/env node
'use strict';

/**
 * Unit tests for the dashboard room-selector rebuild decision.
 *
 * Trazabilidad: RF-59 (ventana QR) no aplica; este test cubre el bug de UX del
 * selector de habitación del panel (`#room-selector`): la reconstrucción de
 * `<option>` cada 5 s cerraba/impedía abrir el picker nativo.
 *
 * Pure logic only: no browser, no network, no DB, no Tuya quota.
 *
 * Run: node api/tests/Unit/room-selector.test.js
 */

const path = require('path');

const {
  shouldRebuildRoomOptions,
} = require(path.join(__dirname, '..', '..', 'public', 'assets', 'room-selector.js'));

let passed = 0;
let failed = 0;

function check(name, cond) {
  if (cond) {
    passed++;
    console.log('  \u2705 ' + name);
  } else {
    failed++;
    console.error('  \u274c ' + name);
  }
}

const NOW = 1_000_000;

console.log('\n\u2500\u2500 room-selector (decisión de rebuild) \u2500\u2500');

// ─── 1. Sin cambios → no tocar el DOM ──────────────────────────────────
const same = shouldRebuildRoomOptions({
  prevSignature: 'sig-A', nextSignature: 'sig-A',
  optionCount: 4, roomCount: 4,
  focused: false, interactingUntil: 0, now: NOW,
});
check('sin cambios (firma y nº de opciones iguales) → no rebuild', same.rebuild === false);
check('sin cambios → no blocked', same.blocked === false);

// ─── 2. Firma cambia sin interacción → rebuild ─────────────────────────
const changed = shouldRebuildRoomOptions({
  prevSignature: 'sig-A', nextSignature: 'sig-B',
  optionCount: 4, roomCount: 4,
  focused: false, interactingUntil: 0, now: NOW,
});
check('firma cambia sin interacción → rebuild', changed.rebuild === true);
check('firma cambia sin interacción → no blocked', changed.blocked === false);

// ─── 3. Cambian los ids (nº de opciones) sin interacción → rebuild ─────
const idsChanged = shouldRebuildRoomOptions({
  prevSignature: 'sig-A', nextSignature: 'sig-A',
  optionCount: 3, roomCount: 4,
  focused: false, interactingUntil: 0, now: NOW,
});
check('cambia el nº de habitaciones → rebuild', idsChanged.rebuild === true);

// ─── 4. Firma cambia con el select enfocado → bloqueado ────────────────
const focused = shouldRebuildRoomOptions({
  prevSignature: 'sig-A', nextSignature: 'sig-B',
  optionCount: 4, roomCount: 4,
  focused: true, interactingUntil: 0, now: NOW,
});
check('firma cambia con el select enfocado → no rebuild', focused.rebuild === false);
check('firma cambia con el select enfocado → blocked (reintentar)', focused.blocked === true);

// ─── 5. Ventana de interacción activa → bloqueado ──────────────────────
const interacting = shouldRebuildRoomOptions({
  prevSignature: 'sig-A', nextSignature: 'sig-B',
  optionCount: 4, roomCount: 4,
  focused: false, interactingUntil: NOW + 5000, now: NOW,
});
check('ventana de interacción activa → no rebuild', interacting.rebuild === false);
check('ventana de interacción activa → blocked', interacting.blocked === true);

// ─── 6. Ventana de interacción expirada → rebuild ──────────────────────
const expired = shouldRebuildRoomOptions({
  prevSignature: 'sig-A', nextSignature: 'sig-B',
  optionCount: 4, roomCount: 4,
  focused: false, interactingUntil: NOW - 1, now: NOW,
});
check('ventana de interacción expirada → rebuild', expired.rebuild === true);

// ─── 7. Sin cambios PERO enfocado → no hay nada que hacer (no blocked) ──
const sameFocused = shouldRebuildRoomOptions({
  prevSignature: 'sig-A', nextSignature: 'sig-A',
  optionCount: 4, roomCount: 4,
  focused: true, interactingUntil: NOW + 5000, now: NOW,
});
check('sin cambios y enfocado → no rebuild', sameFocused.rebuild === false);
check('sin cambios y enfocado → no blocked', sameFocused.blocked === false);

// ─── 8. Robustez ante entradas ausentes/raras ──────────────────────────
const empty = shouldRebuildRoomOptions({});
check('entrada vacía → no rebuild', empty.rebuild === false);
const nulls = shouldRebuildRoomOptions({ prevSignature: null, nextSignature: null, optionCount: 0, roomCount: 0 });
check('firmas null y 0/0 → no rebuild', nulls.rebuild === false);
const firstLoad = shouldRebuildRoomOptions({
  prevSignature: null, nextSignature: 'sig-A',
  optionCount: 0, roomCount: 4,
  focused: false, interactingUntil: 0, now: NOW,
});
check('primera carga (0 opciones, llegan 4) → rebuild', firstLoad.rebuild === true);

// ─── 9. Módulo require-safe ────────────────────────────────────────────
check('module exports the pure decision function',
  typeof shouldRebuildRoomOptions === 'function');

console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' room-selector: ' + passed + ' passed, ' + failed + ' failed\n');
process.exit(failed > 0 ? 1 : 0);
