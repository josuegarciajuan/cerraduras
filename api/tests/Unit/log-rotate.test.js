#!/usr/bin/env node
'use strict';

/**
 * Unit tests for api/bin/log-rotate.sh (saneamiento de logs, Fase 49).
 *
 * Trazabilidad: TAREA 1 (parametrización LOGDIR/MAX_MB/KEEP_DAYS, validación
 * de MAX_MB) y TAREA 2 (programación por timer systemd).
 *
 * Aislamiento: trabaja SIEMPRE sobre un directorio temporal; nunca toca
 * api/logs real. Sin red, sin BD, sin cuota.
 *
 * Run: node api/tests/Unit/log-rotate.test.js
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

const SCRIPT = path.join(__dirname, '..', '..', 'bin', 'log-rotate.sh');
const MB = 1024 * 1024;
const OLD_LITERAL = '⚠ no hay dispositivos PRESENCE en la BD';

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

function run(env) {
  return spawnSync('bash', [SCRIPT], {
    env: Object.assign({}, process.env, env),
    encoding: 'utf8',
  });
}

console.log('\n\u2500\u2500 log-rotate (TAREA 1/2) \u2500\u2500');

check('el script existe', fs.existsSync(SCRIPT));

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'log-rotate-test-'));

try {
  // ─── 1. Rotación de un fichero por encima del umbral ────────────────────
  const big = path.join(tmp, 'app.log');
  const small = path.join(tmp, 'small.log');
  fs.writeFileSync(big, Buffer.alloc(2 * MB, 0x78)); // ~2 MB
  fs.writeFileSync(small, Buffer.alloc(1024, 0x61)); // 1 KB

  const res = run({ LOGDIR: tmp, MAX_MB: '1', KEEP_DAYS: '7' });
  check('ejecución con MAX_MB=1 termina con exit 0', res.status === 0);

  const rotated = big + '.1';
  check('se crea <fichero>.1', fs.existsSync(rotated));
  check('<fichero>.1 conserva el tamaño original (~2 MB)',
    fs.existsSync(rotated) && fs.statSync(rotated).size === 2 * MB);
  check('el original queda a 0 bytes (truncado en sitio)',
    fs.statSync(big).size === 0);
  check('un fichero por debajo del umbral NO se rota',
    !fs.existsSync(small + '.1') && fs.statSync(small).size === 1024);
  check('el log de salida indica la rotación',
    /Rotating .*app\.log .*> 1 MB/.test(res.stdout));

  // ─── 2. Purga de rotaciones antiguas (KEEP_DAYS) ────────────────────────
  const stale = path.join(tmp, 'stale.log.1');
  fs.writeFileSync(stale, 'viejo');
  const tenDaysAgo = new Date(Date.now() - 10 * 24 * 3600 * 1000);
  fs.utimesSync(stale, tenDaysAgo, tenDaysAgo);
  run({ LOGDIR: tmp, MAX_MB: '1', KEEP_DAYS: '7' });
  check('purga rotaciones *.log.N más antiguas que KEEP_DAYS',
    !fs.existsSync(stale));

  // ─── 3. MAX_MB inválido → aborta con mensaje claro ──────────────────────
  const bad = run({ LOGDIR: tmp, MAX_MB: 'abc', KEEP_DAYS: '7' });
  check('MAX_MB no entero aborta con exit != 0', bad.status !== 0);
  check('MAX_MB no entero aborta con código 2', bad.status === 2);
  check('MAX_MB no entero emite mensaje claro',
    /MAX_MB debe ser un entero positivo/.test(bad.stderr));

  // ─── 4. MAX_MB=0 no es un umbral válido ─────────────────────────────────
  const zero = run({ LOGDIR: tmp, MAX_MB: '0', KEEP_DAYS: '7' });
  check('MAX_MB=0 aborta con exit 2', zero.status === 2);
  check('MAX_MB=0 emite mensaje claro',
    /MAX_MB debe ser mayor que 0/.test(zero.stderr));

  // ─── 5. Valores por defecto y validación de KEEP_DAYS ───────────────────
  const src = fs.readFileSync(SCRIPT, 'utf8');
  check('LOGDIR parametrizable con defecto de producción',
    src.includes('LOGDIR="${LOGDIR:-/root/cerraduras/api/logs}"'));
  check('MAX_MB parametrizable con defecto 50',
    src.includes('MAX_MB="${MAX_MB:-50}"'));
  check('KEEP_DAYS parametrizable con defecto 7',
    src.includes('KEEP_DAYS="${KEEP_DAYS:-7}"'));
  check('el umbral se convierte a bytes (MB * 1048576)',
    /MAX_BYTES=\$\(\( MAX_MB \* 1048576 \)\)/.test(src));

  const badDays = run({ LOGDIR: tmp, MAX_MB: '1', KEEP_DAYS: 'x' });
  check('KEEP_DAYS no entero aborta con exit 2', badDays.status === 2);
  check('KEEP_DAYS no entero emite mensaje claro',
    /KEEP_DAYS debe ser un entero/.test(badDays.stderr));

  // ─── 6. Guardia: no debe contener el literal del manager de pollers ─────
  check('el script no arrastra el literal ajeno de presence-poller', !src.includes(OLD_LITERAL));
} finally {
  fs.rmSync(tmp, { recursive: true, force: true });
}

console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' log-rotate: ' + passed + ' passed, ' + failed + ' failed\n');
if (failed > 0) process.exit(1);
process.exit(0);
