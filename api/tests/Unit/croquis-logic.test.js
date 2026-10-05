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

// F77.7: sin estado real, el último comando se muestra como estimación (?) sin
// encender el foco del croquis.
const lightInferred = deriveCroquis(snap({ switch_state: 'UNKNOWN', switch_state_inferred: 'ON' }), BASE);
check('F77.7 switch inferido ON → chip LUZ ON? dim',
  lightInferred.chips.light.text === 'LUZ ON?' && lightInferred.chips.light.mod === 'dim');
check('F77.7 switch inferido no enciende el foco', lightInferred.classes.lightOn === false);

// ─── 10. Descripción accesible ─────────────────────────────────────────
const openInside = deriveCroquis(snap({ door_state: 'OPEN', presence_state: 'PRESENT' }, true), BASE);
check('desc: puerta abierta + persona dentro',
  openInside.desc.indexOf('Puerta abierta') === 0 && openInside.desc.indexOf('Persona dentro') >= 0);
check('desc: vacío sin datos de luz',
  empty.desc.indexOf('Almacén vacío') >= 0 && empty.desc.indexOf('Luz sin datos') >= 0);

// ─── 11. F72 (RF-83): el estado de puerta persiste ─────────────────────
// El MC400D es edge-triggered: un CLOSED viejo sigue siendo CERRADA, y un
// OPEN viejo sigue siendo ABIERTA, hasta que llegue el evento contrario.
const oldClosed = deriveCroquis(snap({
  door_state: 'CLOSED',
  presence_state: 'ABSENT',
  door_age_seconds: 99999,
}), BASE);
check('F72 CLOSED antiguo → PUERTA CERRADA', oldClosed.chips.door.text === 'PUERTA CERRADA');
check('F72 CLOSED antiguo → desc "Puerta cerrada"', oldClosed.desc.indexOf('Puerta cerrada') === 0);

const oldOpen = deriveCroquis(snap({
  door_state: 'OPEN',
  presence_state: 'ABSENT',
  door_age_seconds: 99999,
}), BASE);
check('F72 OPEN antiguo → PUERTA ABIERTA', oldOpen.chips.door.text === 'PUERTA ABIERTA');
check('F72 OPEN antiguo → doorOpen=true', oldOpen.doorOpen === true);

const noDoorState = deriveCroquis(snap({
  presence_state: 'ABSENT',
  door_age_seconds: null,
}), BASE);
check('F72 sin door_state → PUERTA SIN DATOS', noDoorState.chips.door.text === 'PUERTA SIN DATOS');

// ─── 12. F71 (RF-81): presencia del almacén pinta al monigote dentro ───
const whPresence = deriveCroquis(snap({
  door_state: 'CLOSED',
  presence_state: 'PRESENT',
  door_age_seconds: 3,
  presence_age_seconds: 2,
}, false), BASE);
check('F71 presencia almacén → personInside=true', whPresence.personInside === true);
check('F71 presencia almacén → classes.occupied=true', whPresence.classes.occupied === true);

// ─── 13. F77.5: estado de puerta viejo → SIN DATOS, no "abierta" ───────
const staleOpen = deriveCroquis(snap({
  door_state: 'OPEN', presence_state: 'ABSENT', door_stale: true, door_age_seconds: 600,
}), BASE);
check('F77.5 OPEN stale → doorOpen=false', staleOpen.doorOpen === false);
check('F77.5 OPEN stale → chip PUERTA SIN DATOS (warn)',
  staleOpen.chips.door.text.indexOf('PUERTA SIN DATOS') === 0 && staleOpen.chips.door.mod === 'warn');
check('F77.5 OPEN stale → desc "no fiable"', staleOpen.desc.indexOf('no fiable') >= 0);

const staleClosed = deriveCroquis(snap({
  door_state: 'CLOSED', presence_state: 'ABSENT', door_stale: true, door_age_seconds: 600,
}), BASE);
check('F77.5 CLOSED stale → PUERTA CERRADA (edge-triggered legítimo)',
  staleClosed.chips.door.text === 'PUERTA CERRADA' && staleClosed.doorOpen === false);

const freshOpen = deriveCroquis(snap({
  door_state: 'OPEN', presence_state: 'ABSENT', door_stale: false, door_age_seconds: 3,
}), BASE);
check('F77.5 OPEN fresco → PUERTA ABIERTA', freshOpen.doorOpen === true && freshOpen.chips.door.text === 'PUERTA ABIERTA');

// ─── 14. F80 (RF-103.4): fases en vivo del monigote ────────────────────
check('F80 vacío → phase outside', empty.phase === 'outside');
check('F80 puerta abierta sin presencia → phase near', open.phase === 'near');

const crossing = deriveCroquis(snap({ door_state: 'OPEN', presence_state: 'PRESENT' }, false), BASE);
check('F80 puerta abierta + presencia → phase crossing', crossing.phase === 'crossing');
check('F80 presencia estable (puerta cerrada) → phase inside', whPresence.phase === 'inside');

const recentNear = deriveCroquis(snap({
  door_state: 'CLOSED',
  presence_state: 'ABSENT',
  last_open_at: '2026-10-01 07:59:58.000',
}), BASE);
check('F80 apertura reciente sin presencia → phase near', recentNear.phase === 'near');

const recentEvt = deriveCroquis(snap({
  door_state: 'CLOSED',
  presence_state: 'ABSENT',
  recent_presence: [{ sensor: 'PRESENCE', value: 'PRESENT', occurred_at: '2026-10-01 07:59:56.000' }],
}), BASE);
check('F80 PRESENT reciente aplicado → phase inside', recentEvt.phase === 'inside');

const staleEvt = deriveCroquis(snap({
  door_state: 'CLOSED',
  presence_state: 'ABSENT',
  recent_presence: [{ sensor: 'PRESENCE', value: 'PRESENT', occurred_at: '2026-10-01 07:30:00.000' }],
}), BASE);
check('F80 PRESENT antiguo → phase outside', staleEvt.phase === 'outside');

// ─── 15. Módulo require-safe ───────────────────────────────────────────
check('module exports the pure croquis logic', typeof deriveCroquis === 'function');
check('DOOR_PULSE_MS definido', DOOR_PULSE_MS === 1200);

console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' croquis-logic: ' + passed + ' passed, ' + failed + ' failed\n');
if (failed > 0) process.exit(1);
process.exit(0);
