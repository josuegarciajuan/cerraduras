#!/usr/bin/env node
/**
 * tuya-pulsar-consumer — WebSocket reader for Tuya Message Service (Pulsar/WS).
 *
 * Connects to Tuya's Pulsar WebSocket proxy for Central Europe,
 * receives real-time device status report messages via Pulsar Reader API
 * (no subscription required), filters for the sensor devices currently present
 * in the DB (device → pack, canonical F30 — no hardcoded ids), and forwards
 * changes to our PHP API via HTTP POST.
 *
 * F44 (RF-50.1/50.3): the tracked device list is resolved from MySQL and
 * reconciled periodically; on every (re)connection a rate-limited REST resync
 * probes the sensors once to recover transitions lost during the WS gap.
 *
 * Protocol: Pulsar Reader over WebSocket (wss://mqe.tuyaeu.com:8285/)
 *   Reader mode reads from the topic directly without a subscription,
 *   avoiding 409 conflicts on the Consumer endpoint.
 *
 * Usage:
 *   cd api/bin/tuya-pulsar-consumer && npm install && node index.js
 */

// ─── Configuration ───────────────────────────────────────────────────
// Load .env into process.env
(function() {
  const fs = require('fs');
  const path = require('path');
  const envFile = path.join(__dirname, '..', '..', '.env');
  if (fs.existsSync(envFile)) {
    fs.readFileSync(envFile, 'utf8').split('\n').forEach(function(line) {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith('#')) return;
      const eq = trimmed.indexOf('=');
      if (eq > 0) process.env[trimmed.slice(0, eq).trim()] = trimmed.slice(eq + 1).trim().replace(/^["']|["']$/g, '');
    });
  }
})();

const ACCESS_ID  = process.env.TUYA_ACCESS_ID || '';
const ACCESS_KEY = process.env.TUYA_ACCESS_SECRET || '';

// Pulsar Consumer endpoint — connects to existing subscription in Tuya console.
// Pulsar Reader endpoint — reads directly from topic without subscription.
// messageId=earliest → read from beginning of topic to catch all messages.
const WS_URL     = 'wss://mqe.tuyaeu.com:8285/ws/v2/reader/persistent/' +
                   `${ACCESS_ID}/out/event` +
                   '?messageId=latest';
const API_WEBHOOK_URL  = 'http://127.0.0.1:8080/api/v1/tuya/webhook';

// ─── Dynamic device list (F44, RF-50.1) ───────────────────────────────
// No hardcoded ids: resuelto desde la BD (device → pack, canónico F30).
const DB_HOST = process.env.DB_HOST || '127.0.0.1';
const DB_PORT = process.env.DB_PORT || '3306';
const DB_NAME = process.env.DB_NAME || 'cerraduras_db';
const DB_USER = process.env.DB_USER || 'cerraduras_user';
const DB_PASS = process.env.DB_PASS || '';
// Sensores que aportan estado de dominio (puerta / presencia). LOCK/SWITCH
// son guiados por comando y no necesitan push para el panel de coreografía.
const TRACKED_KINDS = ['PROXIMITY', 'PRESENCE'];
const RECONCILE_MS = 60000;              // re-resolver devices cada 60 s
const RESYNC_MIN_INTERVAL_MS = 20000;    // rate-limit del resync REST (parpadeos)
const REAL_GAP_MS = 10000;               // F47: hueco que justifica resync inmediato
const RECONNECT_BASE_MS = 1000;          // F47: base de reconexión (antes 5000)
// F47 (RF-54.3): watchdog de silencio (configurable). Por defecto 180 s: los
// sensores de presencia emiten iluminancia cada ~10 s; un silencio mayor indica
// WS zombie. En packs solo-puerta (sin emisión periódica) es una red de
// seguridad secundaria, no la fuente primaria de reconexión.
const SILENCE_MS = parseInt(process.env.CONSUMER_SILENCE_MS || '180000', 10);

/** @type {string[]} */
let knownDeviceIds = [];
let lastResyncAt = 0;

// ─── F47: estado de salud del consumer (RF-54) ────────────────────────
let currentWs = null;        // WS activo (para el watchdog)
let lastMessageAt = 0;       // epoch ms del último mensaje recibido (cualquier DP)
let disconnectedAt = 0;      // epoch ms del último cierre (0 = conectado)
let resyncCount = 0;         // resyncs ejecutados desde el arranque

// ─── Dependencies ─────────────────────────────────────────────────────
const crypto = require('crypto');
const https = require('https');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const STATUS_FILE = path.join(__dirname, '..', '..', 'run', 'pulsar-consumer-status.json');

// ─── F47: helpers puros (exportados para tests, RF-54) ────────────────

/**
 * Pure: ¿procede resincronizar al (re)conectar?
 * - Primera vez → siempre.
 * - Hueco real (>= realGapMs) desde el cierre → sí.
 * - Parpadeo → respeta el rate-limit.
 */
function shouldResync(now, disconnectedAtMs, lastResyncAtMs, minIntervalMs, realGapMs) {
  if (!lastResyncAtMs) return true;
  const gap = disconnectedAtMs > 0 ? now - disconnectedAtMs : 0;
  if (gap >= realGapMs) return true;
  return (now - lastResyncAtMs) >= minIntervalMs;
}

/** Pure: ¿el WS lleva demasiado tiempo mudo con devices rastreados? */
function silenceExceeded(now, lastMsgAt, knownCount, silenceMs) {
  if (!knownCount || knownCount <= 0) return false;
  if (!lastMsgAt) return false;
  return (now - lastMsgAt) > silenceMs;
}

/** Pure: latencia de recepción (ms) respecto al sello del dispositivo. */
function receiveLatencyMs(now, tuyaT) {
  const t = Number(tuyaT);
  if (!isFinite(t) || t <= 0) return null;
  return now - t;
}

/** Escribe el fichero de estado (best-effort, nunca rompe el consumer). */
function writeStatus(connected) {
  try {
    const dir = path.dirname(STATUS_FILE);
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
    const now = Date.now();
    fs.writeFileSync(STATUS_FILE, JSON.stringify({
      connected: !!connected,
      last_msg_at: lastMessageAt ? new Date(lastMessageAt).toISOString() : null,
      known_devices: knownDeviceIds.length,
      resyncs: resyncCount,
      updated_at: new Date(now).toISOString(),
    }) + '\n');
  } catch (e) { /* best-effort */ }
}

// `ws` / `crypto-js` se cargan de forma perezosa: requerir este módulo desde los
// tests (sin node_modules) no debe fallar ni abrir el WS (F44).
let WebSocket = null;
let cryptoJS = null;
function loadSocketDeps() {
  if (!WebSocket) WebSocket = require('ws');
  if (!cryptoJS) cryptoJS = require('crypto-js');
}

// ─── Dynamic device discovery (F44, RF-50.1) ──────────────────────────

/**
 * Pure: convierte la salida `mysql -N -e "SELECT external_id ..."` en una
 * lista de ids no vacíos y sin duplicados.
 */
function parseDeviceIds(stdout) {
  return String(stdout || '')
    .split('\n')
    .map((s) => s.trim())
    .filter((s) => s.length > 0)
    .filter((s, i, arr) => arr.indexOf(s) === i);
}

/** Resuelve desde MySQL los external_id de los sensores rastreados. */
function loadKnownDevices() {
  try {
    const kinds = TRACKED_KINDS.map((k) => `'${k}'`).join(',');
    // F46+: los devices `presence_source='disabled'` NO se rastrean (apagado
    // fuerte). Los `push` SÍ (su tiempo real llega por este consumer).
    const sql = `SELECT external_id FROM devices
                 WHERE kind IN (${kinds})
                   AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.presence_source')),'') <> 'disabled'`;
    const out = execFileSync('mysql', [
      '-h', DB_HOST, '-P', String(DB_PORT), '-u', DB_USER, DB_NAME, '-N', '-e', sql,
    ], { env: { ...process.env, MYSQL_PWD: DB_PASS }, encoding: 'utf8', timeout: 5000 });
    return parseDeviceIds(out);
  } catch (e) {
    console.error(`[DB] ⚠ no se pudieron resolver devices: ${e.message}`);
    return knownDeviceIds; // conserva la lista previa si la BD falla
  }
}

/** Pure: ¿el devId pertenece a la lista rastreada? */
function isKnownDevice(devId, ids) {
  if (!devId) return false;
  return (ids || knownDeviceIds).indexOf(String(devId)) !== -1;
}

// ─── Tuya REST (para resync puntual, RF-50.3) ─────────────────────────

let tuyaToken = null;
let tuyaTokenExpiry = 0;

function tuyaSign(method, pathWithQuery, body, token) {
  const [path, queryString] = pathWithQuery.split('?');
  const contentSha = crypto.createHash('sha256').update(body || '').digest('hex');
  let strToSign = `${method}\n${contentSha}\n\n${path}`;
  if (queryString) {
    const params = new URLSearchParams(queryString);
    const sorted = [...params.entries()].sort((a, b) => a[0].localeCompare(b[0]));
    strToSign += '?' + sorted.map(([k, v]) => `${k}=${v}`).join('&');
  }
  const t = String(Date.now());
  const message = ACCESS_ID + (token || '') + t + strToSign;
  const sign = crypto.createHmac('sha256', ACCESS_KEY).update(message).digest('hex').toUpperCase();
  return { sign, t, contentSha };
}

function tuyaRequest(method, pathWithQuery, body, token) {
  return new Promise((resolve, reject) => {
    const { sign, t, contentSha } = tuyaSign(method, pathWithQuery, body, token);
    const headers = {
      'client_id': ACCESS_ID, 'sign': sign, 'sign_method': 'HMAC-SHA256',
      't': t, 'Content-Type': 'application/json', 'Content-SHA256': contentSha,
    };
    if (token) headers['access_token'] = token;
    const opts = { hostname: 'openapi.tuyaeu.com', port: 443, path: pathWithQuery, method, headers, timeout: 8000 };
    const req = https.request(opts, (res) => {
      let data = '';
      res.on('data', (chunk) => data += chunk);
      res.on('end', () => {
        try { resolve(JSON.parse(data)); } catch (e) { resolve({ success: false, msg: 'bad_json' }); }
      });
    });
    req.on('timeout', () => { req.destroy(); reject(new Error('timeout')); });
    req.on('error', (err) => reject(err));
    if (method === 'POST' && body) req.write(body);
    req.end();
  });
}

async function getTuyaToken() {
  if (tuyaToken && Date.now() < tuyaTokenExpiry) return tuyaToken;
  const body = await tuyaRequest('GET', '/v1.0/token?grant_type=1', '', '');
  if (!body || body.success !== true) throw new Error(`token fail: ${(body && body.msg) || 'unknown'}`);
  tuyaToken = body.result.access_token;
  tuyaTokenExpiry = Date.now() + (body.result.expire_time * 1000) - 60000;
  return tuyaToken;
}

/**
 * Pure: convierte el `result` de `/status` en el payload canónico que entiende
 * TuyaSensorIngress (shape legacy `{devId, status:[{code,value,t}]}`).
 * Devuelve null si no hay DPs accionables.
 */
function buildStatusPayload(devId, result) {
  if (!Array.isArray(result) || result.length === 0) return null;
  const status = result
    .filter((dp) => dp && dp.code)
    .map((dp) => ({ code: dp.code, value: dp.value, t: dp.t || Date.now() }));
  if (status.length === 0) return null;
  return { devId, status };
}

/**
 * Resync puntual (RF-50.3): una sonda REST por device rastreado tras (re)conectar
 * el WS, para corregir transiciones perdidas en el hueco de reconexión. Rate-limited
 * para no consumir cuota en reconnects frecuentes.
 */
async function resyncKnownDevices(reason) {
  if (knownDeviceIds.length === 0) return;
  const now = Date.now();
  if (!shouldResync(now, disconnectedAt, lastResyncAt, RESYNC_MIN_INTERVAL_MS, REAL_GAP_MS)) {
    console.log('[RESYNC] omitido (hueco reciente + rate-limit activo)');
    return;
  }
  lastResyncAt = now;
  resyncCount++;
  console.log(`[RESYNC] (${reason}) sondeando ${knownDeviceIds.length} device(s)...`);
  let token;
  try { token = await getTuyaToken(); } catch (e) {
    console.error(`[RESYNC] token fail: ${e.message}`);
    return;
  }
  for (const devId of knownDeviceIds) {
    try {
      const body = await tuyaRequest('GET', `/v1.0/iot-03/devices/${devId}/status`, '', token);
      if (!body || body.success !== true) continue;
      const payload = buildStatusPayload(devId, body.result);
      if (payload) await forwardToApi(payload);
    } catch (e) {
      console.error(`[RESYNC] ${devId}: ${e.message}`);
    }
  }
}

// ─── Tuya Auth: build password (same as SDK utils.ts) ─────────────────
function buildPassword(accessId, accessKey) {
  loadSocketDeps();
  const key = cryptoJS.MD5(accessKey).toString();
  return cryptoJS.MD5(accessId + key).toString().substring(8, 24);
}

// ─── AES-GCM Decryption (same as SDK utils.ts) ────────────────────────
function decryptByGCM(data, accessKey) {
  try {
    const bData = Buffer.from(data, 'base64');
    const iv   = bData.slice(0, 12);
    const tag  = bData.slice(-16);
    const cdata = bData.slice(12, bData.length - 16);
    const decipher = crypto.createDecipheriv('aes-128-gcm', accessKey.substring(8, 24), iv);
    decipher.setAuthTag(tag);
    let dataStr = decipher.update(cdata, undefined, 'utf8');
    dataStr += decipher.final('utf8');
    return JSON.parse(dataStr);
  } catch (e) {
    return '';
  }
}

// ─── AES-ECB Decryption (fallback) ────────────────────────────────────
function decryptByECB(data, accessKey) {
  try {
    loadSocketDeps();
    const realKey = cryptoJS.enc.Utf8.parse(accessKey.substring(8, 24));
    const json = cryptoJS.AES.decrypt(data, realKey, {
      mode: cryptoJS.mode.ECB,
      padding: cryptoJS.pad.Pkcs7,
    });
    const dataStr = cryptoJS.enc.Utf8.stringify(json).toString();
    return JSON.parse(dataStr);
  } catch (e) {
    return '';
  }
}

// ─── Decrypt payload (same as SDK index.ts handleMessage) ──────────────
function decryptPayload(rawMessage, accessKey) {
  const { payload, properties, ...others } = JSON.parse(rawMessage);
  const encryptyModel = (properties || {}).em;   // 'aes_gcm' or 'aes_ecb'
  const pStr = Buffer.from(payload, 'base64').toString('utf-8');
  const pJson = JSON.parse(pStr);
  const decrypted = (encryptyModel === 'aes_gcm')
    ? decryptByGCM(pJson.data, accessKey)
    : decryptByECB(pJson.data, accessKey);
  pJson.data = decrypted;
  return { payload: pJson, ...others };
}

// ─── Forward sensor event to PHP API ──────────────────────────────────
async function forwardToApi(sensorEvent) {
  try {
    const body = JSON.stringify(sensorEvent);
    const response = await fetch(API_WEBHOOK_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body,
    });
    const status = response.status;
    const text   = await response.text().catch(() => '');
    console.log(`[API] HTTP ${status} → ${text.substring(0, 200)}`);
  } catch (err) {
    console.error(`[API] Error forwarding to API: ${err.message}`);
  }
}

// ─── Map Tuya status → canonical presence event ───────────────────────
function mapToPresenceEvent(data, ids) {
  const devId = data.devId || data.dev_id || '';

  if (!isKnownDevice(devId, ids)) {
    return null; // Not our sensor
  }

  for (const st of data.status) {
    const code  = st.code  || '';
    const value = st.value;

    if (code === 'doorcontact_state') {
      const doorOpen = (value === true || value === 'true' || value === 1);
      return {
        devId:            devId,
        code:             code,
        doorcontact_state: doorOpen,
        doorOpen:          doorOpen ? 'OPEN' : 'CLOSED',
        raw_value:         value,
        t:                 st.t || data.t || Date.now(),
      };
    }

    if (code === 'presence_state') {
      // 24G V3 reporta 'move' para movimiento; cuenta como presencia (F43/F44).
      const present = (value === 'presence' || value === 'move' || value === true || value === 'true' || value === 1);
      return {
        devId:           devId,
        code:            code,
        presence_state:  value,
        present:          present,
        t:                st.t || data.t || Date.now(),
      };
    }
  }
  return null; // no actionable DP in this message
}

// ─── Connect and listen ───────────────────────────────────────────────
// F44/F47 (RF-50.3, RF-54.1): reconexión con base 1 s para acortar el hueco en
// el que el Reader/latest pierde eventos; el resync REST cubre lo perdido.
let reconnectDelay = RECONNECT_BASE_MS;   // F47: base 1 s (RF-54.1)
let consecutiveFails = 0;
const MAX_BACKOFF = 60000;   // 1 minute max

function connect() {
  loadSocketDeps();
  const password = buildPassword(ACCESS_ID, ACCESS_KEY);

  console.log(`[WS] Connecting to Tuya Pulsar WS (EU) [Reader mode]...`);
  console.log(`[WS] URL: ${WS_URL}`);
  console.log(`[WS] username=${ACCESS_ID} password=${'*'.repeat(16)}`);

  const ws = new WebSocket(WS_URL, {
    rejectUnauthorized: false,
    headers: {
      username: ACCESS_ID,
      password: password,
    },
  });
  currentWs = ws;   // F47: referencia para el watchdog de silencio

  let keepAliveTimer = null;   // timer del heartbeat proactivo

  // FIX (push fiable): heartbeat proactivo. Antes solo se enviaba ping como
  // RESPUESTA a un ping del servidor; si Tuya no pingueaba primero, el cliente
  // quedaba mudo y el servidor cerraba la conexión con code=1001 (flapping
  // histórico: 7.6k cierres → mensajes perdidos). Enviamos ping cada 30 s.
  const HEARTBEAT_MS = 30000;
  function startHeartbeat() {
    if (keepAliveTimer) clearInterval(keepAliveTimer);
    keepAliveTimer = setInterval(() => {
      if (ws.readyState === WebSocket.OPEN) {
        try { ws.ping(ACCESS_ID); } catch (e) { /* best-effort */ }
      }
    }, HEARTBEAT_MS);
  }
  function stopHeartbeat() {
    if (keepAliveTimer) { clearInterval(keepAliveTimer); keepAliveTimer = null; }
  }

  ws.on('open', () => {
    console.log('[WS] ✅ Connected to Tuya Message Service');
    consecutiveFails = 0;
    reconnectDelay = RECONNECT_BASE_MS;   // F47: reset a base rápida
    lastMessageAt = Date.now();           // F47: evita falso silencio al conectar
    writeStatus(true);
    startHeartbeat();
    // RF-50.3: recuperar transiciones perdidas durante el hueco del WS.
    // F47: debe evaluarse ANTES de resetear `disconnectedAt`, para que un hueco
    // real (>= REAL_GAP_MS) permita el resync aunque el rate-limit esté activo.
    resyncKnownDevices('ws-open').catch((e) =>
      console.error(`[RESYNC] error: ${e.message}`));
    disconnectedAt = 0;
  });

  ws.on('ping', () => { ws.pong(ACCESS_ID); });
  ws.on('pong', () => { /* liveness del servidor */ });

  ws.on('message', async (raw) => {
    try {
      const rawStr = raw.toString();
      console.log(`\n[MSG] ${new Date().toISOString().replace('T',' ').substring(0,19)} — received message`);

      // Decrypt
      const msg = decryptPayload(rawStr, ACCESS_KEY);
      console.log(`[MSG] messageId: ${msg.messageId}, key: ${msg.key}`);

      // ACK
      ws.send(JSON.stringify({ messageId: msg.messageId }));
      console.log(`[MSG] ACK sent`);

      // Parse the decrypted data
      const payloadData = msg.payload && msg.payload.data;
      if (!payloadData) {
        console.log('[MSG] No data field, skipping');
        return;
      }

      // Log the raw data object
      console.log(`[MSG] bizCode: ${(payloadData.bizCode || payloadData.biz_code || 'N/A')}`);
      console.log(`[MSG] devId: ${payloadData.devId || payloadData.dev_id || 'N/A'}`);

      // Check if message is from one of our sensors
      const devId = payloadData.devId || payloadData.dev_id || '';
      
      // Also check bizData for IoT Core format
      let dataToCheck = payloadData;
      if (payloadData.bizData && !dataToCheck.status) {
        dataToCheck = payloadData.bizData;
      }

      // F47 (RF-54.4.1): latencia de recepción respecto al sello del dispositivo.
      lastMessageAt = Date.now();
      const dpList = Array.isArray(dataToCheck.status) ? dataToCheck.status : [];
      const dpT = (dpList[0] && dpList[0].t) || dataToCheck.t || null;
      const latMs = receiveLatencyMs(lastMessageAt, dpT);
      if (latMs !== null) console.log(`[LAT] recv-tuya_t = ${latMs} ms`);
      writeStatus(true);

      if (!isKnownDevice(devId) && !isKnownDevice(dataToCheck.devId || dataToCheck.dev_id || '')) {
        console.log(`[MSG] Not a tracked device (${devId}), skipping`);
        return;
      }

      // Use mapToPresenceEvent to identify the DP type for logging
      const sensorEvent = mapToPresenceEvent(dataToCheck);
      if (sensorEvent) {
        if (sensorEvent.code === 'presence_state') {
          console.log(`[SENSOR] 🧑 presence_state = ${sensorEvent.presence_state} → ${sensorEvent.present ? 'PRESENT' : 'ABSENT'}`);
        } else {
          console.log(`[SENSOR] 🚪 doorcontact_state = ${sensorEvent.doorcontact_state} → ${sensorEvent.doorOpen}`);
        }
      }

      // Forward raw payloadData (proper Tuya format) so PHP normalize() works
      const rawPayload = payloadData.bizData ? payloadData.bizData : payloadData;
      await forwardToApi(rawPayload);
    } catch (err) {
      console.error(`[ERR] Message processing error: ${err.message}`);
      console.error(err.stack);
    }
  });

  ws.on('close', (code, reason) => {
    stopHeartbeat();
    consecutiveFails++;
    disconnectedAt = Date.now();   // F47: para decidir el resync por hueco real
    currentWs = null;
    writeStatus(false);
    // Exponential backoff for persistent failures (409 = subscription conflict, etc.)
    if (code === 1006 || code === 4000 || code === 4001) {
      reconnectDelay = Math.min(reconnectDelay * 2, MAX_BACKOFF);
    } else {
      reconnectDelay = RECONNECT_BASE_MS;   // F47: cierre normal → base rápida (1 s)
    }
    const jitter = Math.floor(Math.random() * 500);
    console.log(`[WS] ❌ Closed (code=${code}). Consecutive fails: ${consecutiveFails}. Reconnecting in ${Math.round((reconnectDelay + jitter)/1000)}s...`);
    setTimeout(connect, reconnectDelay + jitter);
  });

  ws.on('error', (err) => {
    console.error(`[WS] ⚠️ Error: ${err.message}`);
    // HTTP 409 means subscription conflict — back off aggressively
    if (err.message && err.message.includes('409')) {
      reconnectDelay = Math.min(reconnectDelay * 2, MAX_BACKOFF);
    }
  });
}

// ─── Start ────────────────────────────────────────────────────────────
function start() {
  console.log('═══ Tuya Pulsar WebSocket Consumer ═══');
  console.log(`  Region:  EU (Central Europe)`);
  console.log(`  API:     ${API_WEBHOOK_URL}`);
  console.log(`  Device discovery: BD (${TRACKED_KINDS.join('/')}) cada ${RECONCILE_MS / 1000}s`);

  // RF-50.1: resolver devices desde BD y reconciliar periódicamente.
  knownDeviceIds = loadKnownDevices();
  console.log(`  Devices: ${knownDeviceIds.length ? knownDeviceIds.join(', ') : '(ninguno)'}`);
  console.log('');

  setInterval(() => {
    const before = knownDeviceIds.join(',');
    knownDeviceIds = loadKnownDevices();
    if (knownDeviceIds.join(',') !== before) {
      console.log(`[DB] 🔁 devices actualizados: ${knownDeviceIds.length ? knownDeviceIds.join(', ') : '(ninguno)'}`);
    }
  }, RECONCILE_MS).unref();

  // F47 (RF-54.3): watchdog de silencio. Con devices rastreados, si el WS lleva
  // más de SILENCE_MS sin ningún mensaje, se fuerza la reconexión. Antes podía
  // quedarse mudo sin que nadie lo detectara (el heartbeat solo mantenía el TCP).
  lastMessageAt = Date.now();
  writeStatus(false);
  setInterval(() => {
    if (!currentWs || !WebSocket || currentWs.readyState !== WebSocket.OPEN) return;
    if (silenceExceeded(Date.now(), lastMessageAt, knownDeviceIds.length, SILENCE_MS)) {
      console.error(`[WS] ⚠ silencio > ${Math.round(SILENCE_MS / 1000)}s con devices rastreados — reconectando`);
      try { currentWs.terminate(); } catch (e) { /* close programa la reconexión */ }
    }
  }, 15000).unref();

  connect();
}

// Solo arranca cuando se ejecuta directamente: importarlo en tests no debe
// abrir el WS ni tocar la BD (F44).
if (require.main === module) {
  start();
}

module.exports = {
  parseDeviceIds,
  isKnownDevice,
  buildStatusPayload,
  mapToPresenceEvent,
  TRACKED_KINDS,
  // F47 (RF-54): helpers puros para tests
  shouldResync,
  silenceExceeded,
  receiveLatencyMs,
};
