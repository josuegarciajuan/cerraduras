#!/usr/bin/env node
'use strict';

/**
 * Test-guardia para api/bin/presence-poller-manager.sh (saneamiento de logs,
 * Fase 49, TAREA 3).
 *
 * Objetivo: evitar la regresión de loguear "no hay dispositivos PRESENCE" en
 * CADA ciclo (30 s) cuando en realidad todos los sensores son `presence_source`
 * push/disabled (no requieren poller). El aviso debe aparecer una sola vez al
 * cambiar de estado, mediante la variable ASSIGNED_STATE.
 *
 * Trazabilidad: TAREA 3.
 *
 * Run: node api/tests/Unit/presence-poller-manager.test.js
 */

const fs = require('fs');
const path = require('path');

const SCRIPT = path.join(__dirname, '..', '..', 'bin', 'presence-poller-manager.sh');

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

console.log('\n\u2500\u2500 presence-poller-manager (TAREA 3) \u2500\u2500');

check('el script existe', fs.existsSync(SCRIPT));
const src = fs.readFileSync(SCRIPT, 'utf8');

// ─── 1. Variable de estado presente y con transiciones ────────────────────
check('contiene la variable de estado ASSIGNED_STATE', src.includes('ASSIGNED_STATE'));
check('inicializa ASSIGNED_STATE antes del bucle', /ASSIGNED_STATE="unknown"/.test(src));
check('transición a estado empty', /ASSIGNED_STATE="empty"/.test(src));
check('transición a estado active', /ASSIGNED_STATE="active"/.test(src));

// ─── 2. El aviso incondicional ya no existe ───────────────────────────────
const OLD_LITERAL = '\u26a0 no hay dispositivos PRESENCE en la BD';
check('NO contiene el log incondicional antiguo', !src.includes(OLD_LITERAL));
check('el aviso honesto existe (push/disabled)',
  src.includes('ning\u00fan sensor requiere poller de nube (push/disabled)'));

// ─── 3. La rama -z "$ASSIGNED" está guardada por ASSIGNED_STATE ───────────
const marker = 'if [ -z "$ASSIGNED" ]; then';
const start = src.indexOf(marker);
const end = src.indexOf('# Start missing pollers', start);
const branch = (start >= 0 && end > start) ? src.slice(start, end) : '';
check('existe la rama `if [ -z "$ASSIGNED" ]`', start >= 0);
check('la rama vacía consulta ASSIGNED_STATE', branch.includes('ASSIGNED_STATE'));
check('la rama vacía NO loguea el literal antiguo', !branch.includes(OLD_LITERAL));
check('la rama vacía compara contra "empty"', /ASSIGNED_STATE"\s*!=\s*"empty"/.test(branch));

// ─── 4. Lógica de descubrimiento/reap/arranque intacta ────────────────────
check('conserva discover_sensors()', src.includes('discover_sensors()'));
check('conserva reap_removed()', src.includes('reap_removed()'));
check('conserva poller_pid_for()', src.includes('poller_pid_for()'));
check('conserva la exclusión push/disabled en SQL',
  src.includes("NOT IN ('push','disabled')"));
check('conserva el arranque con nohup node', /nohup env PRESENCE_DEVICE_ID=/.test(src));
check('conserva el sleep de reconciliación', src.includes('sleep "$RECONCILE_S"'));

console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' presence-poller-manager: ' + passed + ' passed, ' + failed + ' failed\n');
if (failed > 0) process.exit(1);
process.exit(0);
