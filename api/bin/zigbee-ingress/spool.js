'use strict';

/**
 * Durable inbound spool for the Zigbee MQTT ingress (F91/RF-146).
 *
 * A record is written with tmp + fsync(file) + fsync(dir) + rename before the
 * MQTT QoS 1 message is acknowledged. This gives at-least-once delivery from
 * the broker to local disk. Domain idempotency handles replay.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const SUBDIRS = ['incoming', 'processing', 'processed', 'quarantine'];

function ensureLayout(dir) {
  for (const sub of SUBDIRS) {
    fs.mkdirSync(path.join(dir, sub), { recursive: true, mode: 0o700 });
  }
  try {
    fs.chmodSync(dir, 0o700);
  } catch (_) {
    // best effort; filesystem may not support chmod
  }
}

function sha256(buf) {
  return crypto.createHash('sha256').update(buf).digest('hex');
}

function recordName(receivedAt, seq, hash) {
  const ms = receivedAt instanceof Date ? receivedAt.getTime() : new Date(receivedAt).getTime();
  return `${String(ms).padStart(13, '0')}-${String(seq).padStart(8, '0')}-${hash.slice(0, 16)}.json`;
}

function fsyncDir(dir) {
  let fd = null;
  try {
    fd = fs.openSync(dir, 'r');
    fs.fsyncSync(fd);
  } catch (_) {
    // Directory fsync is not supported on every platform; the file fsync and
    // atomic rename still provide the best available guarantee.
  } finally {
    if (fd !== null) {
      try { fs.closeSync(fd); } catch (_) { /* ignore */ }
    }
  }
}

/** JSON.stringify with stable key order for audit reproducibility. */
function serialize(record) {
  return JSON.stringify(record, Object.keys(record).sort());
}

function atomicWrite(file, data) {
  const dir = path.dirname(file);
  const tmp = path.join(dir, `.tmp-${process.pid}-${Date.now()}-${Math.random().toString(16).slice(2)}`);
  let fd = null;
  try {
    fd = fs.openSync(tmp, 'wx', 0o600);
    fs.writeFileSync(fd, data);
    fs.fsyncSync(fd);
    fs.closeSync(fd);
    fd = null;
    fs.renameSync(tmp, file);
    fsyncDir(dir);
  } catch (err) {
    if (fd !== null) {
      try { fs.closeSync(fd); } catch (_) { /* ignore */ }
    }
    try { fs.unlinkSync(tmp); } catch (_) { /* ignore */ }
    throw err;
  }
}

function persist(dir, record, seq) {
  ensureLayout(dir);
  const name = recordName(record.received_at, seq, record.payload_sha256);
  const serialized = serialize(record);
  atomicWrite(path.join(dir, 'incoming', name), serialized);
  return { file: name, bytes: Buffer.byteLength(serialized, 'utf8') };
}

function listFiles(dir, sub) {
  const target = path.join(dir, sub);
  if (!fs.existsSync(target)) return [];
  return fs.readdirSync(target)
    .filter((name) => name.endsWith('.json') && !name.startsWith('.'))
    .sort();
}

function emptyStats() {
  return {
    pending_files: 0,
    pending_bytes: 0,
    processing_files: 0,
    quarantine_files: 0,
    total_files: 0,
    total_bytes: 0,
    oldest_pending_age_seconds: null,
  };
}

/**
 * F108/RF-239: estadísticas del trabajo pendiente. Solo recorre incoming,
 * processing y quarantine; nunca el histórico de processed. Es la ruta que se
 * usa en el camino crítico de cada mensaje.
 */
function pendingStats(dir, now = new Date()) {
  const result = emptyStats();
  if (!fs.existsSync(dir)) return result;

  for (const sub of ['incoming', 'processing', 'quarantine']) {
    for (const name of listFiles(dir, sub)) {
      const file = path.join(dir, sub, name);
      let st;
      try { st = fs.statSync(file); } catch (_) { continue; }
      if (sub === 'quarantine') {
        result.quarantine_files++;
        continue;
      }
      result.pending_files++;
      result.pending_bytes += st.size;
      if (sub === 'processing') result.processing_files++;
      if (sub === 'incoming') {
        const age = Math.max(0, Math.floor((now.getTime() - st.mtimeMs) / 1000));
        if (result.oldest_pending_age_seconds === null || age > result.oldest_pending_age_seconds) {
          result.oldest_pending_age_seconds = age;
        }
      }
    }
  }
  return result;
}

/**
 * Estadísticas completas. Recorre también processed; se usa para reconciliar
 * al arrancar o al reconectar, nunca por cada mensaje.
 */
function stats(dir, now = new Date()) {
  const result = emptyStats();
  if (!fs.existsSync(dir)) return result;

  for (const sub of SUBDIRS) {
    for (const name of listFiles(dir, sub)) {
      const file = path.join(dir, sub, name);
      let st;
      try { st = fs.statSync(file); } catch (_) { continue; }
      result.total_files++;
      result.total_bytes += st.size;
      if (sub === 'quarantine') result.quarantine_files++;
      if (sub === 'processing') {
        result.processing_files++;
        result.pending_files++;
        result.pending_bytes += st.size;
      }
      if (sub === 'incoming') {
        result.pending_files++;
        result.pending_bytes += st.size;
        const age = Math.max(0, Math.floor((now.getTime() - st.mtimeMs) / 1000));
        if (result.oldest_pending_age_seconds === null || age > result.oldest_pending_age_seconds) {
          result.oldest_pending_age_seconds = age;
        }
      }
    }
  }
  return result;
}

/** Espacio libre del sistema de ficheros que contiene dir. */
function diskFreeBytes(dir) {
  try {
    if (typeof fs.statfsSync !== 'function') return null;
    const st = fs.statfsSync(dir);
    return Number(st.bavail) * Number(st.bsize);
  } catch (_) {
    return null;
  }
}

function canAccept(current, limits) {
  if (limits.maxFiles > 0 && current.pending_files >= limits.maxFiles) return false;
  // F108/RF-239: maxBytes limita la cola pendiente, no el histórico ya
  // procesado. Así un spool histórico grande no bloquea la recepción.
  if (limits.maxBytes > 0 && current.pending_bytes >= limits.maxBytes) return false;
  if (limits.maxAgeSeconds > 0
      && current.oldest_pending_age_seconds !== null
      && current.oldest_pending_age_seconds >= limits.maxAgeSeconds) {
    return false;
  }
  if (limits.minFreeBytes > 0
      && current.disk_free_bytes !== null
      && current.disk_free_bytes < limits.minFreeBytes) {
    return false;
  }
  return true;
}

module.exports = {
  SUBDIRS,
  ensureLayout,
  sha256,
  recordName,
  atomicWrite,
  serialize,
  persist,
  pendingStats,
  stats,
  diskFreeBytes,
  canAccept,
};
