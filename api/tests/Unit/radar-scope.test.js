#!/usr/bin/env node
'use strict';

/**
 * F125/F135 — Radar Studio: detección bruta separada de la política.
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
check('sens 5 → verde', RadarScope.sensitivityColor(5) === '#00e676');
check('sens 9 → rojo', RadarScope.sensitivityColor(9) === '#ff5252');
check('etiqueta máxima', RadarScope.sensitivityLabel(9) === 'Máxima');

// ─── Formato y escala ────────────────────────────────────────────────────
check('formatM', RadarScope.formatM(1.5) === '1.50 m');
check('formatM inválido', RadarScope.formatM(null) === '—');
check('scaleFor usa axes', RadarScope.scaleFor({ axes: { max_m: 3.5 } }) === 3.5);
check('scaleFor acota mínimo', RadarScope.scaleFor({ axes: { max_m: 0.2 } }) === 1.5);
check('pxPerM', Math.abs(RadarScope.pxPerM({ axes: { max_m: 2 } }) - 84) < 0.001);

// ─── Presencia bruta ─────────────────────────────────────────────────────
const present = RadarScope.presenceVisual({ raw: { state: 'PRESENT' }, stats: { motion_recent: true } });
check('PRESENT → verde', present.state === 'PRESENT' && present.tone === 'ok');
const stale = RadarScope.presenceVisual({ raw: { state: 'PRESENT', stale: true }, stats: {} });
check('stale → sin datos', stale.state === 'STALE' && stale.tone === 'bad');
const motion = RadarScope.presenceVisual({ raw: { state: 'ABSENT' }, stats: { motion_recent: true } });
check('movimiento sin presencia → ámbar', motion.state === 'MOTION' && motion.tone === 'warn');
const absent = RadarScope.presenceVisual({ raw: { state: 'ABSENT' }, stats: { motion_recent: false } });
check('ABSENT → dim', absent.state === 'ABSENT' && absent.tone === 'dim');

// ─── Distancia actual ────────────────────────────────────────────────────
const fresh = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stats: { motion_recent: true, last_distance: 2.76, last_distance_age_s: 2 },
});
check('PRESENT usa distancia explícita', fresh.subtitle.indexOf('2.76 m') >= 0);
check('PRESENT incluye edad', fresh.ageSeconds === 1);
check('PRESENT devuelve lastDistance', fresh.lastDistance === 2.76);
const freshNoDistance = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stats: { motion_recent: false },
});
check('PRESENT sin distancia → no disponible', freshNoDistance.subtitle.indexOf('distancia no disponible') >= 0);

// ─── Banner principal: SOLO sensor (F135) ────────────────────────────────
const presentBlocked = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stats: { motion_recent: true, last_distance: 2.8, last_distance_age_s: 1 },
  presence_decision: { outcome: 'blocked', outcome_reason: 'no_context', effective_presence: 'ABSENT' },
  range_evaluation: { status: 'unverified', reason: 'marker_already_used' },
});
check('decisión bloqueada NO cambia el banner de detección',
  presentBlocked.state === 'PRESENT' && presentBlocked.title === 'TE DETECTA'
  && presentBlocked.tone === 'ok');

const presentStaleDecision = RadarScope.detectionModel({
  raw: { state: 'PRESENT', age_seconds: 1 },
  stats: { motion_recent: true, last_distance: 2.8, last_distance_age_s: 1 },
  presence_decision: { outcome: 'unverified', effective_presence: 'ABSENT', event_at: '2020-01-01 00:00:00' },
  range_evaluation: { status: 'unverified', reason: 'zero_dp9' },
});
check('decisión unverified NO cambia el banner de detección',
  presentStaleDecision.title === 'TE DETECTA' && presentStaleDecision.tone === 'ok');

const absentStalePresent = RadarScope.detectionModel({
  raw: { state: 'ABSENT', age_seconds: 1 },
  stats: { motion_recent: false },
  presence_decision: { outcome: 'applied', effective_presence: 'PRESENT' },
});
check('decisión antigua PRESENT no convierte ABSENT en presencia',
  absentStalePresent.state === 'ABSENT' && absentStalePresent.title === 'NO TE DETECTA');

const staleRaw = RadarScope.detectionModel({ raw: { state: 'UNKNOWN', stale: true, age_seconds: 120 }, stats: {} });
check('raw viejo → RADAR SIN REPORTES', staleRaw.state === 'NO_DATA' && staleRaw.title === 'RADAR SIN REPORTES');

const pipelineDown = RadarScope.detectionModel({
  raw: { state: 'UNKNOWN', stale: true, age_seconds: 9000 },
  stats: {},
  pipeline: { enabled: true, healthy: false, state: 'stopped' },
});
check('pipeline caído → INGESTA ZIGBEE DETENIDA',
  pipelineDown.state === 'PIPELINE_DOWN' && pipelineDown.title === 'INGESTA ZIGBEE DETENIDA');

const pipelineDisabled = RadarScope.detectionModel({
  raw: { state: 'UNKNOWN', stale: true, age_seconds: 9000 },
  stats: {},
  pipeline: { enabled: false, healthy: false, state: 'disabled' },
});
check('pipeline deshabilitado no genera aviso de ingesta',
  pipelineDisabled.state === 'NO_DATA' && pipelineDisabled.title === 'RADAR SIN REPORTES');

const nullModel = RadarScope.detectionModel(null);
check('sin scope → SIN DATOS', nullModel.title === 'SIN DATOS' && nullModel.tone === 'dim');

// ─── Política operativa separada (F135) ─────────────────────────────────
const opApplied = RadarScope.operationalModel({
  presence_decision: { apply: true, outcome: 'applied', effective_presence: 'PRESENT' },
});
check('aplicada → APLICADA verde', opApplied.state === 'APPLIED' && opApplied.tone === 'ok');
const opBlocked = RadarScope.operationalModel({
  presence_decision: { apply: false, outcome: 'blocked', outcome_reason: 'no_context', effective_presence: 'ABSENT' },
});
check('bloqueada → BLOQUEADA POR POLÍTICA', opBlocked.state === 'BLOCKED' && opBlocked.tone === 'warn');
check('bloqueada explica la política', opBlocked.detail.indexOf('política') >= 0);
const opUnverified = RadarScope.operationalModel({
  presence_decision: { outcome: 'unverified', outcome_reason: 'zero_dp9', effective_presence: 'ABSENT' },
  range_evaluation: { status: 'unverified', reason: 'zero_dp9' },
});
check('sin DP9 → COMPROBANDO ALCANCE', opUnverified.state === 'UNVERIFIED' && opUnverified.tone === 'warn');
const opDoorFallback = RadarScope.operationalModel({
  presence_decision: { apply: true, outcome: 'applied', effective_presence: 'PRESENT', evidence_reason: 'dp1_without_dp9_door_corroborated' },
  range_evaluation: { status: 'in_range', reason: 'dp1_without_dp9_door_corroborated', distance_m: null },
});
check('fallback por puerta → APLICADA', opDoorFallback.state === 'APPLIED' && opDoorFallback.tone === 'ok');
const opOut = RadarScope.operationalModel({
  presence_decision: { outcome: 'out_of_range', effective_presence: 'ABSENT' },
  range_evaluation: { status: 'out_of_range', reason: 'distance_above_maximum_range', distance_m: 3.2, maximum_range_m: 2.0 },
});
check('fuera de alcance → FUERA DE ALCANCE', opOut.state === 'OUT_OF_RANGE' && opOut.tone === 'bad');
const opMaintainedAbsent = RadarScope.operationalModel({
  presence_decision: { outcome: 'maintained', effective_presence: 'ABSENT' },
});
check('ausencia mantenida → AUSENCIA MANTENIDA', opMaintainedAbsent.state === 'ABSENT' && opMaintainedAbsent.tone === 'dim');
const opNone = RadarScope.operationalModel({});
check('sin decisión → SIN DECISIÓN', opNone.state === 'NONE' && opNone.tone === 'dim');
const opStale = RadarScope.operationalModel({
  decision_age_seconds: 600,
  stale_seconds: 30,
  presence_decision: { outcome: 'unverified', effective_presence: 'ABSENT', event_at: '2020-01-01 00:00:00' },
});
check('decisión antigua → SIN DECISIÓN RECIENTE',
  opStale.state === 'STALE' && opStale.title === 'SIN DECISIÓN RECIENTE');

// ─── Histograma radial ───────────────────────────────────────────────────
const hist = RadarScope.histogram([
  { distance_m: 1.0 }, { distance_m: 1.0 }, { distance_m: 2.0 },
], 0.25);
check('histograma agrupa 1.0 m', hist.bins[1] === 2);
check('histograma agrupa 2.0 m', hist.bins[2] === 1);
check('histograma máximo', hist.max === 2);

// ─── SVG honesto ────────────────────────────────────────────────────────
const baseScope = {
  settings: { maximum_range: 3, minimum_range: 0.6, radar_sensitivity: 7, fading_time: 15.5, detection_delay: 0.1 },
  axes: { max_m: 3.5 },
  stats: {},
  series: [],
  stale_seconds: 30,
  raw: { state: 'ABSENT' },
};
const svg = RadarScope.buildSvg(Object.assign({}, baseScope, { raw: { state: 'ABSENT' } }));
check('SVG contiene svg', svg.indexOf('<svg') === 0);
check('SVG contiene sensor', svg.indexOf('RADAR') >= 0);
check('SVG contiene leyenda honesta', svg.indexOf('no informa ángulo') >= 0);
check('SVG ABSENT pinta anillo rojo tenue', svg.indexOf('#ff5252') >= 0);
const svgPresent = RadarScope.buildSvg(Object.assign({}, baseScope, {
  raw: { state: 'PRESENT' }, stats: { motion_recent: true }, series: [{ distance_m: 1.2 }],
}));
check('SVG con PRESENT pinta glow', svgPresent.indexOf('scope-presence') >= 0);
const svgStale = RadarScope.buildSvg(Object.assign({}, baseScope, { raw: { state: 'UNKNOWN', stale: true } }));
check('SVG STALE pinta anillo gris', svgStale.indexOf('#8a8aa0') >= 0);
const svgOut = RadarScope.buildSvg(Object.assign({}, baseScope, {
  raw: { state: 'PRESENT' }, stats: { motion_recent: true },
  range_evaluation: { status: 'out_of_range', reason: 'distance_above_maximum_range', distance_m: 3.2, maximum_range_m: 2.0 },
}));
check('SVG fuera de alcance no pinta glow verde', svgOut.indexOf('scope-presence') < 0);
check('SVG fuera de alcance marca bloqueo', svgOut.indexOf('scope-range-blocked') >= 0);
check('SVG sin scope devuelve vacío', RadarScope.buildSvg(null) === '');

// ─── Utilidades de decisión ──────────────────────────────────────────────
check('outcomeOf reconoce el resultado final',
  RadarScope.outcomeOf({ outcome: 'pending_absent' }) === 'pending_absent'
  && RadarScope.outcomeOf({ apply: true }) === 'applied'
  && RadarScope.outcomeOf({ apply: false, discard_reason: 'candidate_absent' }) === 'pending_absent');
check('reasonText explica no_context',
  RadarScope.reasonText('no_context').indexOf('política') >= 0);

console.log('\nTotal: ' + passed + ' passed, ' + failed + ' failed');
process.exit(failed === 0 ? 0 : 1);
