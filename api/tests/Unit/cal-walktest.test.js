#!/usr/bin/env node
'use strict';

/**
 * Unit tests for the presence-calibration walk test (F44+, RF-52.4).
 *
 * Trazabilidad: RF-52.4.1/52.4.2 · design.md §13.7 · tasks.md TSK-F44-10.
 *
 * Pure-logic tests only: no network, no DB, no Tuya quota.
 *
 * Run: node api/tests/Unit/cal-walktest.test.js
 */

const path = require('path');

const {
  isDetected,
  summarizeReadings,
  suggestFarAction,
  ACTION,
} = require(path.join(__dirname, '..', '..', 'public', 'assets', 'cal-walktest.js'));

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

const CAPS_24G = { far_min: 75, far_max: 900, far_step: 75, sens_min: 1, sens_max: 10 };
// Piso efectivo del 24G V3 (rechaza 75 cm, mínimo real 150 cm).
const EFF_MIN_24G = 150;

function seq(states, t0) {
  return states.map((s, i) => ({ t: (t0 || 1000) + i * 1500, state: s }));
}

console.log('\n\u2500\u2500 cal-walktest (RF-52.4) \u2500\u2500');

// ─── 1. isDetected ─────────────────────────────────────────────────────
check('isDetected: presence → true', isDetected('presence') === true);
check('isDetected: move → true', isDetected('move') === true);
check('isDetected: none → false', isDetected('none') === false);
check('isDetected: null → false', isDetected(null) === false);

// ─── 2. summarizeReadings ──────────────────────────────────────────────
const sum = summarizeReadings(seq(['move', 'presence', 'none', 'none']));
check('summarize: total=4', sum.total === 4);
check('summarize: detectedCount=2', sum.detectedCount === 2);
check('summarize: noneCount=2', sum.noneCount === 2);
check('summarize: detected=true', sum.detected === true);
check('summarize: allNone=false', sum.allNone === false);
check('summarize: firstNoneAt is the first none timestamp', sum.firstNoneAt === 1000 + 2 * 1500);

const allNone = summarizeReadings(seq(['none', 'none']));
check('summarize: allNone=true when every reading is none', allNone.allNone === true);
check('summarize: detected=false when every reading is none', allNone.detected === false);
check('summarize: empty is safe', summarizeReadings([]).total === 0);

// ─── 3. suggestFarAction: keep ─────────────────────────────────────────
const keep = suggestFarAction({
  boundary: seq(['move', 'presence']),
  away:     seq(['none', 'none'], 9000),
  currentFar: 225,
  caps: CAPS_24G,
  effectiveMin: EFF_MIN_24G,
});
check('keep: detecta en límite y no al alejarse → KEEP', keep.action === ACTION.KEEP);
check('keep: mantiene el radio actual', keep.far === 225);

// ─── 4. suggestFarAction: decrease (sigue detectando al alejarse) ──────
const dec = suggestFarAction({
  boundary: seq(['presence']),
  away:     seq(['move', 'presence'], 9000),
  currentFar: 225,
  caps: CAPS_24G,
  effectiveMin: EFF_MIN_24G,
});
check('decrease: sigue detectando al alejarse → DECREASE', dec.action === ACTION.DECREASE);
check('decrease: baja un paso (225 → 150)', dec.far === 150);

// ─── 5. suggestFarAction: en el piso efectivo → SHIELD ─────────────────
const shieldLow = suggestFarAction({
  boundary: seq(['move']),
  away:     seq(['move'], 9000),
  currentFar: 150,
  caps: CAPS_24G,
  effectiveMin: EFF_MIN_24G,
});
check('shield: en el mínimo efectivo y sigue detectando → SHIELD', shieldLow.action === ACTION.SHIELD);
check('shield: no propone un valor inválido (null)', shieldLow.far === null);

// ─── 6. suggestFarAction: increase (no detecta ni en el límite) ────────
const inc = suggestFarAction({
  boundary: seq(['none', 'none']),
  away:     seq(['none'], 9000),
  currentFar: 150,
  caps: CAPS_24G,
  effectiveMin: EFF_MIN_24G,
});
check('increase: no detecta en el límite → INCREASE', inc.action === ACTION.INCREASE);
check('increase: sube un paso (150 → 225)', inc.far === 225);

// ─── 7. suggestFarAction: al máximo sin detectar → SHIELD ──────────────
const shieldHigh = suggestFarAction({
  boundary: seq(['none']),
  away:     seq(['none'], 9000),
  currentFar: 900,
  caps: CAPS_24G,
  effectiveMin: EFF_MIN_24G,
});
check('shield: al máximo y no detecta → SHIELD', shieldHigh.action === ACTION.SHIELD);

// ─── 8. suggestFarAction: sin datos ────────────────────────────────────
const noData = suggestFarAction({ boundary: [], away: [], currentFar: 150, caps: CAPS_24G });
check('no_data: sin lecturas → NO_DATA', noData.action === ACTION.NO_DATA);

// ─── 9. Piso efectivo por defecto (sin effectiveMin) ───────────────────
const decDefault = suggestFarAction({
  boundary: seq(['move']),
  away:     seq(['move'], 9000),
  currentFar: 150,
  caps: { far_min: 150, far_max: 900, far_step: 75 },
});
check('decrease: sin effectiveMin usa far_min=150 como piso → SHIELD', decDefault.action === ACTION.SHIELD);

// ─── 10. Módulo require-safe ───────────────────────────────────────────
check('module exports the pure walk-test logic',
  typeof suggestFarAction === 'function' && typeof summarizeReadings === 'function');

console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' cal-walktest: ' + passed + ' passed, ' + failed + ' failed\n');
if (failed > 0) process.exit(1);
process.exit(0);
