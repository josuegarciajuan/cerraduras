#!/usr/bin/env node
'use strict';

/**
 * Unit tests for F91/TSK-91.05 — MQTT ingress with ACK after persistence.
 *
 * No broker, network, DB, Tuya or hardware. Temporary spool directories only.
 *
 * Run: node api/tests/Unit/zigbee-ingress.test.js
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { createIngress } = require(path.join(__dirname, '..', '..', 'bin', 'zigbee-ingress', 'ingress.js'));
const spool = require(path.join(__dirname, '..', '..', 'bin', 'zigbee-ingress', 'spool.js'));

let passed = 0;
let failed = 0;
function check(name, cond, detail = '') {
  if (cond) {
    passed++;
    console.log('  \u2705 ' + name);
  } else {
    failed++;
    console.error('  \u274c ' + name + (detail ? ' — ' + detail : ''));
  }
}

function tmpDir() {
  return fs.mkdtempSync(path.join(os.tmpdir(), 'zigbee-ingress-test-'));
}

function listIncoming(dir) {
  const p = path.join(dir, 'incoming');
  if (!fs.existsSync(p)) return [];
  return fs.readdirSync(p).filter((n) => n.endsWith('.json'));
}

function readRecord(dir) {
  const files = listIncoming(dir);
  if (files.length === 0) return null;
  return JSON.parse(fs.readFileSync(path.join(dir, 'incoming', files[0]), 'utf8'));
}

console.log('\n\u2500\u2500 zigbee-ingress (F91/TSK-91.05) \u2500\u2500');

// 1. Persist before ACK
{
  const dir = tmpDir();
  const ingress = createIngress({ spoolDir: dir });
  let acked = false;
  let filesAtAck = 0;
  const payload = Buffer.from('{"contact":true}');
  const res = ingress.handle(
    'zigbee2mqtt/almacen-zb-puerta',
    payload,
    { qos: 1, retain: false, dup: false },
    () => {
      acked = true;
      filesAtAck = listIncoming(dir).length;
    }
  );
  const rec = readRecord(dir);
  check('persiste antes de ACK', res.stored === true && acked === true && filesAtAck === 1);
  check('registro guarda topic y payload', rec && rec.topic === 'zigbee2mqtt/almacen-zb-puerta' && rec.payload === '{"contact":true}');
  check('registro guarda hash SHA-256', rec && rec.payload_sha256 === crypto.createHash('sha256').update(payload).digest('hex'));
  check('registro guarda received_at ISO', rec && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(rec.received_at));
  check('registro guarda qos/dup', rec && rec.qos === 1 && rec.dup === false);
  check('no deja temporales', fs.readdirSync(path.join(dir, 'incoming')).every((n) => !n.startsWith('.tmp-')));
  const st = ingress.stats();
  check('contadores received/persisted/acked', st.received === 1 && st.persisted === 1 && st.acked === 1);
  fs.rmSync(dir, { recursive: true, force: true });
}

// 2. Retained message is marked bootstrap
{
  const dir = tmpDir();
  const ingress = createIngress({ spoolDir: dir });
  ingress.handle('zigbee2mqtt/almacen-zb-luz', Buffer.from('{"state":"OFF"}'), { qos: 1, retain: true }, () => {});
  const rec = readRecord(dir);
  check('retain=true → bootstrap=true', rec && rec.bootstrap === true);
  fs.rmSync(dir, { recursive: true, force: true });
}

// 3. Invalid payloads are ACKed and not spooled
{
  const dir = tmpDir();
  const ingress = createIngress({ spoolDir: dir });
  let acks = 0;
  const big = Buffer.alloc(64 * 1024 + 1, 0x61);
  ingress.handle('zigbee2mqtt/x', big, { qos: 1 }, () => { acks++; });
  ingress.handle('zigbee2mqtt/x', Buffer.alloc(0), { qos: 1 }, () => { acks++; });
  check('payload >64KiB y vacío se ACKed', acks === 2);
  check('payload inválido no ocupa spool', listIncoming(dir).length === 0);
  const st = ingress.stats();
  check('payload inválido contabilizado', st.rejected === 2 && st.persisted === 0);
  fs.rmSync(dir, { recursive: true, force: true });
}

// 4. Capacity backpressure does not ACK
{
  const dir = tmpDir();
  const ingress = createIngress({ spoolDir: dir, maxFiles: 1 });
  spool.persist(dir, {
    schema: 1,
    topic: 'zigbee2mqtt/pending',
    payload: '{}',
    payload_sha256: 'a'.repeat(64),
    qos: 1,
    dup: false,
    received_at: new Date().toISOString(),
    attempts: 0,
    bootstrap: false,
  }, 0);
  let ack = false;
  const res = ingress.handle('zigbee2mqtt/almacen-zb-puerta', Buffer.from('{}'), { qos: 1 }, () => { ack = true; });
  check('spool lleno → no ACK', res.stored === false && res.reason === 'backpressure' && ack === false);
  check('spool lleno no añade registro', listIncoming(dir).length === 1);
  check('backpressure contabilizado', ingress.stats().backpressure === 1);
  fs.rmSync(dir, { recursive: true, force: true });
}

// 5. Write failure does not ACK
{
  const dir = tmpDir();
  const asFile = path.join(dir, 'not-a-dir');
  fs.writeFileSync(asFile, 'x');
  const ingress = createIngress({ spoolDir: asFile });
  let ack = false;
  const res = ingress.handle('zigbee2mqtt/almacen-zb-puerta', Buffer.from('{}'), { qos: 1 }, () => { ack = true; });
  check('fallo de disco → no ACK', res.stored === false && res.reason === 'write_error' && ack === false);
  check('fallo de disco contabilizado', ingress.stats().write_errors === 1);
  fs.rmSync(dir, { recursive: true, force: true });
}

// 6. Stats and age limit
{
  const dir = tmpDir();
  spool.persist(dir, {
    schema: 1,
    topic: 'zigbee2mqtt/old',
    payload: '{}',
    payload_sha256: 'b'.repeat(64),
    qos: 1,
    dup: false,
    received_at: new Date().toISOString(),
    attempts: 0,
    bootstrap: false,
  }, 0);
  const file = path.join(dir, 'incoming', listIncoming(dir)[0]);
  const old = new Date(Date.now() - 120000);
  fs.utimesSync(file, old, old);
  const st = spool.stats(dir);
  check('stats cuenta pendientes', st.pending_files === 1 && st.total_files === 1);
  check('stats calcula antigüedad', st.oldest_pending_age_seconds >= 119);
  check('canAccept respeta maxAgeSeconds', spool.canAccept(st, { maxFiles: 100, maxBytes: 1000000, maxAgeSeconds: 60 }) === false);
  check('canAccept acepta dentro de límites', spool.canAccept(st, { maxFiles: 100, maxBytes: 1000000, maxAgeSeconds: 3600 }) === true);
  fs.rmSync(dir, { recursive: true, force: true });
}

// 7. F108: pendingStats no recorre processed
{
  const dir = tmpDir();
  spool.ensureLayout(dir);
  for (let i = 0; i < 5; i++) {
    fs.writeFileSync(path.join(dir, 'processed', `old-${i}.json`), '{}');
  }
  spool.persist(dir, {
    schema: 1, topic: 'zigbee2mqtt/new', payload: '{}', payload_sha256: 'c'.repeat(64),
    qos: 1, dup: false, received_at: new Date().toISOString(), attempts: 0, bootstrap: false,
  }, 0);
  const pending = spool.pendingStats(dir);
  const full = spool.stats(dir);
  check('F108: pendingStats solo cuenta pendientes', pending.pending_files === 1);
  check('F108: pendingStats ignora processed', pending.total_files === 0);
  check('F108: stats completo conserva el histórico', full.total_files === 6 && full.pending_files === 1);
  fs.rmSync(dir, { recursive: true, force: true });
}

// 8. F108: maxBytes limita la cola pendiente, no el histórico
{
  const current = {
    pending_files: 1, pending_bytes: 10, processing_files: 0, quarantine_files: 0,
    total_files: 99999, total_bytes: 999999999, oldest_pending_age_seconds: 1,
    disk_free_bytes: 1000000000,
  };
  check('F108: histórico grande no dispara backpressure',
    spool.canAccept(current, { maxFiles: 100, maxBytes: 1000, maxAgeSeconds: 60, minFreeBytes: 0 }) === true);
  check('F108: cola pendiente sí dispara backpressure',
    spool.canAccept({ ...current, pending_bytes: 2000 }, { maxFiles: 100, maxBytes: 1000, maxAgeSeconds: 60, minFreeBytes: 0 }) === false);
  check('F108: poco disco dispara backpressure',
    spool.canAccept({ ...current, disk_free_bytes: 10 }, { maxFiles: 100, maxBytes: 1000, maxAgeSeconds: 60, minFreeBytes: 100 }) === false);
}

// 9. F108: filtro de topics seguros
{
  const dir = tmpDir();
  const ingress = createIngress({
    spoolDir: dir,
    topicFilter: (topic) => !topic.endsWith('/bridge/logging'),
  });
  let ack = false;
  const res = ingress.handle('zigbee2mqtt/bridge/logging', Buffer.from('{}'), { qos: 1 }, () => { ack = true; });
  check('F108: topic filtrado se ACKea', ack === true && res.stored === false && res.reason === 'filtered');
  check('F108: topic filtrado no ocupa spool', listIncoming(dir).length === 0);
  check('F108: filtrado contabilizado', ingress.stats().filtered === 1);
  fs.rmSync(dir, { recursive: true, force: true });
}

// 10. F108: reconciliación y métricas
{
  const dir = tmpDir();
  const ingress = createIngress({ spoolDir: dir });
  ingress.handle('zigbee2mqtt/almacen-zb-puerta', Buffer.from('{}'), { qos: 1 }, () => {});
  const before = ingress.stats();
  check('F108: latencia de persistencia medida', before.last_persist_latency_ms !== null);
  check('F108: modo de estadísticas pendientes', before.stats_mode === 'pending');
  spool.persist(dir, {
    schema: 1, topic: 'zigbee2mqtt/external', payload: '{}', payload_sha256: 'd'.repeat(64),
    qos: 1, dup: false, received_at: new Date().toISOString(), attempts: 0, bootstrap: false,
  }, 1);
  ingress.reconcile();
  const after = ingress.stats();
  check('F108: reconcile actualiza totales', after.total_files >= 2);
  check('F108: pendingStats no cuenta processed', after.pending_files === 2);
  fs.rmSync(dir, { recursive: true, force: true });
}

// 11. Entrypoint safety invariants
{
  const index = fs.readFileSync(path.join(__dirname, '..', '..', 'bin', 'zigbee-ingress', 'index.js'), 'utf8');
  const pkg = JSON.parse(fs.readFileSync(path.join(__dirname, '..', '..', 'bin', 'zigbee-ingress', 'package.json'), 'utf8'));
  check('entrypoint usa ACK manual MQTT5 (customHandleAcks)', index.includes('customHandleAcks') && index.includes('protocolVersion: 5'));
  check('entrypoint no usa el manualAcks eliminado', !index.includes('manualAcks'));
  check('entrypoint no hace polling', !index.includes('setInterval'));
  check('entrypoint configura reconexión', index.includes('reconnectPeriod: 1000'));
  check('credenciales fuera de argv', !index.includes('process.argv'));
  check('entrypoint no llama a Tuya', !/TUYA_/.test(index) && !/require\(['"]tuya/i.test(index));
  check('dependencia mqtt declarada', pkg.dependencies && typeof pkg.dependencies.mqtt === 'string');
  check('ingreso deshabilitado por defecto', index.includes("envBool('ZIGBEE_INGRESS_ENABLED', false)"));
}

console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' zigbee-ingress: ' + passed + ' passed, ' + failed + ' failed\n');
process.exit(failed === 0 ? 0 : 1);
