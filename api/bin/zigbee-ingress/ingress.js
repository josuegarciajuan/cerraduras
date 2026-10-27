'use strict';

/**
 * Pure-ish ingress handler (F91/RF-146).
 *
 * The caller supplies the ACK function. This module persists first and only
 * then calls ACK. It never interprets the device payload or talks to the DB,
 * Tuya or the domain.
 */

const spool = require('./spool');

const MAX_PAYLOAD_BYTES = 64 * 1024;

function nowIso(date = new Date()) {
  return date.toISOString();
}

/**
 * @param {object} options
 * @param {string} options.spoolDir absolute path
 * @param {number} [options.maxFiles]
 * @param {number} [options.maxBytes]
 * @param {number} [options.maxAgeSeconds]
 * @param {() => Date} [options.clock]
 * @param {(line: string) => void} [options.log]
 * @param {(stats: object) => void} [options.onStatus]
 */
function createIngress(options) {
  if (!options || typeof options.spoolDir !== 'string' || options.spoolDir === '') {
    throw new Error('spoolDir is required');
  }
  const spoolDir = options.spoolDir;
  const limits = {
    maxFiles: Number.isFinite(options.maxFiles) ? options.maxFiles : 10000,
    maxBytes: Number.isFinite(options.maxBytes) ? options.maxBytes : 268435456,
    maxAgeSeconds: Number.isFinite(options.maxAgeSeconds) ? options.maxAgeSeconds : 86400,
  };
  const clock = typeof options.clock === 'function' ? options.clock : () => new Date();
  const log = typeof options.log === 'function' ? options.log : () => {};
  const onStatus = typeof options.onStatus === 'function' ? options.onStatus : () => {};

  let seq = 0;
  const counters = {
    received: 0,
    persisted: 0,
    acked: 0,
    rejected: 0,
    backpressure: 0,
    write_errors: 0,
    last_received_at: null,
    last_persisted_at: null,
    last_ack_at: null,
    last_error: null,
  };

  function snapshot() {
    const current = spool.stats(spoolDir, clock());
    return {
      ...counters,
      pending_files: current.pending_files,
      pending_bytes: current.pending_bytes,
      processing_files: current.processing_files,
      quarantine_files: current.quarantine_files,
      total_files: current.total_files,
      total_bytes: current.total_bytes,
      oldest_pending_age_seconds: current.oldest_pending_age_seconds,
      updated_at: nowIso(clock()),
    };
  }

  function emit() {
    onStatus(snapshot());
  }

  /**
   * Persist one MQTT message and ACK after it is durable.
   *
   * @param {string} topic
   * @param {Buffer|string} payload
   * @param {object} packet mqtt packet (retain/dup/qos)
   * @param {(err?: Error|null) => void} ack ACK callback; MUST be called at most once
   * @returns {{stored:boolean,reason?:string,file?:string}}
   */
  function handle(topic, payload, packet, ack) {
    const receivedAt = clock();
    const ackOnce = typeof ack === 'function' ? ack : () => {};
    const buf = Buffer.isBuffer(payload) ? payload : Buffer.from(String(payload || ''), 'utf8');
    counters.received++;
    counters.last_received_at = nowIso(receivedAt);

    if (typeof topic !== 'string' || topic === '') {
      counters.rejected++;
      counters.last_error = 'empty_topic';
      log('[zigbee-ingress] rejected empty topic');
      ackOnce(null);
      counters.acked++;
      emit();
      return { stored: false, reason: 'empty_topic' };
    }
    if (buf.length === 0 || buf.length > MAX_PAYLOAD_BYTES) {
      counters.rejected++;
      counters.last_error = buf.length === 0 ? 'empty_payload' : 'payload_too_large';
      log(`[zigbee-ingress] rejected ${topic} bytes=${buf.length}`);
      // ACK invalid messages: a redelivery loop cannot fix a malformed payload.
      ackOnce(null);
      counters.acked++;
      emit();
      return { stored: false, reason: counters.last_error };
    }

    const current = spool.stats(spoolDir, receivedAt);
    if (!spool.canAccept(current, limits)) {
      counters.backpressure++;
      counters.last_error = 'backpressure';
      log(`[zigbee-ingress] backpressure pending=${current.pending_files} bytes=${current.total_bytes}`);
      // Do not ACK: the broker keeps the QoS 1 message for redelivery.
      emit();
      return { stored: false, reason: 'backpressure' };
    }

    const hash = spool.sha256(buf);
    const record = {
      schema: 1,
      topic,
      payload: buf.toString('utf8'),
      payload_sha256: hash,
      qos: packet && Number.isInteger(packet.qos) ? packet.qos : 0,
      dup: !!(packet && packet.dup),
      received_at: nowIso(receivedAt),
      attempts: 0,
      bootstrap: !!(packet && packet.retain),
    };

    try {
      const file = spool.persist(spoolDir, record, seq++);
      counters.persisted++;
      counters.last_persisted_at = record.received_at;
      // Persistence is complete: now it is safe to ACK the broker.
      try {
        ackOnce(null);
        counters.acked++;
        counters.last_ack_at = nowIso(clock());
      } catch (ackErr) {
        counters.last_error = 'ack_failed';
        log(`[zigbee-ingress] ACK failed for ${file}: ${ackErr.message}`);
      }
      emit();
      return { stored: true, file };
    } catch (err) {
      counters.write_errors++;
      counters.last_error = 'write_error';
      log(`[zigbee-ingress] spool write failed: ${err.message}`);
      // Do not ACK: the message must be retried by the broker.
      emit();
      return { stored: false, reason: 'write_error' };
    }
  }

  return { handle, stats: snapshot, limits, spoolDir };
}

module.exports = { createIngress, MAX_PAYLOAD_BYTES };
