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
  openingTypeLabel,
  directionLabel,
  openingStatusLabel,
  openingStatusTone,
  openingTime,
  firstVideo,
  hasRecording,
  qrDownloadName,
  buildKeyPayload,
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
  { id: 15, code: 'oficina', room_type_code: 'TIMBRADO' },
  { id: 16, code: 'oficina2', room_type_code: 'TIMBRADO' },
];
const WH = filterWarehouses(ROOMS);
check('solo salas TIMBRADO', WH.length === 2 && WH[0].id === 15 && WH[1].id === 16);
check('pickDefaultRoom(?room=15) respeta la petición', pickDefaultRoom(WH, 15).id === 15);
check('pickDefaultRoom sin petición → oficina', pickDefaultRoom(WH, 0, 'oficina').id === 15);
check('pickDefaultRoom con petición inválida → preferida', pickDefaultRoom(WH, 999, 'oficina').id === 15);
check('pickDefaultRoom sin preferida → primera', pickDefaultRoom(WH, 0, 'no-existe').id === 15);
check('pickDefaultRoom sin oficinas → null', pickDefaultRoom([], 0, 'oficina') === null);

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

// ─── 10. Aperturas y llaves QR (F117) ─────────────────────────────────
check('tipo sinllave', openingTypeLabel('sinllave') === 'Sin llave');
check('tipo qr', openingTypeLabel('qr') === 'QR');
check('tipo ficha', openingTypeLabel('ficha') === 'Ficha');
check('dirección entrada', directionLabel('entry') === 'Entrada');
check('dirección salida', directionLabel('exit') === 'Salida');
check('dirección cruce', directionLabel('crossing') === 'Cruce');
check('estado sin apertura', openingStatusLabel('no_door') === 'Sin apertura');
check('tono completada', openingStatusTone('completed') === 'ok');
check('tono puerta abierta', openingStatusTone('left_open') === 'warn');
check('hora de apertura válida', openingTime('2026-11-05 10:00:00') !== '—');
const RECORDINGS = [
  { id: 2, status: 'RECORDING', video_url: null },
  { id: 1, status: 'SAVED', video_url: '/v/1', poster_url: '/p/1' },
];
check('primer vídeo guardado', firstVideo(RECORDINGS).id === 1);
check('hay grabación en curso', hasRecording(RECORDINGS) === true);
check('sin grabación', hasRecording([{ status: 'SAVED' }]) === false);
check('PENDING parado no se anuncia como grabando', hasRecording([{ status: 'PENDING', stop_requested: true }]) === false);
check('nombre de descarga QR', qrDownloadName('Llave de José') === 'llave-de-jose.png');

const validFuture = new Date(Date.now() + 86400000);
const pad = (n) => String(n).padStart(2, '0');
const localFuture = validFuture.getFullYear() + '-' + pad(validFuture.getMonth() + 1) + '-'
  + pad(validFuture.getDate()) + 'T' + pad(validFuture.getHours()) + ':' + pad(validFuture.getMinutes());
const free = buildKeyPayload({ roomId: 15, ownerType: 'FREE', ownerName: 'José', validUntilLocal: localFuture });
check('payload libre válido', free.ok === true && free.payload.owner_type === 'FREE');
check('payload libre usa nombre como etiqueta', free.payload.label === 'José');
check('payload trabajador exige empleado',
  buildKeyPayload({ roomId: 15, ownerType: 'WORKER', validUntilLocal: localFuture }).ok === false);
check('payload rol exige rol',
  buildKeyPayload({ roomId: 15, ownerType: 'ROLE', validUntilLocal: localFuture }).ok === false);
check('payload rechaza caducidad pasada',
  buildKeyPayload({ roomId: 15, ownerType: 'FREE', ownerName: 'X', validUntilLocal: '2020-01-01T00:00' }).ok === false);

console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' timbre-logic: ' + passed + ' passed, ' + failed + ' failed\n');
process.exit(failed > 0 ? 1 : 0);
