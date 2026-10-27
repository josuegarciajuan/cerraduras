#!/usr/bin/env node
'use strict';

/**
 * Zigbee MQTT ingress with ACK-after-persistence (F91/RF-146).
 *
 * Flow:
 *   Zigbee2MQTT -> Mosquitto (QoS 1/0) -> this process
 *     -> durable spool (fsync + atomic rename) -> ACK
 *   The PHP spool consumer later maps and delivers to the domain.
 *
 * Default is disabled (`ZIGBEE_INGRESS_ENABLED=false`). This process never
 * interprets device values, never writes to the DB and never talks to Tuya.
 * It does not poll: it only reacts to MQTT push messages.
 */

const fs = require('fs');
const path = require('path');
const { createIngress } = require('./ingress');

// Load api/.env without printing values.
(function loadEnv() {
  const envFile = path.join(__dirname, '..', '..', '.env');
  if (!fs.existsSync(envFile)) return;
  for (const line of fs.readFileSync(envFile, 'utf8').split('\n')) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const eq = trimmed.indexOf('=');
    if (eq <= 0) continue;
    const key = trimmed.slice(0, eq).trim();
    const value = trimmed.slice(eq + 1).trim().replace(/^["']|["']$/g, '');
    if (!(key in process.env)) process.env[key] = value;
  }
})();

function log(...args) {
  console.error(`[zigbee-ingress ${new Date().toISOString()}]`, ...args);
}

function envBool(name, fallback = false) {
  const raw = process.env[name];
  if (raw === undefined || raw === '') return fallback;
  return ['1', 'true', 'yes', 'on'].includes(String(raw).toLowerCase());
}

function envInt(name, fallback) {
  const n = parseInt(process.env[name] || '', 10);
  return Number.isFinite(n) ? n : fallback;
}

function resolvePath(value) {
  if (!value) return '';
  return path.isAbsolute(value) ? value : path.join(__dirname, '..', '..', value);
}

function readPassword() {
  const file = process.env.ZIGBEE_MQTT_PASSWORD_FILE || '';
  if (file !== '') {
    const resolved = resolvePath(file);
    if (!fs.existsSync(resolved)) {
      throw new Error(`ZIGBEE_MQTT_PASSWORD_FILE not found: ${resolved}`);
    }
    return fs.readFileSync(resolved, 'utf8').trim();
  }
  return process.env.ZIGBEE_MQTT_PASSWORD || '';
}

function writeStatus(statusFile, payload) {
  const dir = path.dirname(statusFile);
  fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
  const tmp = path.join(dir, `.zigbee-ingress-status-${process.pid}-${Date.now()}.tmp`);
  fs.writeFileSync(tmp, JSON.stringify(payload, null, 2), { mode: 0o600 });
  fs.renameSync(tmp, statusFile);
}

function main() {
  if (!envBool('ZIGBEE_INGRESS_ENABLED', false)) {
    log('disabled (ZIGBEE_INGRESS_ENABLED is not true); nothing started');
    process.exit(0);
  }

  const host = process.env.ZIGBEE_MQTT_HOST || '127.0.0.1';
  const port = envInt('ZIGBEE_MQTT_PORT', 1883);
  const prefix = process.env.ZIGBEE_MQTT_TOPIC_PREFIX || 'zigbee2mqtt';
  const clientId = process.env.ZIGBEE_MQTT_CLIENT_ID || 'cerraduras-zigbee-ingress';
  const spoolDir = resolvePath(process.env.ZIGBEE_SPOOL_DIR || 'run/zigbee-spool');
  const statusFile = resolvePath(process.env.ZIGBEE_INGRESS_STATUS_FILE || 'run/zigbee-ingress-status.json');
  const maxFiles = envInt('ZIGBEE_SPOOL_MAX_FILES', 10000);
  const maxBytes = envInt('ZIGBEE_SPOOL_MAX_BYTES', 268435456);
  const maxAgeSeconds = envInt('ZIGBEE_SPOOL_MAX_AGE_SECONDS', 86400);

  let password;
  try {
    password = readPassword();
  } catch (err) {
    log(err.message);
    process.exit(1);
  }

  let connected = false;
  let lastError = null;
  let lastEvent = 'starting';
  let client = null;

  const ingress = createIngress({
    spoolDir,
    maxFiles,
    maxBytes,
    maxAgeSeconds,
    log,
    onStatus(stats) {
      const payload = {
        enabled: true,
        connected,
        broker: `${host}:${port}`,
        topic_prefix: prefix,
        client_id: clientId,
        spool_dir: spoolDir,
        ...stats,
        last_error: lastError,
        event: lastEvent,
      };
      try {
        writeStatus(statusFile, payload);
      } catch (err) {
        log(`status write failed: ${err.message}`);
      }
    },
  });

  let mqtt;
  try {
    mqtt = require('mqtt');
  } catch (err) {
    log('missing dependency "mqtt"; run npm install in api/bin/zigbee-ingress');
    process.exit(1);
  }

  const url = `mqtt://${host}:${port}`;
  client = mqtt.connect(url, {
    clientId,
    username: process.env.ZIGBEE_MQTT_USERNAME || undefined,
    password: password || undefined,
    protocolVersion: 5,
    clean: false,
    reconnectPeriod: 1000,
    connectTimeout: 10000,
    properties: { sessionExpiryInterval: 3600 },
    // MQTT 5 hook: mqtt.js only sends PUBACK/PUBREC after this callback.
    // Persist first; call the callback only when the spool write succeeded.
    customHandleAcks(topic, message, packet, callback) {
      if (packet.qos === 0) {
        callback(null, 0);
        return;
      }
      ingress.handle(topic, message, packet, (err) => {
        if (err) {
          callback(err, 0);
          return;
        }
        callback(null, 0);
      });
    },
  });

  client.on('connect', () => {
    connected = true;
    lastError = null;
    lastEvent = 'connected';
    log(`connected to ${url}`);
    client.subscribe(`${prefix}/#`, { qos: 1 }, (err) => {
      if (err) {
        lastError = 'subscribe_failed';
        log(`subscribe failed: ${err.message}`);
      } else {
        log(`subscribed to ${prefix}/#`);
      }
      ingress.stats();
    });
  });

  client.on('reconnect', () => {
    connected = false;
    lastEvent = 'reconnecting';
    ingress.stats();
  });

  client.on('offline', () => {
    connected = false;
    lastEvent = 'offline';
    ingress.stats();
  });

  client.on('close', () => {
    connected = false;
    lastEvent = 'closed';
    ingress.stats();
  });

  client.on('error', (err) => {
    connected = false;
    lastError = 'mqtt_error';
    lastEvent = 'error';
    log(`mqtt error: ${err.message}`);
    ingress.stats();
  });

  // QoS 0 has no ACK; QoS 1/2 are handled by customHandleAcks above. This
  // listener only covers QoS 0 so a QoS 1 message is never persisted twice.
  client.on('message', (topic, payload, packet) => {
    if (packet && packet.qos === 0) {
      ingress.handle(topic, payload, packet, () => {});
    }
  });

  function shutdown(signal) {
    log(`${signal} received; closing MQTT client`);
    lastEvent = 'shutdown';
    connected = false;
    try { ingress.stats(); } catch (_) { /* ignore */ }
    if (client && typeof client.end === 'function') {
      client.end(false, {}, () => process.exit(0));
      setTimeout(() => process.exit(0), 5000).unref();
    } else {
      process.exit(0);
    }
  }

  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('SIGINT', () => shutdown('SIGINT'));

  // Initial status file before the first message.
  ingress.stats();
}

if (require.main === module) {
  main();
}

module.exports = { main };
