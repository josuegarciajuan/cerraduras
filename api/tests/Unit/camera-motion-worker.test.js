#!/usr/bin/env node
'use strict';

/**
 * Unit tests for the camera motion worker pure logic (F88, RF-122).
 *
 * Trazabilidad: RF-122.2 · design.md §52.2.
 * Pure-logic tests only: no ffmpeg, no network, no DB.
 *
 * Run: node api/tests/Unit/camera-motion-worker.test.js
 */

const path = require('path');

const { parseScale, frameDiffPct, debounce, go2rtcUrl } = require(
  path.join(__dirname, '..', '..', 'bin', 'camera-motion-worker.js')
);

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

console.log('\n\u2500\u2500 camera-motion-worker (RF-122) \u2500\u2500');

// parseScale
let s = parseScale('160:120');
check('parseScale 160:120 → 160x120', s.w === 160 && s.h === 120);
s = parseScale('320x240');
check('parseScale 320x240 → 320x240', s.w === 320 && s.h === 240);
s = parseScale('');
check('parseScale inválido → default 160x120', s.w === 160 && s.h === 120);

// frameDiffPct
const a = Buffer.alloc(100, 10);
const b = Buffer.alloc(100, 10);
check('frames idénticos → 0%', frameDiffPct(a, b, 25) === 0);

const c = Buffer.alloc(100, 10);
for (let i = 0; i < 40; i++) c[i] = 200; // 40/100 píxeles cambian
check('40% de píxeles cambiados → ~40%', Math.abs(frameDiffPct(a, c, 25) - 40) < 0.001);

// Por debajo del umbral de gris no cuenta.
const d = Buffer.alloc(100, 10);
for (let i = 0; i < 40; i++) d[i] = 20; // diff=10 < threshold 25
check('diferencia < umbral no cuenta → 0%', frameDiffPct(a, d, 25) === 0);

// longitudes distintas → 0 (no rompe)
check('longitudes distintas → 0%', frameDiffPct(Buffer.alloc(10), Buffer.alloc(20), 25) === 0);

// debounce (histéresis): K de N
check('debounce [1,0,0] min=2 win=3 → false', debounce([1, 0, 0], 2, 3) === false);
check('debounce [1,1,0] min=2 win=3 → true', debounce([1, 1, 0], 2, 3) === true);
check('debounce [0,0,1] min=2 win=3 → false', debounce([0, 0, 1], 2, 3) === false);
check('debounce ventana recorta a los últimos 3', debounce([1, 1, 1, 0], 2, 2) === false);
check('debounce [1,1,1,0] min=2 win=3 → true', debounce([1, 1, 1, 0], 2, 3) === true);

// go2rtcUrl
check('go2rtcUrl construye el restream',
  go2rtcUrl('rtsp://127.0.0.1:8554/', 'almacen_12_INTERIOR') === 'rtsp://127.0.0.1:8554/almacen_12_INTERIOR');

console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' camera-motion-worker: ' + passed + ' passed, ' + failed + ' failed\n');
if (failed > 0) process.exit(1);
process.exit(0);
