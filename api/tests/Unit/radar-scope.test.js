#!/usr/bin/env node
'use strict';

/**
 * Unit tests for the Radar Studio pure drawing logic (F125, RF-350).
 *
 * Pure logic only: no DOM, no network, no DB, no Tuya.
 *
 * Run: node api/tests/Unit/radar-scope.test.js
 */

const path = require('path');
const RadarScope = require(path.join(__dirname, '..', '..', 'public', 'assets', 'radar-scope.js'));

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

// ─── Rampa de sensibilidad ───────────────────────────────────────────────
check('sens 0 → azul', RadarScope.sensitivityColor(0) === '#448aff');
check('sens 3 → cian', RadarScope.sensitivityColor(3) === '#18ffff');
check('sens 5 → verde', RadarScope.sensitivityColor(5) === '#00e676');
check('sens 7 → ámbar', RadarScope.sensitivityColor(7) === '#ffd740');
check('sens 9 → rojo', RadarScope.sensitivityColor(9) === '#ff5252');
check('sens fuera de rango se acota', RadarScope.sensitivityColor(99) === '#ff5252');
check('etiqueta baja', RadarScope.sensitivityLabel(1) === 'Baja');
check('etiqueta máxima', RadarScope.sensitivityLabel(9) === 'Máxima');

// ─── Formato y escala ────────────────────────────────────────────────────
check('formatM', RadarScope.formatM(1.5) === '1.50 m');
check('formatM inválido', RadarScope.formatM(null) === '—');
check('scaleFor usa axes', RadarScope.scaleFor({ axes: { max_m: 3.5 } }) === 3.5);
check('scaleFor acota mínimo', RadarScope.scaleFor({ axes: { max_m: 0.2 } }) === 1.5);
check('scaleFor acota máximo', RadarScope.scaleFor({ axes: { max_m: 99 } }) === 12);
check('pxPerM', Math.abs(RadarScope.pxPerM({ axes: { max_m: 2 } }) - 84) < 0.001);

// ─── Presencia ───────────────────────────────────────────────────────────
const present = RadarScope.presenceVisual({ raw: { state: 'PRESENT' }, stats: { motion_recent: true } });
check('PRESENT → verde', present.state === 'PRESENT' && present.tone === 'ok');
const stale = RadarScope.presenceVisual({ raw: { state: 'PRESENT', stale: true }, stats: {} });
check('stale → sin datos', stale.state === 'STALE' && stale.tone === 'bad');
const motion = RadarScope.presenceVisual({ raw: { state: 'ABSENT' }, stats: { motion_recent: true } });
check('movimiento sin presencia → ámbar', motion.state === 'MOTION' && motion.tone === 'warn');
const absent = RadarScope.presenceVisual({ raw: { state: 'ABSENT' }, stats: { motion_recent: false } });
check('AUSENTE → dim', absent.state === 'ABSENT' && absent.tone === 'dim');

// ─── Banner de detección ─────────────────────────────────────────────────
const dmPresent = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stats: { motion_recent: true },
  series: [{ distance_m: 1.2 }],
});
check('banner PRESENT → verde', dmPresent.tone === 'ok' && dmPresent.title === 'TE DETECTA' && dmPresent.icon === '✓');
check('banner PRESENT incluye distancia', dmPresent.subtitle.indexOf('1.20 m') >= 0);
check('banner PRESENT incluye edad', dmPresent.ageSeconds === 1);

const dmPresentExplicit = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stats: { motion_recent: true, last_distance: 0.57, last_distance_age_s: 2 },
  series: [],
});
check('F125.1.6: PRESENT usa last_distance sin serie', dmPresentExplicit.subtitle.indexOf('0.57 m') >= 0);
check('F125.1.6: PRESENT explícito no dice no disponible', dmPresentExplicit.subtitle.indexOf('no disponible') < 0);
check('F125.1.6: lastDistance devuelto', dmPresentExplicit.lastDistance === 0.57);

const dmPresentNoDistance = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stats: { motion_recent: false },
  series: [],
});
check('F125.1.6: PRESENT sin distancia → no disponible', dmPresentNoDistance.subtitle.indexOf('distancia no disponible') >= 0);
check('F125.1.6: PRESENT sin distancia → lastDistance null', dmPresentNoDistance.lastDistance === null);

const dmPresentZero = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  last_distance: 0,
  last_distance_age_s: 1,
  series: [],
});
check('F125.1.6: distancia cero se ignora', dmPresentZero.subtitle.indexOf('distancia no disponible') >= 0);

const dmPresentOld = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stale_seconds: 30,
  last_distance: 1.2,
  last_distance_age_s: 45,
  series: [],
});
check('F125.1.6: distancia vieja se descarta', dmPresentOld.subtitle.indexOf('distancia no disponible') >= 0);

const dmPresentOldSeries = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stale_seconds: 30,
  series: [{ distance_m: 1.2, age_s: 45 }],
});
check('F125.1.6: serie vieja se descarta', dmPresentOldSeries.subtitle.indexOf('distancia no disponible') >= 0);

const dmMotionDistance = RadarScope.detectionModel({
  raw: { state: 'ABSENT' },
  stats: { motion_recent: true, last_distance: 1.4, last_distance_age_s: 3 },
});
check('F125.1.6: MOTION incluye distancia', dmMotionDistance.subtitle.indexOf('1.40 m') >= 0);

const dmAbsent = RadarScope.detectionModel({
  raw: { state: 'ABSENT', age_seconds: 2 },
  stats: { motion_recent: false },
});
check('banner ABSENT → rojo', dmAbsent.tone === 'bad' && dmAbsent.title === 'NO TE DETECTA' && dmAbsent.icon === '✕');

const dmMotion = RadarScope.detectionModel({
  raw: { state: 'ABSENT' },
  stats: { motion_recent: true },
});
check('banner MOTION → ámbar', dmMotion.tone === 'warn' && dmMotion.title === 'MOVIMIENTO SIN PRESENCIA' && dmMotion.icon === '≈');

const dmStale = RadarScope.detectionModel({ raw: { state: 'PRESENT', stale: true }, stats: {} });
check('banner STALE → gris', dmStale.tone === 'dim' && dmStale.title === 'SIN DATOS' && dmStale.icon === '…');

// F134: un pipeline detenido explica la falta de datos; no es un sensor caído.
const dmPipelineDown = RadarScope.detectionModel({
  raw: { state: 'ABSENT', stale: true, age_seconds: 9000 },
  stats: {},
  pipeline: { enabled: true, healthy: false, state: 'stopped', reason: 'service_inactive' },
});
check('F134: ingesta parada → aviso ámbar',
  dmPipelineDown.state === 'PIPELINE_DOWN' && dmPipelineDown.tone === 'warn'
  && dmPipelineDown.title === 'INGESTA ZIGBEE DETENIDA');
check('F134: aviso de ingesta explica el sensor conectado',
  dmPipelineDown.subtitle.indexOf('puede estar conectado') >= 0);
const dmPipelineDownOldGate = RadarScope.detectionModel({
  raw: { state: 'ABSENT', stale: true, age_seconds: 9000 },
  stats: {},
  presence_decision: { range_gate: { status: 'unverified', reason: 'no_dp9' }, age_seconds: 9000 },
  range_evaluation: { status: 'unverified', reason: 'no_dp9' },
  pipeline: { enabled: true, healthy: false, state: 'stopped' },
});
check('F134: ingesta parada prevalece sobre una decisión antigua',
  dmPipelineDownOldGate.state === 'PIPELINE_DOWN'
  && dmPipelineDownOldGate.title === 'INGESTA ZIGBEE DETENIDA');
const dmStaleDecisionFreshPresence = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 2 },
  stats: { motion_recent: true, last_distance: 2.76, last_distance_age_s: 2 },
  server_ts: '2026-11-13T10:55:40.000Z',
  presence_decision: {
    event_at: '2026-11-13 10:53:03.991',
    range_gate: { status: 'unverified', reason: 'zero_dp9' },
  },
  range_evaluation: { status: 'unverified', reason: 'zero_dp9' },
});
check('F134 decisión antigua no oculta detección fresca',
  dmStaleDecisionFreshPresence.title === 'TE DETECTA'
  && dmStaleDecisionFreshPresence.tone === 'ok'
  && dmStaleDecisionFreshPresence.subtitle.indexOf('2.76 m') >= 0);

const dmStaleDecisionAbsent = RadarScope.detectionModel({
  raw: { state: 'ABSENT', age_seconds: 1 },
  stats: { motion_recent: false, last_distance: 2.48, last_distance_age_s: 5 },
  server_ts: '2026-11-13T10:59:00.000Z',
  presence_decision: {
    event_at: '2026-11-13 10:53:03.991',
    range_gate: { status: 'unverified', reason: 'zero_dp9' },
  },
  range_evaluation: { status: 'unverified', reason: 'zero_dp9' },
});
check('F134 decisión antigua no convierte ABSENT en presencia',
  dmStaleDecisionAbsent.state === 'ABSENT'
  && dmStaleDecisionAbsent.title === 'NO TE DETECTA');

const dmPipelineOk = RadarScope.detectionModel({
  raw: { state: 'PRESENT', stale: true, age_seconds: 9000 },
  stats: {},
  pipeline: { enabled: true, healthy: true, state: 'ok' },
});
check('F134: pipeline sano mantiene SIN DATOS del sensor',
  dmPipelineOk.state === 'STALE' && dmPipelineOk.title === 'SIN DATOS');
const dmPipelineDisabled = RadarScope.detectionModel({
  raw: { state: 'PRESENT', stale: true, age_seconds: 9000 },
  stats: {},
  pipeline: { enabled: false, healthy: false, state: 'disabled' },
});
check('F134: pipeline deshabilitado no genera aviso',
  dmPipelineDisabled.state === 'STALE' && dmPipelineDisabled.title === 'SIN DATOS');

const dmNull = RadarScope.detectionModel(null);
check('banner sin scope → SIN DATOS', dmNull.tone === 'dim' && dmNull.title === 'SIN DATOS');

// ─── Histograma radial ───────────────────────────────────────────────────
const hist = RadarScope.histogram([
  { distance_m: 1.0 }, { distance_m: 1.0 }, { distance_m: 2.0 },
], 0.25);
check('histograma agrupa 1.0 m', hist.bins[1] === 2);
check('histograma agrupa 2.0 m', hist.bins[2] === 1);
check('histograma máximo', hist.max === 2);

// ─── SVG ─────────────────────────────────────────────────────────────────
const baseScope = {
  settings: { radar_sensitivity: 7, maximum_range: 3.0, minimum_range: 0.6 },
  axes: { max_m: 3.0 },
  raw: { state: 'ABSENT', stale: false },
  stats: { motion_recent: false, p90: 2.0 },
  series: [{ distance_m: 1.2 }, { distance_m: 1.5 }],
  boundary: { found: true, distance_m: 1.5, samples: 3, suggested_max: 1.2 },
  diagnosis: [],
  status: 'ok',
};
const svg = RadarScope.buildSvg(baseScope);
check('SVG contiene svg', svg.indexOf('<svg') === 0);
check('SVG contiene alcance', svg.indexOf('Alcance 3.00 m') >= 0);
check('SVG contiene sensor', svg.indexOf('RADAR') >= 0);
check('SVG contiene borde', svg.indexOf('borde 1.50 m') >= 0);
check('SVG contiene leyenda honesta', svg.indexOf('no informa ángulo') >= 0);
check('SVG sin presencia no pinta glow', svg.indexOf('scope-presence') < 0);
check('SVG ABSENT pinta anillo rojo tenue', svg.indexOf('#ff5252') >= 0 && svg.indexOf('stroke-opacity="0.35"') >= 0);

const svgStale = RadarScope.buildSvg(Object.assign({}, baseScope, {
  raw: { state: 'UNKNOWN', stale: true },
}));
check('SVG STALE pinta anillo gris', svgStale.indexOf('#8a8aa0') >= 0);

const svgPresent = RadarScope.buildSvg(Object.assign({}, baseScope, {
  raw: { state: 'PRESENT', stale: false },
  stats: { motion_recent: true, p90: 2.0 },
}));
check('SVG con PRESENT pinta glow', svgPresent.indexOf('scope-presence') >= 0);

const svgMotion = RadarScope.buildSvg(Object.assign({}, baseScope, {
  raw: { state: 'ABSENT', stale: false },
  stats: { motion_recent: true, p90: 2.0 },
}));
check('SVG con movimiento pinta pulso', svgMotion.indexOf('scope-motion') >= 0);

const svgEmpty = RadarScope.buildSvg(null);
check('SVG sin datos devuelve vacío', svgEmpty === '');

// ─── F130: alcance operativo ─────────────────────────────────────────────
const outOfRange = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stats: { motion_recent: true },
  settings: { maximum_range: 1.95 },
  last_distance: 2.64,
  last_distance_age_s: 1,
  presence_decision: { apply: true, state: 'ABSENT_CONFIRMED', discard_reason: null },
  range_evaluation: {
    status: 'out_of_range',
    reason: 'distance_above_maximum_range',
    distance_m: 2.64,
    maximum_range_m: 1.95,
  },
});
check('F130 fuera de alcance → FILTERED', outOfRange.state === 'FILTERED' && outOfRange.tone === 'bad');
check('F130 fuera de alcance → título', outOfRange.title === 'FUERA DEL ALCANCE');
check('F130 fuera de alcance → eco y máximo', outOfRange.subtitle.indexOf('2.64 m') >= 0 && outOfRange.subtitle.indexOf('1.95 m') >= 0);
check('F130 fuera de alcance no dice confirmada', outOfRange.subtitle.indexOf('confirmada') < 0);

const unverified = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stats: { motion_recent: false },
  settings: { maximum_range: 1.95 },
  presence_decision: { apply: false, discard_reason: 'range_unverified' },
  range_evaluation: { status: 'unverified', reason: 'no_dp9', distance_m: null, maximum_range_m: 1.95 },
});
check('F133 sin DP9 con señal PRESENT → EL RADAR DETECTA',
  unverified.state === 'AWAITING_EVIDENCE' && unverified.tone === 'warn');
check('F133 sin DP9 explica que comprueba alcance',
  unverified.title === 'EL RADAR DETECTA' && unverified.subtitle.indexOf('Comprobando alcance') >= 0);
check('F133 sin DP9 no afirma presencia confirmada',
  unverified.subtitle.indexOf('confirmada') < 0);

const notApplied = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stats: { motion_recent: true },
  presence_decision: { apply: false, discard_reason: 'uncorroborated' },
});
check('F130 no aplicada → NOT_APPLIED', notApplied.state === 'NOT_APPLIED');
check('F130 no aplicada expone motivo legible', notApplied.subtitle.indexOf('corroboración') >= 0);
check('F132 no aplicada no dice que el radar te ve', notApplied.subtitle.indexOf('radar te ve') < 0);

const noop = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stats: { motion_recent: true },
  last_distance: 1.2,
  last_distance_age_s: 1,
  presence_decision: { apply: false, state: 'PRESENT_CONFIRMED', discard_reason: 'noop' },
});
check('F130 noop no se pinta como no aplicada', noop.title === 'TE DETECTA' && noop.tone === 'ok');

const inRange = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stats: { motion_recent: true },
  settings: { maximum_range: 1.95 },
  last_distance: 1.4,
  last_distance_age_s: 1,
  presence_decision: { apply: true, state: 'PRESENT_CONFIRMED', discard_reason: null },
  range_evaluation: { status: 'in_range', distance_m: 1.4, maximum_range_m: 1.95 },
});
check('F130 dentro de alcance → TE DETECTA', inRange.title === 'TE DETECTA' && inRange.tone === 'ok');

const svgOut = RadarScope.buildSvg({
  settings: { maximum_range: 1.95, radar_sensitivity: 7 },
  axes: { max_m: 3.0 },
  raw: { state: 'PRESENT', stale: false },
  stats: { motion_recent: true, p90: 2.64 },
  series: [{ distance_m: 2.64 }],
  presence_decision: { apply: true, state: 'ABSENT_CONFIRMED' },
  range_evaluation: { status: 'out_of_range', distance_m: 2.64, maximum_range_m: 1.95 },
});
check('F130 SVG no pinta glow verde fuera de alcance', svgOut.indexOf('scope-presence') < 0);
check('F130 SVG marca el bloqueo', svgOut.indexOf('scope-range-blocked') >= 0);
check('F130 SVG etiqueta eco fuera', svgOut.indexOf('fuera') >= 0);

// ─── F132: resultado operativo final ─────────────────────────────────────
const pendingAbsent = RadarScope.detectionModel({
  raw: { state: 'ABSENT', age_seconds: 1 },
  stats: { motion_recent: false },
  presence_decision: {
    outcome: 'pending_absent', outcome_reason: 'candidate_absent',
    effective_presence: 'PRESENT', pending: { state: 'ABSENT', due_at: '2026-11-13 10:00:04.000' },
  },
});
check('F132 candidate_absent → COMPROBANDO AUSENCIA',
  pendingAbsent.state === 'CHECKING_ABSENCE' && pendingAbsent.title === 'COMPROBANDO AUSENCIA');
check('F132 candidate_absent no dice «el radar te ve»', pendingAbsent.subtitle.indexOf('radar te ve') < 0);
check('F132 candidate_absent mantiene la presencia anterior',
  pendingAbsent.subtitle.indexOf('mantiene la presencia') >= 0);

const pendingPresent = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stats: { motion_recent: true },
  presence_decision: { outcome: 'pending_present', outcome_reason: 'entry_pending', effective_presence: 'ABSENT' },
});
check('F132 entrada pendiente → CONFIRMANDO PRESENCIA',
  pendingPresent.state === 'CONFIRMING' && pendingPresent.title === 'CONFIRMANDO PRESENCIA');

const blocked = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stats: { motion_recent: true },
  presence_decision: { outcome: 'blocked', outcome_reason: 'no_context', effective_presence: 'ABSENT' },
});
check('F132 bloqueo por política → DETECCIÓN NO APLICADA',
  blocked.state === 'NOT_APPLIED' && blocked.title === 'DETECCIÓN NO APLICADA');
check('F132 bloqueo explica la política', blocked.subtitle.indexOf('política') >= 0);

const dp1Only = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stats: { motion_recent: true },
  presence_decision: {
    outcome: 'applied', effective_presence: 'PRESENT', value: 'PRESENT',
    evidence_source: 'dp1_only',
  },
});
check('F133.1 respaldo DP1 → TE DETECTA sin distancia',
  dp1Only.title === 'TE DETECTA' && dp1Only.subtitle.indexOf('DP1') >= 0
  && dp1Only.subtitle.indexOf('distancia no disponible') >= 0);

const appliedAbsent = RadarScope.detectionModel({
  raw: { state: 'ABSENT', age_seconds: 1 },
  stats: { motion_recent: false },
  presence_decision: { outcome: 'applied', effective_presence: 'ABSENT', value: 'ABSENT' },
});
check('F132 ausencia aplicada → NO TE DETECTA',
  appliedAbsent.state === 'ABSENT' && appliedAbsent.title === 'NO TE DETECTA');

const staleWins = RadarScope.detectionModel({
  raw: { state: 'UNKNOWN', age_seconds: 120, stale: true },
  stats: { motion_recent: false },
  stale_seconds: 30,
  presence_decision: { outcome: 'applied', effective_presence: 'PRESENT' },
});
check('F132 SIN DATOS prevalece sobre una decisión antigua',
  staleWins.state === 'STALE' && staleWins.title === 'SIN DATOS');

check('F132 outcomeOf reconoce el resultado final',
  RadarScope.outcomeOf({ outcome: 'pending_absent' }) === 'pending_absent'
  && RadarScope.outcomeOf({ apply: true }) === 'applied'
  && RadarScope.outcomeOf({ apply: false, discard_reason: 'candidate_absent' }) === 'pending_absent');

// ─── Resumen ─────────────────────────────────────────────────────────────
console.log('\nTotal: ' + passed + ' passed, ' + failed + ' failed');
process.exit(failed === 0 ? 0 : 1);
