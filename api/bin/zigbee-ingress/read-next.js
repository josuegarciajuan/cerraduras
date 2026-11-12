#!/usr/bin/env node
'use strict';

/**
 * F125.2 — lee el siguiente estado de un dispositivo Zigbee2MQTT.
 *
 * Uso:
 *   node read-next.js <topic-de-estado> [timeoutSegundos]
 *
 * Se suscribe al topic indicado y devuelve el primer mensaje recibido como
 * JSON. No publica nada: es una lectura de solo observación. El topic de
 * estado de Zigbee2MQTT no es retenido, así que si el dispositivo está en
 * silencio se agota el timeout.
 *
 * Credenciales: api/.env o el entorno del perfil MQTT del dispositivo.
 */

const fs = require('fs');
const path = require('path');

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

function fail(message, code = 1) {
  console.error(message);
  process.exit(code);
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
const timeoutSeconds = Math.max(1, Math.min(15, parseInt(process.argv[3] || '5', 10) || 5));

if (!/^[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*$/.test(topic)
    || topic.toLowerCase().includes('-oficina')
    || topic.endsWith('/set')
    || topic.endsWith('/get')) {
  fail('invalid_topic');
}

let mqtt;
try {
  mqtt = require('mqtt');
} catch (_) {
  fail('missing_dependency_mqtt');
}

const host = process.env.ZIGBEE_MQTT_HOST || '127.0.0.1';
const port = parseInt(process.env.ZIGBEE_MQTT_PORT || '1883', 10) || 1883;
const clientId = `cerraduras-zb-read-next-${process.pid}-${Date.now()}`;

const client = mqtt.connect(`mqtt://${host}:${port}`, {
  clientId,
  username: process.env.ZIGBEE_MQTT_USERNAME || undefined,
  password: readPassword() || undefined,
  clean: true,
  reconnectPeriod: 0,
  connectTimeout: timeoutSeconds * 1000,
  protocolVersion: 5,
});

const timer = setTimeout(() => {
  console.error('read_timeout');
  try { client.end(true); } catch (_) { /* ignore */ }
  process.exit(2);
}, timeoutSeconds * 1000);

client.on('error', (err) => {
  clearTimeout(timer);
  console.error(`mqtt_error: ${err.message}`);
  try { client.end(true); } catch (_) { /* ignore */ }
  process.exit(1);
});

client.on('connect', () => {
  client.subscribe(topic, { qos: 0 }, (err) => {
    if (err) {
      clearTimeout(timer);
      console.error(`subscribe_error: ${err.message}`);
      try { client.end(true); } catch (_) { /* ignore */ }
      process.exit(1);
    }
  });
});

let done = false;
client.on('message', (receivedTopic, payload) => {
  if (done) return;
  done = true;
  clearTimeout(timer);
  let decoded = null;
  try {
    decoded = JSON.parse(payload.toString());
  } catch (_) {
    decoded = null;
  }
  const output = {
    topic: receivedTopic,
    received_at: new Date().toISOString(),
    payload: decoded === null ? { raw: payload.toString().slice(0, 4096) } : decoded,
  };
  process.stdout.write(JSON.stringify(output) + '\n', () => {
    try { client.end(true); } catch (_) { /* ignore */ }
    process.exit(0);
  });
});
