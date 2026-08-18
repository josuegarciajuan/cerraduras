#!/usr/bin/env node
/**
 * tuya-presence-listen — Tuya HTTP API poller for ZY-M100-5 presence sensor.
 *
 * Polls the Tuya Cloud API every 1.5s and displays presence state changes
 * in real-time with colored output.
 *
 * Pulsar WS was tested but the topic delivered zero messages — HTTP API
 * polling is the reliable fallback that works.
 *
 * Usage:
 *   node /root/cerraduras/api/bin/tuya-presence-listen.js
 *
 * Ctrl+C to exit.
 */

// ─── Configuration ─────────────────────────────────────────────────────
// Load .env into process.env
(function() {
  const fs = require('fs');
  const path = require('path');
  const envFile = path.join(__dirname, '..', '.env');
  if (fs.existsSync(envFile)) {
    fs.readFileSync(envFile, 'utf8').split('\n').forEach(function(line) {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith('#')) return;
      const eq = trimmed.indexOf('=');
      if (eq > 0) process.env[trimmed.slice(0, eq).trim()] = trimmed.slice(eq + 1).trim().replace(/^["']|["']$/g, '');
    });
  }
})();

const ACCESS_ID    = process.env.TUYA_ACCESS_ID || '';
const ACCESS_SECRET = process.env.TUYA_ACCESS_SECRET || '';
const BASE_URL     = process.env.TUYA_BASE_URL || 'https://openapi.tuyaeu.com';
const DEVICE_ID    = 'bf98d27d79685e38a2wbda'; // ZY-M100-5
const POLL_MS      = 1500; // polling interval

// ─── Dependencies ─────────────────────────────────────────────────────
const crypto  = require('crypto');
const https   = require('https');

// ─── Colors ────────────────────────────────────────────────────────────
const C = { r:'\x1b[0m', red:'\x1b[31m', green:'\x1b[32m', yellow:'\x1b[33m', cyan:'\x1b[36m', dim:'\x1b[2m', bold:'\x1b[1m' };

// ─── State ─────────────────────────────────────────────────────────────
let accessToken  = null;
let tokenExpiry  = 0;
let lastPresence = null;
let lastDistance = null;
let lastSens     = null;
let lastFar      = null;
let seq          = 0;
let startTime    = Date.now();
let errorStreak  = 0;

// ─── Tuya HMAC-SHA256 signing ─────────────────────────────────────────
function tuyaSign(method, pathWithQuery, body, token) {
  // Tuya signing: path WITHOUT query string, but query params ARE used in strToSign
  const [path, queryString] = pathWithQuery.split('?');
  const contentSha = crypto.createHash('sha256').update(body).digest('hex');
  let strToSign = `${method}\n${contentSha}\n\n${path}`;
  // Parse and sort query params
  if (queryString) {
    const params = new URLSearchParams(queryString);
    const sorted = [...params.entries()].sort((a, b) => a[0].localeCompare(b[0]));
    const qs = sorted.map(([k, v]) => `${k}=${v}`).join('&');
    strToSign += '?' + qs;
  }
  const t = String(Date.now());
  const message = ACCESS_ID + token + t + strToSign;
  const sign = crypto.createHmac('sha256', ACCESS_SECRET).update(message).digest('hex').toUpperCase();
  return { sign, t, contentSha };
}

// ─── Tuya HTTP request ─────────────────────────────────────────────────
function tuyaRequest(method, pathWithQuery, body, token) {
  return new Promise((resolve, reject) => {
    const { sign, t, contentSha } = tuyaSign(method, pathWithQuery, body, token || '');

    const headers = {
      'client_id':       ACCESS_ID,
      'sign':            sign,
      'sign_method':     'HMAC-SHA256',
      't':               t,
      'Content-Type':    'application/json',
      'Content-SHA256':  contentSha,
    };
    if (token) headers['access_token'] = token;

    const options = {
      hostname: 'openapi.tuyaeu.com',
      port:     443,
      path:     pathWithQuery,
      method:   method,
      headers,
      timeout:  8000,
    };

    const start = Date.now();
    const req = https.request(options, (res) => {
      let data = '';
      res.on('data',  chunk => data += chunk);
      res.on('end', () => {
        const elapsed = Date.now() - start;
        try {
          resolve({ httpCode: res.statusCode, body: JSON.parse(data), elapsed });
        } catch (e) {
          resolve({ httpCode: res.statusCode, body: { raw: data }, elapsed, error: e.message });
        }
      });
    });
    req.on('timeout', () => { req.destroy(); reject(new Error('timeout')); });
    req.on('error',   err => reject(err));
    if (method === 'POST' && body) req.write(body);
    req.end();
  });
}

// ─── Token management ──────────────────────────────────────────────────
async function getToken() {
  if (accessToken && Date.now() < tokenExpiry) return accessToken;
  const { httpCode, body } = await tuyaRequest('GET', '/v1.0/token?grant_type=1', '', '');
  if (httpCode !== 200 || !body.success) throw new Error(`Token fail: ${body.msg}`);
  accessToken = body.result.access_token;
  tokenExpiry = Date.now() + (body.result.expire_time * 1000) - 60000;
  return accessToken;
}

// ─── Poll device status ────────────────────────────────────────────────
async function getDeviceStatus() {
  const token = await getToken();
  return tuyaRequest('GET', `/v1.0/iot-03/devices/${DEVICE_ID}/status`, '', token);
}

// ─── Send DP command ───────────────────────────────────────────────────
async function sendCommand(code, value) {
  const token = await getToken();
  const body = JSON.stringify({ commands: [{ code, value }] });
  return tuyaRequest('POST', `/v1.0/iot-03/devices/${DEVICE_ID}/commands`, body, token);
}

// ─── Timestamp ─────────────────────────────────────────────────────────
function ts() {
  return new Date().toISOString().replace('T',' ').substring(0,19);
}

// ─── Display ───────────────────────────────────────────────────────────
function display(state) {
  const presence  = state.presence_state || '';
  const distance  = state.target_dis_closest;
  const sens      = state.sensitivity;
  const farDet    = state.far_detection;
  const check     = state.checking_result || '';
  const now       = ts();

  const changed   = (presence !== lastPresence);
  const heartbeat = (seq > 0 && seq % 20 === 0 && !changed);

  if (!changed && !heartbeat) {
    lastPresence = presence;
    lastDistance = distance;
    lastSens     = sens;
    lastFar      = farDet;
    return;
  }

  let line = C.dim + `[${now}]` + C.r;

  if (presence === 'presence') {
    line += '  ' + C.green + C.bold + '🧑 PRESENTE' + C.r;
    if (distance !== null && distance !== undefined) line += '  📏 ' + C.cyan + distance + 'cm' + C.r;
  } else if (presence === 'none') {
    line += '  ' + C.red + '🚫 AUSENTE' + C.r;
  } else {
    line += '  ' + C.yellow + '⚠ état=' + presence + C.r;
  }

  if (sens !== null)   line += '  ' + C.dim + 'sens:' + sens + '/9' + C.r;
  if (farDet !== null) line += '  ' + C.dim + 'rango:' + (farDet/100).toFixed(1) + 'm' + C.r;
  if (check && check !== 'check_success') line += '  ' + C.yellow + '⚡' + check + C.r;

  if (changed && lastPresence !== null) {
    const prior = lastPresence === 'presence' ? (C.green + 'PRESENTE' + C.r) : (C.red + 'AUSENTE' + C.r);
    const arrow = presence === 'presence' ? (C.green + '→ PRESENTE' + C.r) : (C.red + '→ AUSENTE' + C.r);
    line += '  ' + C.dim + '(era ' + prior + ')' + C.r + ' ' + arrow;
  } else if (heartbeat) {
    const running = Math.round((Date.now() - startTime) / 1000);
    line += '  ' + C.dim + '(sin cambios — ' + running + 's)' + C.r;
  }

  console.log(line);

  lastPresence = presence;
  lastDistance = distance;
  lastSens     = sens;
  lastFar      = farDet;
}

// ─── Main loop ─────────────────────────────────────────────────────────
async function main() {
  console.log(C.bold + '═══ ZY-M100-5 Presence Listener (HTTP API poll) ═══' + C.r);
  console.log(`  Device : ${DEVICE_ID}`);
  console.log(`  Poll   : ${POLL_MS}ms`);
  console.log('  Ctrl+C para salir\n');

  // Get initial token
  try {
    await getToken();
    console.log(C.green + '✅ Conectado a Tuya Cloud' + C.r);
  } catch (e) {
    console.error(C.red + `❌ ${e.message}` + C.r);
    process.exit(1);
  }

  // First read
  try {
    const { httpCode, body } = await getDeviceStatus();
    if (httpCode === 200 && body.success) {
      const dps = body.result || [];
      const state = {};
      for (const dp of dps) state[dp.code] = dp.value;
      lastPresence = state.presence_state || '';
      lastDistance = state.target_dis_closest;
      lastSens     = state.sensitivity;
      lastFar      = state.far_detection;
      const present = (lastPresence === 'presence');
      const label = present ? C.green + '🧑 PRESENTE' + C.r : C.red + '🚫 AUSENTE' + C.r;
      console.log(C.dim + `ESTADO INICIAL:` + C.r + ' ' + C.bold + label + C.r +
        (lastDistance ? ('  📏 ' + C.cyan + lastDistance + 'cm' + C.r) : '') +
        (lastFar ? ('  rango:' + (lastFar/100).toFixed(1) + 'm') : '') + '\n');
    }
  } catch (e) {
    // continue to loop
  }

  // Poll loop
  while (true) {
    seq++;
    await new Promise(r => setTimeout(r, POLL_MS));

    try {
      const { httpCode, body, elapsed } = await getDeviceStatus();
      errorStreak = 0;

      if (httpCode !== 200 || !body.success) {
        const msg = (body && body.msg) || `HTTP ${httpCode}`;
        console.error(C.red + `  ⚠ ${msg} [${elapsed}ms]` + C.r);
        continue;
      }

      const dps = body.result || [];
      const state = {};
      for (const dp of dps) state[dp.code] = dp.value;
      display(state);

    } catch (e) {
      errorStreak++;
      if (errorStreak <= 3) console.error(C.red + `  ⚠ ${e.message}` + C.r);
      if (errorStreak === 3) console.error(C.dim + '  (suprimiendo errores)' + C.r);
    }
  }
}

main();
