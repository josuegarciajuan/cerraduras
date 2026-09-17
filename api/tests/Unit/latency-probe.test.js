#!/usr/bin/env node
'use strict';

/**
 * Unit tests for the presence latency probe pure helpers (F47, TSK-F47-02).
 *
 * Trazabilidad: RF-53.1 (medición), RF-53.2 (sin cuota), RF-53.3 (atribución).
 *
 * Pure-logic tests only: no network, no DB, no SSE. Requiring the module must
 * not start the probe (guarded by `require.main === module`).
 *
 * Run: node api/tests/Unit/latency-probe.test.js
 */

const path = require('path');

const {
  toMs,
  parseMarker,
  parseTuyaT,
  computeDeltas,
  formatDelta,
  pickMarkForEvent,
  newMarkerLines,
} = require(path.join(__dirname, '..', '..', 'bin', 'presence-latency-probe.js'));

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

console.log('\n\u2500\u2500 presence-latency-probe (F47/RF-53) \u2500\u2500');

// ─── 1. toMs ───────────────────────────────────────────────────────────
check('toMs parses ISO-8601 UTC',
  toMs('2026-09-17T11:17:12.000Z') === Date.parse('2026-09-17T11:17:12.000Z'));

check('toMs treats MySQL DATETIME as UTC',
  toMs('2026-09-17 11:17:12.917') === Date.parse('2026-09-17T11:17:12.917Z'));

check('toMs handles epoch seconds and milliseconds',
  toMs('1789643832') === 1789643832000 && toMs(1789643832720) === 1789643832720);

check('toMs returns null for empty values',
  toMs('') === null && toMs(null) === null && toMs(undefined) === null);

// ─── 2. parseMarker ────────────────────────────────────────────────────
const nowMs = Date.parse('2026-09-17T11:20:00.000Z');

check('parseMarker accepts a bare label using the read time',
  (() => {
    const m = parseMarker('DELANTE_SENSOR', nowMs);
    return m && m.label === 'DELANTE_SENSOR' && m.tsMs === nowMs;
  })());

check('parseMarker accepts label + explicit ISO time',
  (() => {
    const m = parseMarker('DELANTE_SENSOR 2026-09-17T11:16:53.000Z', nowMs);
    return m && m.label === 'DELANTE_SENSOR' && m.tsMs === Date.parse('2026-09-17T11:16:53.000Z');
  })());

check('parseMarker accepts ISO + label order and normalises spaces',
  (() => {
    const m = parseMarker('2026-09-17T11:16:53.000Z puerta abre', nowMs);
    return m && m.label === 'PUERTA_ABRE' && m.tsMs === Date.parse('2026-09-17T11:16:53.000Z');
  })());

check('parseMarker rejects blank lines and comments',
  parseMarker('', nowMs) === null && parseMarker('   ', nowMs) === null &&
  parseMarker('# nota', nowMs) === null);

// ─── 3. parseTuyaT ─────────────────────────────────────────────────────
check('parseTuyaT reads tuya_t from a parsed object',
  parseTuyaT({ tuya_t: 1789643832720 }) === 1789643832720);

check('parseTuyaT reads tuya_t from raw JSON string',
  parseTuyaT('{"tuya_t":1789643832720,"tuya_dp":"presence_state"}') === 1789643832720);

check('parseTuyaT returns null when absent or invalid',
  parseTuyaT({}) === null && parseTuyaT('{"x":1}') === null &&
  parseTuyaT('not-json') === null && parseTuyaT(null) === null);

// ─── 4. computeDeltas ──────────────────────────────────────────────────
check('computeDeltas splits the three hops',
  (() => {
    const d = computeDeltas({
      tuyaT: 1000,
      receivedAtMs: 1500,
      markMs: 800,
      sseServerTs: 1700,
    });
    return d.physicalToDevice === 200 && d.deviceToDb === 500 && d.dbToSse === 200;
  })());

check('computeDeltas returns null when the mark is missing',
  (() => {
    const d = computeDeltas({ tuyaT: 1000, receivedAtMs: 1500, markMs: null, sseServerTs: null });
    return d.physicalToDevice === null && d.deviceToDb === 500 && d.dbToSse === null;
  })());

// ─── 5. formatDelta ────────────────────────────────────────────────────
check('formatDelta prints ms under one second',
  formatDelta(0) === '0ms' && formatDelta(850) === '850ms' && formatDelta(-120) === '-120ms');

check('formatDelta prints seconds with one decimal',
  formatDelta(19000) === '19.0s' && formatDelta(-2500) === '-2.5s');

check('formatDelta prints a dash for null/NaN',
  formatDelta(null) === '\u2014' && formatDelta(undefined) === '\u2014' && formatDelta(NaN) === '\u2014');

// ─── 6. pickMarkForEvent ───────────────────────────────────────────────
const marks = [
  { label: 'PUERTA_ABRE', tsMs: 1000 },
  { label: 'DELANTE_SENSOR', tsMs: 5000 },
];

check('pickMarkForEvent chooses the most recent mark before the event',
  (() => {
    const m = pickMarkForEvent(marks, 6000, 180000);
    return m && m.label === 'DELANTE_SENSOR';
  })());

check('pickMarkForEvent ignores marks outside the window',
  pickMarkForEvent(marks, 500000, 180000) === null);

check('pickMarkForEvent ignores marks in the future',
  pickMarkForEvent(marks, 500, 180000) === null);

// ─── 7. newMarkerLines (append-only, sin duplicados) ───────────────────
check('newMarkerLines devuelve todas las líneas la primera vez',
  (() => {
    const r = newMarkerLines('A\nB\n', 0);
    return JSON.stringify(r.lines) === JSON.stringify(['A', 'B']) && r.seen === 2;
  })());

check('newMarkerLines NO re-procesa líneas ya consumidas',
  (() => {
    const r = newMarkerLines('A\nB\n', 2);
    return r.lines.length === 0 && r.seen === 2;
  })());

check('newMarkerLines devuelve solo las líneas nuevas al hacer append',
  (() => {
    const r = newMarkerLines('A\nB\nC\n', 2);
    return JSON.stringify(r.lines) === JSON.stringify(['C']) && r.seen === 3;
  })());

check('newMarkerLines reinicia el cursor si el fichero se truncó',
  (() => {
    const r = newMarkerLines('A\n', 5);
    return JSON.stringify(r.lines) === JSON.stringify(['A']) && r.seen === 1;
  })());

check('newMarkerLines tolera vacío/null',
  newMarkerLines('', 0).seen === 0 && newMarkerLines(null, 3).lines.length === 0);

// ─── Summary ───────────────────────────────────────────────────────────
console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' latency-probe: ' + passed + ' passed, ' + failed + ' failed\n');
if (failed > 0) {
  process.exit(1);
}
process.exit(0);
