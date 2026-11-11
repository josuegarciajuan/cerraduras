#!/usr/bin/env node
'use strict';

/**
 * Unit tests for the guided radar wizard presentation logic (F125.1).
 *
 * Pure logic only: no DOM, no network, no DB, no Tuya.
 *
 * Run: node api/tests/Unit/radar-wizard.test.js
 */

const path = require('path');
const W = require(path.join(__dirname, '..', '..', 'public', 'assets', 'radar-wizard.js'));

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

// ─── Estados visuales ────────────────────────────────────────────────────
check('pending se pinta como activo', W.visualStatus('pending') === 'active');
check('awaiting_decision se conserva', W.visualStatus('awaiting_decision') === 'awaiting_decision');
check('desconocido → locked', W.visualStatus('raro') === 'locked');
check('icono passed', W.statusIcon('passed') === '✓');
check('icono failed', W.statusIcon('failed') === '✕');
check('etiqueta active', W.statusLabel('active') === 'En curso');
check('countdown', W.formatCountdown(4.2) === '5 s');
check('countdown 0', W.formatCountdown(0) === '');

// ─── Acciones ────────────────────────────────────────────────────────────
function actions(step) { return W.actionModel(step).buttons.map(function (b) { return b.action; }).join(','); }

check('pasado no tiene botones', actions({ kind: 'present', status: 'passed' }) === '');
check('bloqueado no tiene botones', actions({ kind: 'present', status: 'locked' }) === '');
check('perfil no tiene botones', actions({ kind: 'profile', status: 'active' }) === '');
check('done no tiene botones', actions({ kind: 'done', status: 'passed' }) === '');

const failedStep = W.actionModel({ kind: 'absent', status: 'failed', detail: { message: 'Te detecta fuera' } });
check('failed → retry/reset', failedStep.buttons.map(function (b) { return b.action; }).join(',') === 'retry,reset');
check('failed → muestra motivo', failedStep.note === 'Te detecta fuera');

const awaiting = W.actionModel({
  kind: 'sensitivity',
  status: 'awaiting_decision',
  detail: { recommendation: { value: 8 }, message: 'Sugerencia: 8/9' }
});
check('sensibilidad awaiting → accept/keep', awaiting.buttons.map(function (b) { return b.action; }).join(',') === 'sensitivity_accept,sensitivity_keep');
check('accept lleva el valor', awaiting.buttons[0].value === 8);
check('accept etiqueta el valor', awaiting.buttons[0].label === 'Aceptar 8/9');

const walkStart = W.actionModel({ kind: 'manual_walk', status: 'active', started_at: null, detail: {} });
check('paseo sin empezar → start', walkStart.buttons.map(function (b) { return b.action; }).join(',') === 'start');
const walkActive = W.actionModel({ kind: 'manual_walk', status: 'active', started_at: '2026-11-11T10:00:00Z', detail: {} });
check('paseo activo → walk_pass/start', walkActive.buttons.map(function (b) { return b.action; }).join(',') === 'walk_pass,start');

const sensStart = W.actionModel({ kind: 'sensitivity', status: 'active', started_at: null, detail: {} });
check('sensibilidad sin empezar → start', sensStart.buttons.map(function (b) { return b.action; }).join(',') === 'start');
const sensMeasuring = W.actionModel({ kind: 'sensitivity', status: 'active', started_at: '2026-11-11T10:00:00Z', detail: {} });
check('sensibilidad midiendo → sin botones', sensMeasuring.buttons.length === 0);

const absentStart = W.actionModel({ kind: 'absent', status: 'pending', detail: {} });
check('fuera sin empezar → start', absentStart.buttons.map(function (b) { return b.action; }).join(',') === 'start');
const absentActive = W.actionModel({ kind: 'absent', status: 'active', started_at: '2026-11-11T10:00:00Z', detail: { countdown: 20 } });
check('fuera en curso → sin botones', absentActive.buttons.length === 0);

// ─── Detalle ─────────────────────────────────────────────────────────────
const lines = W.detailLines({
  kind: 'manual_walk',
  detail: { loss_distance: 2.5, last_distance: 2.8, configured_max: 3.0 }
});
check('detalle paseo: último eco', lines.indexOf('Último eco: 2.80 m') >= 0);
check('detalle paseo: sin duplicar pérdida', lines.length === 1);
const countLines = W.detailLines({ kind: 'absent', detail: { countdown: 12 } });
check('countdown va en el mensaje, no duplicado', countLines.length === 0);
const sensLines = W.detailLines({ kind: 'sensitivity', detail: { recommendation: { value: 8 } } });
check('sugerencia va en el mensaje, no duplicada', sensLines.length === 0);

console.log('\nTotal: ' + passed + ' passed, ' + failed + ' failed');
process.exit(failed === 0 ? 0 : 1);
