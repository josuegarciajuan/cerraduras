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

// ─── Resumen ─────────────────────────────────────────────────────────────
console.log('\nTotal: ' + passed + ' passed, ' + failed + ' failed');
process.exit(failed === 0 ? 0 : 1);
