#!/usr/bin/env node
/**
 * tuya-door-monitor — Monitor de puerta en tiempo real vía Pulsar WS.
 *
 * Se conecta al Pulsar de Tuya en modo Reader (latest) y muestra en consola
 * cada cambio del sensor de puerta MC400D: ABIERTO / CERRADO.
 *
 * Uso:
 *   node /root/cerraduras/api/bin/tuya-door-monitor.js
 *
 * Ctrl+C para salir.
 */

// ─── Credenciales (vía variables de entorno) ─────────────────────────
const ACCESS_ID  = process.env.TUYA_ACCESS_ID || '';
const ACCESS_KEY = process.env.TUYA_ACCESS_SECRET || '';
const DEVICE_ID  = 'bf4c7e7d2cef28cea2nkwk'; // MC400D — sensor de puerta

// ─── WS Reader URL (latest = solo eventos nuevos, conexión persistente) ─
const WS_URL = `wss://mqe.tuyaeu.com:8285/ws/v2/reader/persistent/${ACCESS_ID}/out/event?messageId=latest`;

// ─── Dependencias (reutiliza las del pulsar-consumer) ───────────────
const WebSocket = require('./tuya-pulsar-consumer/node_modules/ws');
const crypto   = require('crypto');
const cryptoJS = require('./tuya-pulsar-consumer/node_modules/crypto-js');

// ─── Colores ANSI ────────────────────────────────────────────────────
const R = '\x1b[0m';
const G = '\x1b[32m';
const B = '\x1b[1m';
const Y = '\x1b[33m';
const D = '\x1b[2m';
const C = '\x1b[36m';

// ─── Tuya Pulsar auth (password = MD5(ACCESS_ID + MD5(ACCESS_KEY))[8:24]) ─
function buildPassword(accessId, accessKey) {
  const key = cryptoJS.MD5(accessKey).toString();
  return cryptoJS.MD5(accessId + key).toString().substring(8, 24);
}

// ─── AES-GCM decryption (mismo que el consumer) ──────────────────────
function decryptPayload(rawMessage, accessKey) {
  const { payload, properties } = JSON.parse(rawMessage);
  const encryptyModel = (properties || {}).em; // 'aes_gcm' or 'aes_ecb'
  const pStr = Buffer.from(payload, 'base64').toString('utf-8');
  const pJson = JSON.parse(pStr);

  let decrypted = '';
  if (encryptyModel === 'aes_gcm') {
    const bData = Buffer.from(pJson.data, 'base64');
    const iv    = bData.slice(0, 12);
    const tag   = bData.slice(-16);
    const cdata = bData.slice(12, bData.length - 16);
    const decipher = crypto.createDecipheriv('aes-128-gcm', accessKey.substring(8, 24), iv);
    decipher.setAuthTag(tag);
    decrypted = decipher.update(cdata, undefined, 'utf8') + decipher.final('utf8');
  } else {
    // ECB fallback
    const realKey = cryptoJS.enc.Utf8.parse(accessKey.substring(8, 24));
    const json = cryptoJS.AES.decrypt(pJson.data, realKey, {
      mode: cryptoJS.mode.ECB, padding: cryptoJS.pad.Pkcs7,
    });
    decrypted = cryptoJS.enc.Utf8.stringify(json).toString();
  }

  try { pJson.data = JSON.parse(decrypted); } catch (e) { pJson.data = decrypted; }
  return { payload: pJson };
}

// ─── Timestamp ────────────────────────────────────────────────────────
function ts() {
  return new Date().toISOString().replace('T',' ').substring(11, 19);
}

// ─── Estado ───────────────────────────────────────────────────────────
let lastState = null;

function printState(open) {
  const state = open ? '🚪 ABIERTO ' : '🚪 CERRADO';
  const color = open ? G + B : Y;
  if (state !== lastState) {
    const arrow = lastState !== null
      ? (open ? ` ${D}→${R} ${G + B}ABIERTO${R}` : ` ${D}→${R} ${Y}CERRADO${R}`)
      : '';
    console.log(`${C}[${ts()}]${R} ${color}${state}${R}${arrow}`);
    lastState = state;
  }
}

// ─── Conectar ─────────────────────────────────────────────────────────
function connect() {
  const password = buildPassword(ACCESS_ID, ACCESS_KEY);

  console.log(`${C}[${ts()}]${R} ${D}Conectando a Tuya Pulsar (EU, Reader/latest)...${R}`);

  const ws = new WebSocket(WS_URL, {
    rejectUnauthorized: false,
    headers: { username: ACCESS_ID, password: password },
  });

  let keepAliveTimer = null;

  ws.on('open', () => {
    console.log(`${G}✅ Conectado — esperando eventos del sensor de puerta...${R}`);
    console.log(`${D}   Separa el imán del sensor MC400D para probar ABIERTO${R}`);
    console.log('');
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

  ws.on('message', (raw) => {
    try {
      const msg = decryptPayload(raw.toString(), ACCESS_KEY);

      // ACK
      ws.send(JSON.stringify({ messageId: msg.messageId }));

      const payloadData = msg.payload && msg.payload.data;
      if (!payloadData) return;

      // Check if it's our door sensor
      const devId = payloadData.devId || payloadData.dev_id || '';
      const bizData = payloadData.bizData || payloadData;
      const bizDevId = (bizData && bizData.devId) || (bizData && bizData.dev_id) || '';
      if (devId !== DEVICE_ID && bizDevId !== DEVICE_ID) return;

      // Extract status
      const dps = (bizData && bizData.properties) || (bizData && bizData.status) || payloadData.status || [];
      if (!Array.isArray(dps)) return;

      for (const dp of dps) {
        const code  = (dp.code || '').toLowerCase();
        const value = dp.value;

        if (code === 'doorcontact_state') {
          const open = (value === true || value === 'true' || value === 1 || value === 'open');
          printState(open);
          return;
        }
      }
    } catch (err) {
      // Silencioso — no spam en consola
    }
  });

  ws.on('close', (code) => {
    clearTimeout(keepAliveTimer);
    const reason = code === 1001 ? '(fin de lote — normal en Reader)' : `(code=${code})`;
    console.log(`${D}[${ts()}] Conexión cerrada ${reason} — reconectando en 3s...${R}`);
    setTimeout(connect, 3000);
  });

  ws.on('error', (err) => {
    // Silencioso — el close handler se encarga de reconectar
  });
}

// ─── Arranque ─────────────────────────────────────────────────────────
console.log('');
console.log(`${B}═══ Tuya Door Monitor — MC400D ═══${R}`);
console.log(`${D}  Device : ${DEVICE_ID}${R}`);
console.log(`${D}  Modo   : Pulsar Reader (latest)${R}`);
console.log(`${D}  Ctrl+C para salir${R}`);
console.log('');

connect();
