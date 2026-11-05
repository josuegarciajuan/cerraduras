#!/usr/bin/env node
'use strict';

/**
 * Unit tests for the video-intercom pure logic (F115, RF-286…RF-292).
 *
 * Trazabilidad: RF-286…RF-292 · design.md §F115 · tasks.md TSK-F115.
 * Pure logic only: no network, no DB, no DOM, no Tuya quota.
 *
 * Run: node api/tests/Unit/timbre-logic.test.js
 */

const path = require('path');

const {
  DOOR_PULSE_MS,
  OPEN_COOLDOWN_MS,
  filterWarehouses,
  pickDefaultRoom,
  pickInteriorCamera,
  cameraSignature,
  derivePresence,
  deriveDoor,
  canOpen,
  doorOutcome,
  roomMismatch,
  formatAge,
} = require(path.join(__dirname, '..', '..', 'public', 'assets', 'timbre-logic.js'));

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

const BASE = Date.parse('2026-11-05T10:00:00Z');

console.log('\n\u2500\u2500 timbre-logic (F115) \u2500\u2500');

// ─── 1. Almacenes y sala por defecto ──────────────────────────────────
const ROOMS = [
  { id: 1, code: '101', room_type_code: 'DOUBLE' },
  { id: 12, code: 'almacen', room_type_code: 'ALMACEN_BEBIDAS' },
  { id: 14, code: 'almacenZigbee', room_type_code: 'ALMACEN_BEBIDAS' },
];
const WH = filterWarehouses(ROOMS);
check('solo salas ALMACEN_BEBIDAS', WH.length === 2 && WH[0].id === 12 && WH[1].id === 14);
check('pickDefaultRoom(?room=12) respeta la petición', pickDefaultRoom(WH, 12).id === 12);
check('pickDefaultRoom sin petición → almacenZigbee', pickDefaultRoom(WH, 0, 'almacenZigbee').id === 14);
check('pickDefaultRoom con petición inválida → preferida', pickDefaultRoom(WH, 999, 'almacenZigbee').id === 14);
check('pickDefaultRoom sin preferida → primera', pickDefaultRoom(WH, 0, 'no-existe').id === 12);
check('pickDefaultRoom sin almacenes → null', pickDefaultRoom([], 0, 'almacenZigbee') === null);

// ─── 2. Cámara INTERIOR ───────────────────────────────────────────────
const CAMS = [
  { id: 7, position: 'EXTERIOR', enabled: true, mjpeg_url: '/almacen-live?id=7' },
  { id: 9, position: 'INTERIOR', enabled: true, mjpeg_url: '/almacen-live?id=9', recording: true },
];
check('pickInteriorCamera elige INTERIOR', pickInteriorCamera(CAMS).id === 9);
check('pickInteriorCamera sin cámaras → null', pickInteriorCamera([]) === null);
check('pickInteriorCamera ignora mayúsculas/minúsculas',
  pickInteriorCamera([{ id: 3, position: 'interior' }]).id === 3);

// ─── 3. Firma anti-recarga del MJPEG ──────────────────────────────────
const SIG = cameraSignature(CAMS[1]);
check('cameraSignature es estable', SIG === cameraSignature({ ...CAMS[1] }));
check('cameraSignature ignora recording/motion/edades',
  SIG === cameraSignature({ ...CAMS[1], recording: false, motion_active: true, last_motion_at: '2026-11-05 09:00:00' }));
check('cameraSignature cambia si se apaga',
  SIG !== cameraSignature({ ...CAMS[1], enabled: false }));
check('cameraSignature cambia si cambia la URL',
  SIG !== cameraSignature({ ...CAMS[1], mjpeg_url: '/almacen-live?id=99' }));

// ─── 4. Presencia: verde SOLO con PRESENT fresco ──────────────────────
const present = derivePresence({ presence_state: 'PRESENT', presence_stale: false });
check('PRESENT fresco → present', present.state === 'present');
check('PRESENT fresco → green', present.green === true);
check('PRESENT fresco → tono ok', present.tone === 'ok');
check('PRESENT fresco → etiqueta PRESENCIA', present.label === 'PRESENCIA');

const stale = derivePresence({ presence_state: 'PRESENT', presence_stale: true });
check('PRESENT stale → stale', stale.state === 'stale');
check('PRESENT stale → NO green', stale.green === false);
check('PRESENT stale → ámbar', stale.tone === 'warn');

const candidate = derivePresence({
  presence_state: 'ABSENT',
  presence_stale: false,
  presence_decision: { state: 'CANDIDATE_PRESENT' },
});
check('CANDIDATE_PRESENT → candidate', candidate.state === 'candidate');
check('CANDIDATE_PRESENT → NO green', candidate.green === false);
check('CANDIDATE_PRESENT → ámbar', candidate.tone === 'warn');

check('ABSENT → absent', derivePresence({ presence_state: 'ABSENT' }).state === 'absent');
check('UNKNOWN → unknown', derivePresence({ presence_state: 'UNKNOWN' }).state === 'unknown');
check('sin live → unknown', derivePresence(null).state === 'unknown');
check('occupied NO se usa para el verde',
  derivePresence({ presence_state: 'UNKNOWN', occupied: true }).green === false);

// ─── 5. Puerta fiel ───────────────────────────────────────────────────
check('OPEN fresco → open', deriveDoor({ door_state: 'OPEN' }, BASE).state === 'open');
check('OPEN fresco → etiqueta ABIERTA', deriveDoor({ door_state: 'OPEN' }, BASE).label === 'ABIERTA');
check('OPEN stale → unknown', deriveDoor({ door_state: 'OPEN', door_stale: true }, BASE).state === 'unknown');
check('OPEN stale → SIN DATOS', deriveDoor({ door_state: 'OPEN', door_stale: true }, BASE).label === 'SIN DATOS');
check('CLOSED stale sigue siendo closed',
  deriveDoor({ door_state: 'CLOSED', door_stale: true }, BASE).state === 'closed');
check('UNKNOWN → unknown', deriveDoor({ door_state: 'UNKNOWN' }, BASE).state === 'unknown');
check('sin live → unknown', deriveDoor(null, BASE).state === 'unknown');

const pulseLive = { door_state: 'CLOSED', last_open_at: '2026-11-05T10:00:00.000Z' };
check('pulso anti-colapso: 500 ms → open',
  deriveDoor(pulseLive, BASE + 500).state === 'open');
check('pulso anti-colapso: 1500 ms → closed',
  deriveDoor(pulseLive, BASE + DOOR_PULSE_MS + 100).state === 'closed');

// ─── 6. Cooldown del pulsador ─────────────────────────────────────────
check('canOpen sin historial → true', canOpen(BASE, 0, false, OPEN_COOLDOWN_MS) === true);
check('canOpen durante cooldown → false', canOpen(BASE + 1000, BASE, false, OPEN_COOLDOWN_MS) === false);
check('canOpen tras cooldown → true', canOpen(BASE + OPEN_COOLDOWN_MS, BASE, false, OPEN_COOLDOWN_MS) === true);
check('canOpen con petición en vuelo → false', canOpen(BASE + 9000, 0, true, OPEN_COOLDOWN_MS) === false);

// ─── 7. Resultado de la apertura ──────────────────────────────────────
check('HTTP 200 + result ok → ok', doorOutcome(200, { result: 'ok' }).ok === true);
check('HTTP 200 sin result → ok (compat)',
  doorOutcome(200, {}).ok === true);
check('HTTP 200 + result error → error',
  doorOutcome(200, { result: 'error' }).ok === false);
check('HTTP 409 usa message', doorOutcome(409, { message: 'cooldown' }).message === 'cooldown');
check('HTTP 500 → error', doorOutcome(500, {}).ok === false);
check('visit_started se conserva', doorOutcome(200, { result: 'ok', visit_started: true }).visitStarted === true);

// ─── 8. Sala solicitada vs snapshot ───────────────────────────────────
check('roomMismatch con la misma sala → false', roomMismatch(14, { room: { id: 14 } }) === false);
check('roomMismatch con otra sala → true', roomMismatch(14, { room: { id: 12 } }) === true);
check('roomMismatch sin room → true', roomMismatch(14, { room: null }) === true);
check('roomMismatch sin id solicitado → false', roomMismatch(0, { room: { id: 14 } }) === false);

// ─── 9. Utilidades ────────────────────────────────────────────────────
check('formatAge segundos', formatAge(30) === 'hace 30 s');
check('formatAge minutos', formatAge(120) === 'hace 2 min');
check('formatAge inválido', formatAge(-1) === '');
check('DOOR_PULSE_MS = 1200', DOOR_PULSE_MS === 1200);
check('OPEN_COOLDOWN_MS = 3000', OPEN_COOLDOWN_MS === 3000);

console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' timbre-logic: ' + passed + ' passed, ' + failed + ' failed\n');
process.exit(failed > 0 ? 1 : 0);
