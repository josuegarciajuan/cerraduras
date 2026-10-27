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
  atomicWrite(path.join(dir, 'incoming', name), serialize(record));
  return name;
}

function listFiles(dir, sub) {
  const target = path.join(dir, sub);
  if (!fs.existsSync(target)) return [];
  return fs.readdirSync(target)
    .filter((name) => name.endsWith('.json') && !name.startsWith('.'))
    .sort();
}

/**
 * Pending/capacity stats. Counts incoming + processing (work not done) and
 * quarantine separately. `totalFiles`/`totalBytes` include every subdir so
 * quarantine cannot fill the disk unnoticed.
 */
function stats(dir, now = new Date()) {
  const result = {
    pending_files: 0,
    pending_bytes: 0,
    processing_files: 0,
    quarantine_files: 0,
    total_files: 0,
    total_bytes: 0,
    oldest_pending_age_seconds: null,
  };
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

function canAccept(current, limits) {
  if (limits.maxFiles > 0 && current.pending_files >= limits.maxFiles) return false;
  if (limits.maxBytes > 0 && current.total_bytes >= limits.maxBytes) return false;
  if (limits.maxAgeSeconds > 0
      && current.oldest_pending_age_seconds !== null
      && current.oldest_pending_age_seconds >= limits.maxAgeSeconds) {
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
  persist,
  stats,
  canAccept,
};
