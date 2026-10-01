#!/usr/bin/env node
'use strict';

/**
 * Unit tests for the warehouse croquis pure logic (F67, RF-77).
 *
 * Trazabilidad: RF-77.1/77.2/77.5 · design.md §31.4 · tasks.md TSK-F67-03.
 *
 * Pure-logic tests only: no network, no DB, no DOM, no Tuya quota.
 *
 * Run: node api/tests/Unit/croquis-logic.test.js
 */

const path = require('path');

const {
  deriveCroquis,
  DOOR_PULSE_MS,
} = require(path.join(__dirname, '..', '..', 'public', 'assets', 'croquis-logic.js'));

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

const BASE = Date.parse('2026-10-01T08:00:00Z');

function snap(live, occupied) {
  return { occupied: !!occupied, live: live || {} };
}

console.log('\n\u2500\u2500 croquis-logic (RF-77) \u2500\u2500');

// ─── 1. Puerta abierta por sensor ──────────────────────────────────────
const open = deriveCroquis(snap({ door_state: 'OPEN', presence_state: 'ABSENT' }), BASE);
check('door OPEN → doorOpen=true', open.doorOpen === true);
check('door OPEN → classes.open=true', open.classes.open === true);
check('door OPEN → chip PUERTA ABIERTA', open.chips.door.text === 'PUERTA ABIERTA');
check('door OPEN → chip ok', open.chips.door.mod === 'ok');

// ─── 2. Puerta cerrada sin pulso ───────────────────────────────────────
const closed = deriveCroquis(snap({
  door_state: 'CLOSED',
  presence_state: 'ABSENT',
  last_open_at: '2026-10-01 07:00:00.000',
  last_close_at: '2026-10-01 07:00:02.000',
}), BASE);
check('door CLOSED → doorOpen=false', closed.doorOpen === false);
check('door CLOSED → chip PUERTA CERRADA', closed.chips.door.text === 'PUERTA CERRADA');

// ─── 3. Pulso anti-colapso: OPEN+CLOSED en el mismo ciclo ──────────────
const pulseNow = BASE + 500;
const pulsing = deriveCroquis(snap({
  door_state: 'CLOSED',
  presence_state: 'PRESENT',
  last_open_at: '2026-10-01 08:00:00.000',
  last_close_at: '2026-10-01 08:00:00.200',
}), pulseNow);
check('OPEN reciente + CLOSED → pulso abre la puerta', pulsing.doorOpen === true);
check('pulso dentro de DOOR_PULSE_MS', pulseNow < BASE + DOOR_PULSE_MS);

// ─── 4. Pulso caducado ─────────────────────────────────────────────────
const noPulse = deriveCroquis(snap({
  door_state: 'CLOSED',
  presence_state: 'PRESENT',
  last_open_at: '2026-10-01 08:00:00.000',
}), BASE + DOOR_PULSE_MS + 1);
check('pulso caducado → puerta cerrada', noPulse.doorOpen === false);

// ─── 5. Persona dentro por visita ──────────────────────────────────────
const occupied = deriveCroquis(snap({ door_state: 'CLOSED', presence_state: 'ABSENT' }, true), BASE);
check('occupied → personInside=true', occupied.personInside === true);
check('occupied → classes.occupied=true', occupied.classes.occupied === true);
check('occupied → chip PRESENTE ok', occupied.chips.presence.text === 'PRESENTE' && occupied.chips.presence.mod === 'ok');

// ─── 6. Presencia sin visita (sensor) ──────────────────────────────────
const presenceOnly = deriveCroquis(snap({ door_state: 'CLOSED', presence_state: 'PRESENT' }, false), BASE);
check('presence PRESENT sin visita → personInside=true', presenceOnly.personInside === true);
check('presence PRESENT → chip PRESENCIA warn', presenceOnly.chips.presence.text === 'PRESENCIA' && presenceOnly.chips.presence.mod === 'warn');

// ─── 7. Vacío ──────────────────────────────────────────────────────────
const empty = deriveCroquis(snap({ door_state: 'CLOSED', presence_state: 'ABSENT' }, false), BASE);
check('vacío → personInside=false', empty.personInside === false);
check('vacío → classes.outside=true', empty.classes.outside === true);
check('vacío → chip VACÍO dim', empty.chips.presence.text === 'VACÍO' && empty.chips.presence.mod === 'dim');

// ─── 8. Sin datos ──────────────────────────────────────────────────────
const unknown = deriveCroquis(snap({}), BASE);
check('UNKNOWN+UNKNOWN → unknown=true', unknown.unknown === true);
check('UNKNOWN → personInside=false', unknown.personInside === false);
check('UNKNOWN → chip SIN DATOS', unknown.chips.presence.text === 'SIN DATOS');

// ─── 9. Luz (switch) ───────────────────────────────────────────────────
const lightOn = deriveCroquis(snap({ switch_state: 'ON' }), BASE);
const lightOff = deriveCroquis(snap({ switch_state: 'OFF' }), BASE);
const lightUnknown = deriveCroquis(snap({}), BASE);
check('switch ON → chip LUZ ON warn', lightOn.chips.light.text === 'LUZ ON' && lightOn.chips.light.mod === 'warn');
check('switch ON → classes.lightOn=true', lightOn.classes.lightOn === true);
check('switch OFF → chip LUZ OFF dim', lightOff.chips.light.text === 'LUZ OFF' && lightOff.chips.light.mod === 'dim');
check('switch UNKNOWN → chip LUZ —', lightUnknown.chips.light.text === 'LUZ —');

// ─── 10. Descripción accesible ─────────────────────────────────────────
const openInside = deriveCroquis(snap({ door_state: 'OPEN', presence_state: 'PRESENT' }, true), BASE);
check('desc: puerta abierta + persona dentro',
  openInside.desc.indexOf('Puerta abierta') === 0 && openInside.desc.indexOf('Persona dentro') >= 0);
check('desc: vacío sin datos de luz',
  empty.desc.indexOf('Almacén vacío') >= 0 && empty.desc.indexOf('Luz sin datos') >= 0);

// ─── 11. Módulo require-safe ───────────────────────────────────────────
check('module exports the pure croquis logic', typeof deriveCroquis === 'function');
check('DOOR_PULSE_MS definido', DOOR_PULSE_MS === 1200);

console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' croquis-logic: ' + passed + ' passed, ' + failed + ' failed\n');
if (failed > 0) process.exit(1);
process.exit(0);
