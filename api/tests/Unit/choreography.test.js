/**
 * choreography.test.js — Unit tests de la función pura `deriveChoreography()`.
 *
 * Trazabilidad: TSK-F41-13 · design.md §5 (T1–T18) · RF-46, RF-47.
 *
 * Script Node plano (mismo patrón que los `*Test.php` del runner):
 * imprime PASS/FAIL y sale con código 0 solo si no hay fallos.
 *
 * Run:
 *   node api/tests/Unit/choreography.test.js
 */
'use strict';

const path = require('path');
const Ch = require(path.join(__dirname, '..', '..', 'public', 'assets', 'choreography.js'));
const deriveChoreography = Ch.deriveChoreography;
const emptyEpisodes = Ch.emptyEpisodes;

// ============================================================================
// Harness mínimo
// ============================================================================
let PASS = 0, FAIL = 0;
function ok(label) { PASS++; console.log('  PASS  ' + label); }
function bad(label, detail) { FAIL++; console.log('  FAIL  ' + label + (detail ? ' — ' + detail : '')); }
function check(label, cond, detail) { cond ? ok(label) : bad(label, detail); }
function eq(label, got, want) { check(label, got === want, 'expected ' + want + ', got ' + got); }

// ============================================================================
// Fixtures
// ============================================================================
const T0 = Date.parse('2026-09-16T10:00:00Z');
const iso = (ms) => new Date(ms).toISOString();

function baseSnap(over) {
  const s = {
    roomStatus: 'OCCUPIED',
    door: 'CLOSED',
    presence: 'UNKNOWN',
    stayId: 305,
    stayStatus: 'OCCUPIED',
    entryConfirmedAt: null,
    lastOpenAt: null,
    lastCloseAt: null,
    lastAbsentSince: null,
    exitDeadline: null,
    gapSeconds: 15,
    cooldown: false,
    qrScannable: false,
    qrConsumed: false,
    qrRevoked: false,
    qrExpired: false,
    qrRecentAt: 0,
    qrRecentResult: null,
    qrBroadAt: 0,
    qrDeniedAt: 0,
    sensorsEverSeen: true,
    anomalies: [],
    anyDeviceReachable: true,
    prevDoor: null,
    prevPresence: null,
    prevStayStatus: null,
    freshPresenceAfterClose: false
  };
  return Object.assign(s, over || {});
}

let ep;

// ============================================================================
// Entrada por episodios (RF-46)
// ============================================================================
console.log('\nF41 · Coreografía de entrada (RF-46)\n');

// T3: QR recién validado, puerta aún UNKNOWN → QR_OK (AT_QR), NO interior.
let r = deriveChoreography(baseSnap({
  door: 'UNKNOWN', presence: 'UNKNOWN',
  qrRecentAt: T0, qrRecentResult: 'OK'
}), emptyEpisodes(305), T0);
eq('T3 QR validado → QR_OK', r.state, 'QR_OK');
eq('T3 lógico', r.logical, 'QR_OK');
ep = r.episodeUpdates;
check('T3 arranca entryEpisode y limpia entryPresenceSeen',
  ep.entryActive === true && ep.entryPresenceSeen === false);

// T4/T7: puerta abierta → umbral (CROSSING); presencia vista mientras abre.
r = deriveChoreography(baseSnap({
  door: 'OPEN', presence: 'PRESENT',
  lastOpenAt: iso(T0 + 5000), prevDoor: 'UNKNOWN'
}), ep, T0 + 5000);
eq('T4 puerta abierta → HUESPED_EN_PUERTA (umbral)', r.state, 'HUESPED_EN_PUERTA');
eq('T4 lógico EN_UMBRAL', r.logical, 'EN_UMBRAL');
ep = r.episodeUpdates;
check('T7 entryPresenceSeen=true con PRESENT y puerta abierta', ep.entryPresenceSeen === true);

// Tránsito por el umbral: sigue en umbral, NO dispara salida.
r = deriveChoreography(baseSnap({
  door: 'OPEN', presence: 'PRESENT',
  lastOpenAt: iso(T0 + 5000), prevDoor: 'OPEN'
}), ep, T0 + 6000);
eq('Tránsito por umbral no dispara salida', r.state, 'HUESPED_EN_PUERTA');
check('Tránsito no marca exitActive', r.episodeUpdates.exitActive === false);

// T8: cierre con presencia vista al abrir → DENTRO (OCUPADA) y limpia la bandera.
r = deriveChoreography(baseSnap({
  door: 'CLOSED', presence: 'PRESENT',
  lastCloseAt: iso(T0 + 8000), prevDoor: 'OPEN'
}), ep, T0 + 8000);
eq('T8 cierre con presencia → OCUPADA', r.state, 'OCUPADA');
eq('T8 lógico DENTRO', r.logical, 'DENTRO');
ep = r.episodeUpdates;
check('T8 consolidación limpia entryPresenceSeen (sin bandera pegajosa)',
  ep.entryPresenceSeen === false && ep.entryConsolidatedAt > 0);

// No se puede saltar de acceso concedido a interior sin transitar el umbral.
console.log('\nF41 · No saltar QR_OK → DENTRO (RF-46.2.3)\n');
r = deriveChoreography(baseSnap({
  door: 'CLOSED', presence: 'PRESENT',
  qrRecentAt: T0, qrRecentResult: 'OK', prevDoor: null
}), emptyEpisodes(305), T0);
eq('QR_OK + puerta cerrada + presencia → QR_OK (no OCUPADA)', r.state, 'QR_OK');
ep = r.episodeUpdates;
// Con la ventana QR amplia (sin evento fresco) y sin OPEN observado, espera apertura.
r = deriveChoreography(baseSnap({
  door: 'CLOSED', presence: 'PRESENT',
  qrBroadAt: T0, qrRecentAt: 0, prevDoor: null
}), ep, T0 + 20000);
eq('Sin OPEN observado → QR_ESPERANDO (no OCUPADA)', r.state, 'QR_ESPERANDO');
check('Nunca salta a OCUPADA sin umbral', r.state !== 'OCUPADA');

// ============================================================================
// Salida por episodios (RF-47)
// ============================================================================
console.log('\nF41 · Verificación y confirmación de salida (RF-47)\n');

// Huésped confirmado dentro (autoridad backend).
r = deriveChoreography(baseSnap({
  door: 'CLOSED', presence: 'PRESENT',
  entryConfirmedAt: iso(T0), lastCloseAt: iso(T0), prevDoor: 'CLOSED'
}), emptyEpisodes(305), T0 + 1000);
eq('Huésped confirmado dentro → OCUPADA', r.state, 'OCUPADA');
ep = r.episodeUpdates;

// T9: nueva apertura posterior a la confirmación → POSIBLE_SALIDA.
r = deriveChoreography(baseSnap({
  door: 'OPEN', presence: 'ABSENT',
  entryConfirmedAt: iso(T0), lastOpenAt: iso(T0 + 60000),
  prevDoor: 'CLOSED'
}), ep, T0 + 60000);
eq('T9 apertura tras entrada confirmada → PUERTA_ABIERTA', r.state, 'PUERTA_ABIERTA');
eq('T9 lógico POSIBLE_SALIDA', r.logical, 'POSIBLE_SALIDA');
ep = r.episodeUpdates;
check('T9 arranca exitEpisode', ep.exitActive === true && ep.exitDoorOpenedAt > 0);

// T11/T14: cierre + ausencia + deadline → VERIFICANDO (conteo activo).
r = deriveChoreography(baseSnap({
  door: 'CLOSED', presence: 'ABSENT',
  entryConfirmedAt: iso(T0),
  lastOpenAt: iso(T0 + 60000), lastCloseAt: iso(T0 + 63000),
  lastAbsentSince: iso(T0 + 64000),
  exitDeadline: iso(T0 + 79000),
  prevDoor: 'OPEN'
}), ep, T0 + 64000);
eq('T11 cierre + ausencia → VERIFICANDO_PRESENCIA', r.state, 'VERIFICANDO_PRESENCIA');
eq('T14 lógico VERIFICANDO', r.logical, 'VERIFICANDO');
ep = r.episodeUpdates;

// T13: reaparición de presencia antes del deadline → cancela y vuelve a DENTRO.
r = deriveChoreography(baseSnap({
  door: 'CLOSED', presence: 'PRESENT',
  entryConfirmedAt: iso(T0),
  lastOpenAt: iso(T0 + 60000), lastCloseAt: iso(T0 + 63000),
  exitDeadline: iso(T0 + 79000),
  prevDoor: 'CLOSED',
  freshPresenceAfterClose: true
}), ep, T0 + 68000);
eq('T13 reaparición cancela salida → OCUPADA', r.state, 'OCUPADA');
ep = r.episodeUpdates;
check('T13 la cancelación no deja residuos de exitEpisode',
  ep.exitActive === false && ep.exitDeadline === 0 && ep.exitDoorOpenedAt === 0);

// RF-47.3.2: tras cancelar, una nueva salida debe poder iniciarse.
r = deriveChoreography(baseSnap({
  door: 'OPEN', presence: 'ABSENT',
  entryConfirmedAt: iso(T0), lastOpenAt: iso(T0 + 120000),
  prevDoor: 'CLOSED'
}), ep, T0 + 120000);
eq('Nueva verificación posible tras cancelar', r.state, 'PUERTA_ABIERTA');
ep = r.episodeUpdates;

// T15/T17: el dominio cierra la estancia → SALIDA_CONFIRMADA (fuera).
r = deriveChoreography(baseSnap({
  roomStatus: 'FREE', stayId: 305, stayStatus: 'EXITED',
  door: 'CLOSED', presence: 'ABSENT',
  prevStayStatus: 'OCCUPIED'
}), ep, T0 + 140000);
eq('T15/T17 estancia EXITED → HUESPED_HA_SALIDO', r.state, 'HUESPED_HA_SALIDO');
eq('T15 lógico SALIDA_CONFIRMADA', r.logical, 'SALIDA_CONFIRMADA');
check('Al confirmar salida se limpian los episodios',
  r.episodeUpdates.exitActive === false && r.episodeUpdates.entryConsolidatedAt === 0);

// ============================================================================
// T18: anomalías informativas
// ============================================================================
console.log('\nF41 · T18 anomalías no alteran la coreografía\n');
r = deriveChoreography(baseSnap({
  door: 'CLOSED', presence: 'PRESENT',
  anomalies: [{ anomaly_type: 'A1' }], qrBroadAt: 0
}), emptyEpisodes(305), T0);
eq('T18 A1 + PRESENT sin QR → ANOMALIA_PA (badge)', r.state, 'ANOMALIA_PA');

// ============================================================================
// F42 · RF-46.4: ventana de verificación de entrada
// ============================================================================
console.log('\nF42 · Ventana de verificación de entrada (RF-46.4)\n');

// Setup: QR validado + apertura acreditada; cerramos la puerta sin presencia.
const openAt  = iso(T0 + 5000);
const closeAt = iso(T0 + 8000);
ep = emptyEpisodes(305);
r = deriveChoreography(baseSnap({ door: 'UNKNOWN', qrRecentAt: T0, qrRecentResult: 'OK' }), ep, T0);
ep = r.episodeUpdates;
r = deriveChoreography(baseSnap({
  door: 'OPEN', lastOpenAt: openAt, prevDoor: 'UNKNOWN'
}), ep, T0 + 5000);
ep = r.episodeUpdates;
eq('V1 apertura previa → EN_UMBRAL', r.state, 'HUESPED_EN_PUERTA');

function entryClosedSnap(over) {
  return baseSnap(Object.assign({
    door: 'CLOSED', presence: 'ABSENT',
    lastOpenAt: openAt, lastCloseAt: closeAt, lastAbsentSince: closeAt,
    prevDoor: 'OPEN'
  }, over || {}));
}

// T-w1: cierre sin presencia dentro de la ventana → VERIFICANDO_ENTRADA (umbral, '?').
r = deriveChoreography(entryClosedSnap(), ep, T0 + 10000);
eq('T-w1 cierre sin presencia → VERIFICANDO_ENTRADA', r.state, 'VERIFICANDO_ENTRADA');
eq('T-w1 lógico', r.logical, 'VERIFICANDO_ENTRADA');
ep = r.episodeUpdates;
check('T-w1 no consolida entrada (timedOut=false)', ep.entryTimedOut === false && ep.entryConsolidatedAt === 0);

// T-w2: justo antes de agotar la ventana (gap=15) sigue esperando.
r = deriveChoreography(entryClosedSnap(), ep, T0 + 8000 + 14 * 1000);
eq('T-w2 a gap-1s → sigue VERIFICANDO_ENTRADA', r.state, 'VERIFICANDO_ENTRADA');
ep = r.episodeUpdates;

// T-w3: la ventana expira → se asume que no entró nadie; vuelve fuera.
r = deriveChoreography(entryClosedSnap(), ep, T0 + 8000 + 16 * 1000);
eq('T-w3 expira → vuelve fuera (QR_ESPERANDO)', r.state, 'QR_ESPERANDO');
ep = r.episodeUpdates;
check('T-w3 marca entryTimedOut', ep.entryTimedOut === true);

// T-w4: presencia después de expirar NO consolida (requiere nueva apertura).
r = deriveChoreography(entryClosedSnap({ presence: 'PRESENT', prevDoor: 'CLOSED' }), ep, T0 + 8000 + 30 * 1000);
eq('T-w4 presencia tras expirar → sigue fuera', r.state, 'QR_ESPERANDO');
ep = r.episodeUpdates;

// T-w5: una nueva apertura re-arma la ventana.
r = deriveChoreography(baseSnap({
  door: 'OPEN', presence: 'PRESENT', lastOpenAt: iso(T0 + 40000), prevDoor: 'CLOSED'
}), ep, T0 + 40000);
eq('T-w5 nueva apertura → EN_UMBRAL otra vez', r.state, 'HUESPED_EN_PUERTA');
ep = r.episodeUpdates;
check('T-w5 limpia entryTimedOut', ep.entryTimedOut === false);

// T-w6: cierre con presencia vista → consolida DENTRO.
r = deriveChoreography(entryClosedSnap({
  presence: 'PRESENT', lastOpenAt: iso(T0 + 40000), lastCloseAt: iso(T0 + 43000),
  prevDoor: 'OPEN'
}), ep, T0 + 43000);
eq('T-w6 cierre con presencia → OCUPADA', r.state, 'OCUPADA');

// T-w7: presencia DENTRO de la ventana → avanza al interior.
ep = emptyEpisodes(305);
r = deriveChoreography(baseSnap({ door: 'UNKNOWN', qrRecentAt: T0, qrRecentResult: 'OK' }), ep, T0);
ep = r.episodeUpdates;
r = deriveChoreography(baseSnap({ door: 'OPEN', lastOpenAt: openAt, prevDoor: 'UNKNOWN' }), ep, T0 + 5000);
ep = r.episodeUpdates;
r = deriveChoreography(entryClosedSnap(), ep, T0 + 10000);
ep = r.episodeUpdates;
r = deriveChoreography(entryClosedSnap({ presence: 'PRESENT', prevDoor: 'CLOSED' }), ep, T0 + 18000);
eq('T-w7 presencia dentro de ventana → OCUPADA', r.state, 'OCUPADA');
eq('T-w7 lógico DENTRO', r.logical, 'DENTRO');

// ============================================================================
// Resultado
// ============================================================================
console.log('\nTotal: ' + PASS + ' passed, ' + FAIL + ' failed\n');
process.exit(FAIL === 0 ? 0 : 1);
