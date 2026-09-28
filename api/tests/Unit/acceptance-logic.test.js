#!/usr/bin/env node
'use strict';

/**
 * Unit tests for the manual-acceptance panel pure logic (F55, RF-62).
 *
 * Trazabilidad: RF-62.2/62.3 · design.md §19 · tasks.md TSK-F55-02.
 * Pure-logic only: no DOM, no network, no DB.
 *
 * Run: node api/tests/Unit/acceptance-logic.test.js
 */

const path = require('path');

const A = require(path.join(__dirname, '..', '..', 'public', 'assets', 'acceptance-logic.js'));

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

console.log('\n\u2500\u2500 acceptance-logic (RF-62) \u2500\u2500');

// ─── 1. normalizeStatus ────────────────────────────────────────────────
check('normalizeStatus: valor desconocido → PENDING',
  A.normalizeStatus('whatever') === A.STATUS.PENDING);
check('normalizeStatus: PASS se conserva',
  A.normalizeStatus('PASS') === A.STATUS.PASS);

// ─── 2. summarize básico ───────────────────────────────────────────────
const T = [
  { id: 'P1', block: '0', status: 'PASS' },
  { id: 'P2', block: '0', status: 'FAIL' },
  { id: 'P3', block: '0', status: 'NA' },
  { id: 'P4', block: 'A', status: 'PENDING' },
  { id: 'P5', block: 'A', status: null },
];
const s = A.summarize(T);
check('summarize: pass/fail/na/pending/total',
  s.pass === 1 && s.fail === 1 && s.na === 1 && s.pending === 2 && s.total === 5);
check('summarize: byBlock agrupa por bloque',
  s.byBlock['0'].total === 3 && s.byBlock['A'].total === 2);
check('summarize: no verde con fail/pending',
  s.green === false);

// ─── 3. pct y N/A cuenta como verde por defecto ────────────────────────
const sNA = A.summarize([
  { id: 'P1', block: '0', status: 'PASS' },
  { id: 'P2', block: '0', status: 'NA' },
]);
check('summarize: pct incluye N/A como verde (default) → 100',
  sNA.pct === 100 && sNA.green === true);

// ─── 4. modo estricto (naCountsAsGreen=false) ──────────────────────────
const sStrict = A.summarize([
  { id: 'P1', block: '0', status: 'PASS' },
  { id: 'P2', block: '0', status: 'NA' },
], { naCountsAsGreen: false });
check('estricto: N/A bloquea el verde',
  sStrict.green === false);
check('estricto: pct no cuenta N/A → 50',
  sStrict.pct === 50);

// ─── 5. isGreen ────────────────────────────────────────────────────────
check('isGreen: total 0 → false', A.isGreen({ total: 0, pending: 0, fail: 0 }, {}) === false);
check('isGreen: todo PASS → true',
  A.isGreen({ total: 3, pass: 3, pending: 0, fail: 0, na: 0 }, {}) === true);
check('isGreen: con fail → false',
  A.isGreen({ total: 3, pass: 2, fail: 1, pending: 0, na: 0 }, {}) === false);

// ─── 6. nextPending / nextOpen ─────────────────────────────────────────
const nav = [
  { id: 'P1', block: '0', status: 'PASS' },
  { id: 'P2', block: '0', status: 'PENDING' },
  { id: 'P3', block: '0', status: 'NA' },
  { id: 'P4', block: 'A', status: 'FAIL' },
  { id: 'P5', block: 'A', status: 'PENDING' },
];
check('nextPending: salta PASS/N/A y va al siguiente PENDING',
  A.nextPending(nav, 'P1') === 'P2');
check('nextPending: con wrap encuentra el primero pendiente',
  A.nextPending(nav, 'P5') === 'P2');
check('nextOpen: incluye FAIL como no resuelto',
  A.nextOpen(nav, 'P2') === 'P4');
check('nextOpen: desde el último vuelve al primer no resuelto',
  A.nextOpen(nav, 'P5') === 'P2');
check('nextPending: sin pendientes → null',
  A.nextPending([{ id: 'P1', status: 'PASS' }], 'P1') === null);

// ─── 7. progressCells ──────────────────────────────────────────────────
const cells = A.progressCells(nav);
check('progressCells: una celda por prueba con estado normalizado',
  cells.length === 5 && cells[1].status === 'PENDING' && cells[2].status === 'NA');

// ─── 8. diffRuns ───────────────────────────────────────────────────────
const prev = { tests: { P1: { status: 'PASS' }, P2: { status: 'FAIL' }, P3: { status: 'PASS' } } };
const curr = { tests: { P1: { status: 'FAIL' }, P2: { status: 'PASS' }, P3: { status: 'PASS' }, P4: { status: 'PENDING' } } };
const d = A.diffRuns(prev, curr);
const byId = {};
d.forEach((x) => { byId[x.id] = x; });
check('diffRuns: PASS→FAIL es regression', byId.P1 && byId.P1.kind === 'regression');
check('diffRuns: FAIL→PASS es fix', byId.P2 && byId.P2.kind === 'fix');
check('diffRuns: sin cambio no aparece', !byId.P3);
check('diffRuns: nueva prueba es new', byId.P4 && byId.P4.kind === 'new');

// ─── 9. toMarkdown ─────────────────────────────────────────────────────
const catalog = {
  blocks: [
    { id: '0', name: 'Precondiciones', order: 0 },
    { id: 'A', name: 'Estado inicial', order: 1 },
  ],
  tests: [
    { id: 'P1', block: '0', title: 'Health' },
    { id: 'P2', block: '0', title: 'Workers' },
    { id: 'P3', block: 'A', title: 'Reset' },
  ],
};
const md = A.toMarkdown({
  run_id: '12-test', operator: 'Ana', room_id: 12, commit: 'abc123',
  tests: { P1: { status: 'PASS' }, P2: { status: 'FAIL', notes: 'timeout | revisar' }, P3: { status: 'NA' } },
}, catalog);
check('toMarkdown: cabecera con metadatos',
  md.indexOf('# Aceptación manual PROTO2 — 12-test') === 0 && md.indexOf('Ana') !== -1);
check('toMarkdown: secciones por bloque',
  md.indexOf('## Bloque 0') !== -1 && md.indexOf('## Bloque A') !== -1);
check('toMarkdown: escapa pipes en notas', md.indexOf('timeout \\| revisar') !== -1);
check('toMarkdown: incluye tabla y estados',
  md.indexOf('| ID | Título | Estado | Notas |') !== -1 && md.indexOf('| P2 | Workers | FAIL |') !== -1);

// ─── 10. require-safe ──────────────────────────────────────────────────
check('módulo exporta la lógica pura',
  typeof A.summarize === 'function' && typeof A.toMarkdown === 'function' && typeof A.diffRuns === 'function');

console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' acceptance-logic: ' + passed + ' passed, ' + failed + ' failed\n');
if (failed > 0) process.exit(1);
process.exit(0);
