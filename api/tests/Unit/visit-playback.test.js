#!/usr/bin/env node
'use strict';

/**
 * Unit tests for the warehouse visit playback pure logic (F68, RF-78).
 *
 * Trazabilidad: RF-78.2/78.3/78.6/78.8 · design.md §32 · tasks.md TSK-F68-03.
 *
 * Pure-logic tests only: no network, no DB, no DOM, no Tuya quota.
 *
 * Run: node api/tests/Unit/visit-playback.test.js
 */

const path = require('path');

const {
  buildVisitTimeline,
  frameAt,
  phaseAt,
  clipAt,
  formatClock,
  formatDuration,
  PRE_ROLL_MS,
} = require(path.join(__dirname, '..', '..', 'public', 'assets', 'visit-playback.js'));

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

// ─── Fixtures ──────────────────────────────────────────────────────────
function qrVisit() {
  return {
    id: 42,
    entry_trigger: 'QR',
    outcome: 'ENTERED',
    qr_at: '2026-10-01 09:13:48.000',
    entered_at: '2026-10-01 09:13:52.000',
    exited_at: '2026-10-01 09:18:20.000',
    created_at: '2026-10-01 09:13:48.000',
    recordings: [
      { id: 501, position: 'EXTERIOR', episode: 'ENTRY', trigger: 'QR', status: 'SAVED',
        requested_at: '2026-10-01 09:13:48.300', started_at: '2026-10-01 09:13:49.000',
        stopped_at: '2026-10-01 09:18:26.000', duration_s: 277,
        video_url: '/almacen-api/recordings/501/video', poster_url: '/p501.jpg' },
      { id: 502, position: 'INTERIOR', episode: 'ENTRY', trigger: 'QR', status: 'SAVED',
        requested_at: '2026-10-01 09:13:48.300', started_at: '2026-10-01 09:13:49.000',
        stopped_at: '2026-10-01 09:18:21.000', duration_s: 272,
        video_url: '/almacen-api/recordings/502/video', poster_url: '/p502.jpg' },
    ],
  };
}

function noShowVisit() {
  return {
    id: 43,
    entry_trigger: 'QR',
    outcome: 'NO_SHOW',
    qr_at: '2026-10-01 10:00:00.000',
    entered_at: null,
    exited_at: null,
    created_at: '2026-10-01 10:00:00.000',
    recordings: [
      { id: 601, position: 'EXTERIOR', episode: 'ENTRY', trigger: 'QR', status: 'SAVED',
        requested_at: '2026-10-01 10:00:00.300', started_at: '2026-10-01 10:00:00.500',
        stopped_at: '2026-10-01 10:00:12.000', duration_s: 12,
        video_url: '/almacen-api/recordings/601/video', poster_url: '/p601.jpg' },
      { id: 602, position: 'INTERIOR', episode: 'ENTRY', trigger: 'QR', status: 'DISCARDED',
        requested_at: '2026-10-01 10:00:00.300', started_at: '2026-10-01 10:00:00.500',
        stopped_at: '2026-10-01 10:00:02.000', duration_s: 2, video_url: null, poster_url: null },
    ],
  };
}

function anonymousVisit() {
  return {
    id: 44,
    entry_trigger: 'PRESENCE',
    outcome: 'ANONYMOUS',
    qr_at: null,
    entered_at: '2026-10-01 11:00:00.000',
    exited_at: '2026-10-01 11:05:00.000',
    created_at: '2026-10-01 11:00:00.000',
    recordings: [
      { id: 701, position: 'INTERIOR', episode: 'ENTRY', trigger: 'PRESENCE', status: 'SAVED',
        requested_at: '2026-10-01 11:00:00.000', started_at: '2026-10-01 11:00:00.200',
        stopped_at: '2026-10-01 11:05:00.000', duration_s: 300,
        video_url: '/almacen-api/recordings/701/video', poster_url: null },
    ],
  };
}

console.log('\n\u2500\u2500 visit-playback (RF-78) \u2500\u2500');

// ─── 1. Línea de tiempo: visita QR completa ────────────────────────────
const tl = buildVisitTimeline(qrVisit());
check('origin incluye pre-roll de acercamiento', tl.originMs === Date.parse('2026-10-01T09:13:45.500Z'));
check('pre-roll = PRE_ROLL_MS', PRE_ROLL_MS === 2500);
check('duración total = 280.5 s', tl.durationMs === 280500);
check('2 clips', tl.clips.length === 2);
check('hasRecordings=true', tl.hasRecordings === true);
check('3 marcadores (QR/Entrada/Salida)', tl.markers.length === 3);
check('marcador QR primero', tl.markers[0].type === 'QR');
check('marcador QR a 2.5 s', tl.markers[0].ms === 2500);
check('marcador Entrada a 6.5 s', tl.markers[1].type === 'ENTRY' && tl.markers[1].ms === 6500);
check('marcador Salida a 274.5 s', tl.markers[2].type === 'EXIT' && tl.markers[2].ms === 274500);
check('estancia dentro [6.5s, 274.5s]',
  tl.inside.startMs === 6500 && tl.inside.endMs === 274500);

// ─── 2. Fases del monigote ─────────────────────────────────────────────
check('t=1s → acercándose', phaseAt(tl, 1000) === 'approach');
check('t=3s → escaneando QR', phaseAt(tl, 3000) === 'scan');
check('t=5s → entrando', phaseAt(tl, 5000) === 'enter');
check('t=30s → dentro', phaseAt(tl, 30000) === 'inside');
check('t=273s → saliendo', phaseAt(tl, 273000) === 'exit');
check('t=279s → fuera', phaseAt(tl, 279000) === 'after');

// ─── 3. Fotograma (frameAt) ────────────────────────────────────────────
const origin = tl.originMs;
const insideFrame = frameAt(tl, origin + 30000, 0);
check('frame dentro: personInside=true', insideFrame.personInside === true);
check('frame dentro: doorOpen=false', insideFrame.doorOpen === false);
check('frame dentro: luz ON', insideFrame.lightOn === true && insideFrame.chips.light.text === 'LUZ ON');
check('frame dentro: reloj HH:MM:SS', /^\d{2}:\d{2}:\d{2}$/.test(insideFrame.wallClockLabel));
check('frame dentro: contador 23.5 s', insideFrame.insideSeconds === 23.5);
check('frame dentro: etiqueta dentro', insideFrame.insideLabel === '00:23');
check('frame dentro: fase Dentro', insideFrame.phaseLabel === 'Dentro');

const crossFrame = frameAt(tl, origin + 5000, 0);
check('frame cruce: puerta abierta', crossFrame.doorOpen === true && crossFrame.chips.door.text === 'PUERTA ABIERTA');
check('frame cruce: personPos crossing', crossFrame.personPos === 'crossing');

const scanFrame = frameAt(tl, origin + 3000, 0);
check('frame escaneo: personPos qr', scanFrame.personPos === 'qr');
check('frame escaneo: fuera (no dentro)', scanFrame.personInside === false);

const afterFrame = frameAt(tl, origin + 279000, 0);
check('frame después: vacío', afterFrame.personInside === false && afterFrame.chips.presence.text === 'VACÍO');
check('frame después: puerta cerrada', afterFrame.doorOpen === false);

// ─── 4. Clips activos y sincronía ──────────────────────────────────────
const bothActive = frameAt(tl, origin + 30000, 0).activeClips;
check('30 s: EXTERIOR activo', bothActive.EXTERIOR && bothActive.EXTERIOR.id === 501);
check('30 s: INTERIOR activo', bothActive.INTERIOR && bothActive.INTERIOR.id === 502);
check('30 s: localTimeMs relativo al clip', bothActive.EXTERIOR.localTimeMs === 27200);

const onlyExt = frameAt(tl, origin + 279000, 0).activeClips;
check('279 s: EXTERIOR aún activo', onlyExt.EXTERIOR && onlyExt.EXTERIOR.id === 501);
check('279 s: INTERIOR ya no', onlyExt.INTERIOR === null);
check('clipAt directo devuelve EXT', clipAt(tl.clips, 'EXTERIOR', 10000, true).id === 501);

// ─── 5. NO_SHOW (no entró) ─────────────────────────────────────────────
const tlNo = buildVisitTimeline(noShowVisit());
check('NO_SHOW: sin estancia (inside=null)', tlNo.inside === null);
check('NO_SHOW: hasRecordings=true (solo EXT)', tlNo.hasRecordings === true);
check('NO_SHOW: marcador Entrada ausente', tlNo.markers.every(m => m.type !== 'ENTRY'));
check('NO_SHOW: t=3s escaneando', phaseAt(tlNo, 3000) === 'scan');
check('NO_SHOW: t=10s fuera', phaseAt(tlNo, 10000) === 'after');
check('NO_SHOW: frame sin persona dentro', frameAt(tlNo, tlNo.originMs + 10000, 0).personInside === false);
check('NO_SHOW: INT descartado sin vídeo',
  frameAt(tlNo, tlNo.originMs + 3000, 0).activeClips.INTERIOR === null);

// ─── 6. Anónima (sin QR, disparo por presencia) ────────────────────────
const tlAnon = buildVisitTimeline(anonymousVisit());
check('anónima: sin marcador QR', tlAnon.markers.every(m => m.type !== 'QR'));
check('anónima: sin pre-roll (origin=creado)', tlAnon.originMs === Date.parse('2026-10-01T11:00:00Z'));
check('anónima: dentro desde t=0', phaseAt(tlAnon, 1000) === 'inside');
check('anónima: sale al final', phaseAt(tlAnon, 298000) === 'exit');
check('anónima: personInside a 1s', frameAt(tlAnon, tlAnon.originMs + 1000, 0).personInside === true);

// ─── 6b. F77.2: estancia larga con clip corto (tope de grabación) ───────
// El clip INTERIOR dura 60 s pero la estancia 55 min: el monigote debe
// permanecer DENTRO (no "Saliendo") hasta el final real.
const longVisit = {
  id: 46, entry_trigger: 'PRESENCE', outcome: 'ENTERED',
  qr_at: null,
  entered_at: '2026-10-01 13:00:00.000',
  exited_at: '2026-10-01 13:55:00.000',
  created_at: '2026-10-01 13:00:00.000',
  recordings: [
    { id: 801, position: 'INTERIOR', episode: 'ENTRY', trigger: 'PRESENCE', status: 'SAVED',
      requested_at: '2026-10-01 13:00:00.000', started_at: '2026-10-01 13:00:00.200',
      stopped_at: '2026-10-01 13:01:00.000', duration_s: 60,
      video_url: '/almacen-api/recordings/801/video', poster_url: null },
  ],
};
const tlLong = buildVisitTimeline(longVisit);
check('F77.2 estancia larga: dentro a los 10 min', phaseAt(tlLong, 600000) === 'inside');
check('F77.2 estancia larga: cruce solo al final', phaseAt(tlLong, 3299000) === 'exit');
check('F77.2 estancia larga: personInside a los 10 min',
  frameAt(tlLong, tlLong.originMs + 600000, 0).personInside === true);
check('F77.2 estancia larga: sin vídeo a los 10 min (clip de 60 s)',
  frameAt(tlLong, tlLong.originMs + 600000, 0).activeClips.INTERIOR === null);
check('F77.2 estancia larga: crossOut cerca del fin de estancia',
  tlLong.crossOutMs === tlLong.inside.endMs - 2500);

// ─── 6c. F84/RF-108: puerta fiel al sensor (sin inventar aperturas) ─────
// Visita por PRESENCIA sin ningún evento de puerta: la puerta NUNCA se abre.
const noDoorVisit = {
  id: 90, entry_trigger: 'PRESENCE', outcome: 'ENTERED',
  qr_at: null,
  entered_at: '2026-10-01 14:00:00.000', exited_at: '2026-10-01 14:00:20.000',
  created_at: '2026-10-01 14:00:00.000',
  recordings: [
    { id: 901, position: 'EXTERIOR', episode: 'ENTRY', trigger: 'PRESENCE', status: 'SAVED',
      requested_at: '2026-10-01 14:00:00.000', stopped_at: '2026-10-01 14:00:30.000', duration_s: 30,
      video_url: '/almacen-api/recordings/901/video', poster_url: null },
  ],
  door: { state_at_start: null, events: [] },
};
const tlNoDoor = buildVisitTimeline(noDoorVisit);
check('F84: doorKnown=true con eventos vacíos', tlNoDoor.doorKnown === true);
check('F84: sin eventos de puerta → fase exit sin puerta abierta',
  frameAt(tlNoDoor, tlNoDoor.originMs + 19000, 0).doorOpen === false
  && frameAt(tlNoDoor, tlNoDoor.originMs + 19000, 0).chips.door.text === 'PUERTA CERRADA');
check('F84: sin eventos de puerta → nunca abierta en toda la visita',
  [0, 5000, 10000, 15000, 18000, 22000].every(function (ms) {
    return frameAt(tlNoDoor, tlNoDoor.originMs + ms, 0).doorOpen === false;
  }));

// Visita DOOR con intervalos reales OPEN→CLOSED.
const realDoorVisit = {
  id: 91, entry_trigger: 'DOOR', outcome: 'ENTERED',
  qr_at: null,
  entered_at: '2026-10-01 15:00:02.000', exited_at: '2026-10-01 15:00:40.000',
  created_at: '2026-10-01 15:00:00.000',
  recordings: [
    { id: 911, position: 'INTERIOR', episode: 'ENTRY', trigger: 'DOOR', status: 'SAVED',
      requested_at: '2026-10-01 15:00:00.000', stopped_at: '2026-10-01 15:00:50.000', duration_s: 50,
      video_url: '/almacen-api/recordings/911/video', poster_url: null },
  ],
  door: {
    state_at_start: 'CLOSED',
    events: [
      { value: 'OPEN', occurred_at: '2026-10-01 15:00:00.000' },
      { value: 'CLOSED', occurred_at: '2026-10-01 15:00:20.000' },
    ],
  },
};
const tlRealDoor = buildVisitTimeline(realDoorVisit);
check('F84: DOOR abierta dentro del intervalo real',
  frameAt(tlRealDoor, tlRealDoor.originMs + 5000, 0).doorOpen === true);
check('F84: DOOR cerrada tras el CLOSED real',
  frameAt(tlRealDoor, tlRealDoor.originMs + 25000, 0).doorOpen === false);

// state_at_start=OPEN sin evento OPEN: abierta desde el inicio hasta el CLOSED.
const alreadyOpenVisit = {
  id: 92, entry_trigger: 'PRESENCE', outcome: 'ENTERED',
  qr_at: null,
  entered_at: '2026-10-01 16:00:00.000', exited_at: '2026-10-01 16:00:30.000',
  created_at: '2026-10-01 16:00:00.000',
  recordings: [],
  door: { state_at_start: 'OPEN', events: [
    { value: 'CLOSED', occurred_at: '2026-10-01 16:00:10.000' },
  ] },
};
const tlAlreadyOpen = buildVisitTimeline(alreadyOpenVisit);
check('F84: state_at_start=OPEN → abierta al inicio',
  frameAt(tlAlreadyOpen, tlAlreadyOpen.originMs + 2000, 0).doorOpen === true);
check('F84: state_at_start=OPEN → cerrada tras CLOSED',
  frameAt(tlAlreadyOpen, tlAlreadyOpen.originMs + 12000, 0).doorOpen === false);

// Compatibilidad: sin `door` se conserva el comportamiento por fase (fallback).
const legacyNoDoor = qrVisit();
delete legacyNoDoor.door;
const tlLegacy = buildVisitTimeline(legacyNoDoor);
check('F84: sin campo door → fallback sintético (puerta en fase enter)',
  tlLegacy.doorKnown === false && frameAt(tlLegacy, tlLegacy.originMs + 5000, 0).doorOpen === true);

// ─── 7. Sin grabaciones ────────────────────────────────────────────────
const tlEmpty = buildVisitTimeline({
  id: 45, entry_trigger: 'QR', outcome: 'ENTERED',
  qr_at: '2026-10-01 12:00:00.000', entered_at: '2026-10-01 12:00:04.000',
  exited_at: '2026-10-01 12:01:00.000', created_at: '2026-10-01 12:00:00.000', recordings: [],
});
check('sin grabaciones: hasRecordings=false', tlEmpty.hasRecordings === false);
check('sin grabaciones: hasCameras=false', tlEmpty.hasCameras === false);
const fEmpty = frameAt(tlEmpty, tlEmpty.originMs + 10000, 0);
check('sin grabaciones: clips activos null',
  fEmpty.activeClips.EXTERIOR === null && fEmpty.activeClips.INTERIOR === null);
check('sin grabaciones: fases siguen', fEmpty.personInside === true);

// ─── 8. Formateadores ──────────────────────────────────────────────────
check('formatClock UTC exacto',
  formatClock(Date.UTC(2026, 9, 1, 9, 13, 48), 0) === '09:13:48');
check('formatClock aplica huso (+120 min)',
  formatClock(Date.UTC(2026, 9, 1, 9, 13, 48), 120) === '11:13:48');
check('formatDuration mm:ss', formatDuration(65) === '01:05');
check('formatDuration h:mm:ss', formatDuration(3725) === '1:02:05');
check('formatDuration 0', formatDuration(0) === '00:00');

// ─── 9. Módulo require-safe ────────────────────────────────────────────
check('module exports pure timeline logic',
  typeof buildVisitTimeline === 'function' && typeof frameAt === 'function');

console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' visit-playback: ' + passed + ' passed, ' + failed + ' failed\n');
if (failed > 0) process.exit(1);
process.exit(0);
