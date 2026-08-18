#!/usr/bin/env node
/**
 * tuya-pulsar-consumer — WebSocket reader for Tuya Message Service (Pulsar/WS).
 *
 * Connects to Tuya's Pulsar WebSocket proxy for Central Europe,
 * receives real-time device status report messages via Pulsar Reader API
 * (no subscription required), filters for configured sensor devices,
 * and forwards changes to our PHP API via HTTP POST.
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
const SUBSCRIPTION_NAME = process.env.TUYA_ACCESS_ID + '-sub-iot-web';

// Pulsar Consumer endpoint — connects to existing subscription in Tuya console.
// Pulsar Reader endpoint — reads directly from topic without subscription.
// messageId=earliest → read from beginning of topic to catch all messages.
const WS_URL     = 'wss://mqe.tuyaeu.com:8285/ws/v2/reader/persistent/' +
                   `${ACCESS_ID}/out/event` +
                   '?messageId=latest';
const SENSOR_DEVICE_IDS = [
  'bf4c7e7d2cef28cea2nkwk',  // PROXIMITY – MC400D door magnet
  'bf98d27d79685e38a2wbda',  // PRESENCE  – ZY-M100-5 mmWave
  'bfafd3f2013c4b1876f5g5',  // LOCK      – WBR3/jtmspro smart lock
  'bfc8730a715c56c8d1paby',  // SWITCH    – EAWCBT-J circuit breaker
];
const API_WEBHOOK_URL  = 'http://127.0.0.1:8080/api/v1/tuya/webhook';

// ─── Dependencies ─────────────────────────────────────────────────────
const WebSocket = require('ws');
const crypto = require('crypto');
const cryptoJS = require('crypto-js');

// ─── Tuya Auth: build password (same as SDK utils.ts) ─────────────────
function buildPassword(accessId, accessKey) {
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
function mapToPresenceEvent(data) {
  const devId = data.devId || data.dev_id || '';

  if (!SENSOR_DEVICE_IDS.includes(devId)) {
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
      const present = (value === 'presence' || value === true || value === 'true' || value === 1);
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
let reconnectDelay = 5000;   // Exponential backoff for 409/error
let consecutiveFails = 0;
const MAX_BACKOFF = 300000;  // 5 minutes max

function connect() {
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

  let keepAliveTimer = null;

  ws.on('open', () => {
    console.log('[WS] ✅ Connected to Tuya Message Service');
    consecutiveFails = 0;
    reconnectDelay = 5000;
  });

  ws.on('ping', () => {
    clearTimeout(keepAliveTimer);
    ws.pong(ACCESS_ID);
    keepAliveTimer = setTimeout(() => ws.ping(ACCESS_ID), 30000);
  });

  ws.on('pong', () => {
    clearTimeout(keepAliveTimer);
    keepAliveTimer = setTimeout(() => ws.ping(ACCESS_ID), 30000);
  });

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

      if (!SENSOR_DEVICE_IDS.includes(devId) && !SENSOR_DEVICE_IDS.includes(dataToCheck.devId || dataToCheck.dev_id || '')) {
        console.log(`[MSG] Not our device (${devId}), skipping`);
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
    clearTimeout(keepAliveTimer);
    consecutiveFails++;
    // Exponential backoff for persistent failures (409 = subscription conflict, etc.)
    if (code === 1006 || code === 4000 || code === 4001) {
      reconnectDelay = Math.min(reconnectDelay * 2, MAX_BACKOFF);
    }
    console.log(`[WS] ❌ Closed (code=${code}). Consecutive fails: ${consecutiveFails}. Reconnecting in ${Math.round(reconnectDelay/1000)}s...`);
    setTimeout(connect, reconnectDelay);
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
console.log('═══ Tuya Pulsar WebSocket Consumer ═══');
console.log(`  Devices: ${SENSOR_DEVICE_IDS.join(', ')}`);
console.log(`  Region:  EU (Central Europe)`);
console.log(`  API:     ${API_WEBHOOK_URL}`);
console.log('');

connect();
