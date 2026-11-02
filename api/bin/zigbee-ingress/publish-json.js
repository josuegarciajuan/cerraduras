#!/usr/bin/env node
'use strict';

/**
 * Publish one Zigbee2MQTT configuration command (F93/RF-156).
 *
 * Usage:
 *   node publish-json.js <topic>/set '{"radar_sensitivity":7}' [timeoutSeconds]
 *
 * Prints "OK" only after the broker PUBACK (QoS 1). Never claims that the
 * device applied the value: the confirmation arrives later by push and is
 * checked by ZigbeeRadarCalibrationService.
 *
 * Only an allowlist of radar parameters may be written. Credentials come from
 * api/.env or the process environment, never argv.
 */

const fs = require('fs');
const path = require('path');

const ALLOWED_KEYS = new Set([
  'radar_sensitivity',
  'minimum_range',
  'maximum_range',
  'detection_delay',
  'fading_time',
]);

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

function fail(message) {
  console.error(message);
  process.exit(1);
}

function readPassword() {
  const file = process.env.ZIGBEE_MQTT_PASSWORD_FILE || '';
  if (file !== '') {
    const resolved = path.isAbsolute(file) ? file : path.join(__dirname, '..', '..', file);
    if (!fs.existsSync(resolved)) fail('password_file_missing');
    return fs.readFileSync(resolved, 'utf8').trim();
  }
  return process.env.ZIGBEE_MQTT_PASSWORD || '';
}

const topic = process.argv[2] || '';
const payload = process.argv[3] || '';
const timeoutSeconds = Math.max(1, parseInt(process.argv[4] || process.env.ZIGBEE_SWITCH_TIMEOUT_SECONDS || '5', 10) || 5);

if (!/^[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*\/set$/.test(topic)
    || topic.toLowerCase().includes('-oficina')) {
  fail('invalid_topic');
}

let parsed;
try {
  parsed = JSON.parse(payload);
} catch (_) {
  fail('invalid_payload');
}
if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
  fail('invalid_payload_shape');
}
const keys = Object.keys(parsed);
if (keys.length === 0 || keys.some((k) => !ALLOWED_KEYS.has(k))) {
  fail('invalid_parameter');
}
for (const key of keys) {
  if (typeof parsed[key] !== 'number' || !Number.isFinite(parsed[key])) {
    fail('invalid_parameter_value');
  }
}

let mqtt;
try {
  mqtt = require('mqtt');
} catch (_) {
  fail('missing_dependency_mqtt');
}

const host = process.env.ZIGBEE_MQTT_HOST || '127.0.0.1';
const port = parseInt(process.env.ZIGBEE_MQTT_PORT || '1883', 10) || 1883;
const clientId = `cerraduras-zb-cal-${process.pid}-${Date.now()}`;
const timer = setTimeout(() => {
  console.error('publish_timeout');
  process.exit(2);
}, timeoutSeconds * 1000);

const client = mqtt.connect(`mqtt://${host}:${port}`, {
  clientId,
  username: process.env.ZIGBEE_MQTT_USERNAME || undefined,
  password: readPassword() || undefined,
  clean: true,
  reconnectPeriod: 0,
  connectTimeout: timeoutSeconds * 1000,
  protocolVersion: 5,
});

client.on('error', (err) => {
  clearTimeout(timer);
  console.error(`mqtt_error: ${err.message}`);
  try { client.end(true); } catch (_) { /* ignore */ }
  process.exit(1);
});

client.on('connect', () => {
  client.publish(topic, payload, { qos: 1 }, (err) => {
    clearTimeout(timer);
    if (err) {
      console.error(`publish_error: ${err.message}`);
      try { client.end(true); } catch (_) { /* ignore */ }
      process.exit(1);
    }
    console.log('OK');
    client.end(false, {}, () => process.exit(0));
  });
});
