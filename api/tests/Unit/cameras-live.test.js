#!/usr/bin/env node
'use strict';

/**
 * Unit tests for the MJPEG live server pure logic (F70, RF-80).
 *
 * Trazabilidad: RF-80.1 · design.md §34 · tasks.md TSK-F70-02.
 *
 * Pure-logic tests only: no network, no ffmpeg, no DOM.
 *
 * Run: node api/tests/Unit/cameras-live.test.js
 */

const path = require('path');

const {
  extractJpegFrames,
  frameMjpeg,
} = require(path.join(__dirname, '..', '..', 'bin', 'cameras-live.js'));

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

const SOI = Buffer.from([0xff, 0xd8]);
const EOI = Buffer.from([0xff, 0xd9]);

function jpeg(payload) {
  return Buffer.concat([SOI, Buffer.from(payload), EOI]);
}

console.log('\n\u2500\u2500 cameras-live (RF-80) \u2500\u2500');

// ─── 1. Un frame completo ──────────────────────────────────────────────
let r = extractJpegFrames(jpeg([1, 2, 3, 4]));
check('1 frame completo', r.frames.length === 1);
check('frame empieza por SOI', r.frames[0].subarray(0, 2).equals(SOI));
check('frame termina en EOI', r.frames[0].subarray(-2).equals(EOI));
check('sin cola', r.tail.length === 0);
check('frame íntegro', r.frames[0].length === 2 + 4 + 2);

// ─── 2. Varios frames en el mismo buffer ───────────────────────────────
r = extractJpegFrames(Buffer.concat([jpeg([9]), jpeg([8, 8])]));
check('2 frames', r.frames.length === 2);
check('2º frame', r.frames[1].length === 2 + 2 + 2);
check('sin cola (2 frames)', r.tail.length === 0);

// ─── 3. Basura previa al primer SOI ────────────────────────────────────
r = extractJpegFrames(Buffer.concat([Buffer.from([0x00, 0x11, 0x22]), jpeg([7, 7])]));
check('descarta basura previa', r.frames.length === 1);
check('frame tras basura es válido', r.frames[0].subarray(-2).equals(EOI));

// ─── 4. Frame parcial (sin EOI) → cola ─────────────────────────────────
const parcial = Buffer.concat([SOI, Buffer.from([1, 2, 3])]);
r = extractJpegFrames(parcial);
check('frame parcial no se emite', r.frames.length === 0);
check('cola conserva el parcial', r.tail.equals(parcial));

// ─── 5. Frame completo + parcial siguiente ─────────────────────────────
r = extractJpegFrames(Buffer.concat([jpeg([1]), SOI, Buffer.from([2, 2])]));
check('1 frame emitido y 1 parcial en cola', r.frames.length === 1 && r.tail.subarray(0, 2).equals(SOI));

// ─── 6. Sin SOI → cola vacía (basura sin marca) ────────────────────────
r = extractJpegFrames(Buffer.from([0xaa, 0xbb, 0xcc]));
check('sin SOI → sin frames y sin cola', r.frames.length === 0 && r.tail.length === 0);

// ─── 7. Vacío ──────────────────────────────────────────────────────────
r = extractJpegFrames(Buffer.alloc(0));
check('buffer vacío seguro', r.frames.length === 0 && r.tail.length === 0);

// ─── 8. frameMjpeg (envoltura multipart) ───────────────────────────────
const packet = frameMjpeg(jpeg([0x01, 0x02]));
const head = packet.toString('latin1', 0, 60);
check('multipart con boundary=frame', head.indexOf('--frame') === 0);
check('content-type image/jpeg', head.indexOf('Content-Type: image/jpeg') > 0);
check('Content-Length correcto', head.indexOf('Content-Length: 6') > 0);
check('termina con CRLF', packet.subarray(-2).toString('latin1') === '\r\n');

// ─── 9. Módulo require-safe (no arranca servidor al importar) ──────────
check('module exports pure logic',
  typeof extractJpegFrames === 'function' && typeof frameMjpeg === 'function');

console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' cameras-live: ' + passed + ' passed, ' + failed + ' failed\n');
if (failed > 0) process.exit(1);
process.exit(0);
