#!/usr/bin/env node
'use strict';

/**
 * presence-latency-probe — mide la latencia end-to-end del pipeline de sensores
 * SIN consumir cuota de Tuya (solo SSE local + MySQL, en lectura).
 *
 * F47 (RF-53). Trazabilidad: RF-53.1 (medición), RF-53.2 (sin cuota),
 * RF-53.3 (grupo de control con el sensor de puerta).
 *
 * Atribución por evento:
 *   físico → dispositivo  = tuya_t  - marca física   (retardo del sensor)
 *   dispositivo → BD      = received_at - tuya_t     (Message Service + consumer + webhook)
 *   BD → SSE              = server_ts - received_at  (event loop / panel)
 *
 * Uso:
 *   node api/bin/presence-latency-probe.js --room 12
 *   node api/bin/presence-latency-probe.js --room 12 --no-sse
 *
 * Marcas físicas (dos vías, ambas sin tocar el sensor):
 *   1) stdin:  escribe `DELANTE_SENSOR` (usa "ahora") o
 *              `DELANTE_SENSOR 2026-09-17T11:16:53.000Z` (instante explícito).
 *   2) fichero `api/run/latency-marker`: una marca por línea (mismo formato).
 *
 * No abre el WS de Tuya, no llama a la API de Tuya, no escribe en BD.
 */

// ─── Configuración ────────────────────────────────────────────────────
// Carga .env a process.env (mismo patrón que tuya-pulsar-consumer/index.js).
(function loadEnv() {
  const fs = require('fs');
  const path = require('path');
  const envFile = path.join(__dirname, '..', '.env');
  if (!fs.existsSync(envFile)) return;
  fs.readFileSync(envFile, 'utf8').split('\n').forEach(function (line) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) return;
    const eq = trimmed.indexOf('=');
    if (eq > 0) {
      process.env[trimmed.slice(0, eq).trim()] =
        trimmed.slice(eq + 1).trim().replace(/^["']|["']$/g, '');
    }
  });
})();

const DB_HOST = process.env.DB_HOST || '127.0.0.1';
const DB_PORT = process.env.DB_PORT || '3306';
const DB_NAME = process.env.DB_NAME || 'cerraduras_db';
const DB_USER = process.env.DB_USER || 'cerraduras_user';
const DB_PASS = process.env.DB_PASS || '';
const API_HOST = process.env.LATENCY_API_HOST || '127.0.0.1';
const API_PORT = parseInt(process.env.LATENCY_API_PORT || '8080', 10);
const RUN_DIR = require('path').join(__dirname, '..', 'run');
const MARKER_FILE = require('path').join(RUN_DIR, 'latency-marker');

// Máximo hueco entre una marca física y un evento para considerarla su causa.
const MARK_MATCH_WINDOW_MS = 180000;

// ─── Helpers puros (exportados para tests) ────────────────────────────

/**
 * Convierte un instante ISO-8601 o un DATETIME de MySQL (UTC) a epoch ms.
 * @param {string|number|null|undefined} value
 * @returns {number|null}
 */
function toMs(value) {
  if (value === null || value === undefined || value === '') return null;
  if (typeof value === 'number') return value;
  const s = String(value).trim();
  if (/^\d+$/.test(s)) {
    const n = Number(s);
    return s.length <= 10 ? n * 1000 : n; // segundos vs milisegundos
  }
  // MySQL DATETIME (UTC, sin zona) → añadir Z si no la trae.
  const iso = /[zZ]|[+-]\d{2}:?\d{2}$/.test(s) ? s : s.replace(' ', 'T') + 'Z';
  const ms = Date.parse(iso);
  return isFinite(ms) ? ms : null;
}

/**
 * Parsea una marca física.
 * Formatos válidos: "ETIQUETA" | "ETIQUETA <ISO-8601>" | "<ISO-8601> ETIQUETA".
 * @param {string} line
 * @param {number} nowMs instante de lectura (si la marca no trae hora)
 * @returns {{label:string, tsMs:number}|null}
 */
function parseMarker(line, nowMs) {
  const raw = String(line || '').trim();
  if (!raw || raw.startsWith('#')) return null;

  const isoMatch = raw.match(/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?/);
  const label = raw
    .replace(isoMatch ? isoMatch[0] : '', '')
    .replace(/[|,;]+/g, ' ')
    .trim()
    .replace(/\s+/g, '_')
    .toUpperCase();

  if (!label) return null;
  const tsMs = isoMatch ? toMs(isoMatch[0]) : nowMs;
  if (tsMs === null || !isFinite(tsMs)) return null;
  return { label: label, tsMs: tsMs };
}

/**
 * Extrae `tuya_t` (epoch ms) del `meta_json` ya decodificado o de su crudo.
 * @param {object|string|null} meta
 * @returns {number|null}
 */
function parseTuyaT(meta) {
  let obj = meta;
  if (typeof meta === 'string' && meta !== '') {
    try { obj = JSON.parse(meta); } catch (e) { return null; }
  }
  if (!obj || typeof obj !== 'object') return null;
  const t = obj.tuya_t;
  if (t === null || t === undefined || t === '') return null;
  const n = Number(t);
  return isFinite(n) && n > 0 ? n : null;
}

/**
 * Calcula deltas (ms) para un evento.
 * @param {{tuyaT:number|null, receivedAtMs:number|null, markMs:number|null, sseServerTs:number|null}} input
 * @returns {{physicalToDevice:number|null, deviceToDb:number|null, dbToSse:number|null}}
 */
function computeDeltas(input) {
  const tuyaT = input.tuyaT !== undefined ? input.tuyaT : null;
  const receivedAtMs = input.receivedAtMs !== undefined ? input.receivedAtMs : null;
  const markMs = input.markMs !== undefined ? input.markMs : null;
  const sseServerTs = input.sseServerTs !== undefined ? input.sseServerTs : null;

  const physicalToDevice =
    (tuyaT !== null && markMs !== null) ? tuyaT - markMs : null;
  const deviceToDb =
    (receivedAtMs !== null && tuyaT !== null) ? receivedAtMs - tuyaT : null;
  const dbToSse =
    (sseServerTs !== null && receivedAtMs !== null) ? sseServerTs - receivedAtMs : null;

  return {
    physicalToDevice: physicalToDevice,
    deviceToDb: deviceToDb,
    dbToSse: dbToSse,
  };
}

/**
 * Formatea un delta en ms legible, con signo. null → '—'.
 * @param {number|null} ms
 * @returns {string}
 */
function formatDelta(ms) {
  if (ms === null || ms === undefined || !isFinite(ms)) return '—';
  const sign = ms < 0 ? '-' : '';
  const abs = Math.abs(ms);
  if (abs < 1000) return sign + abs + 'ms';
  return sign + (abs / 1000).toFixed(1) + 's';
}

/**
 * Elige la marca física más reciente anterior al evento (dentro de la ventana).
 * @param {Array<{label:string, tsMs:number}>} marks
 * @param {number|null} eventMs
 * @param {number} windowMs
 * @returns {{label:string, tsMs:number}|null}
 */
function pickMarkForEvent(marks, eventMs, windowMs) {
  if (eventMs === null || !Array.isArray(marks) || marks.length === 0) return null;
  let best = null;
  for (const m of marks) {
    if (m.tsMs <= eventMs && (eventMs - m.tsMs) <= windowMs) {
      if (best === null || m.tsMs > best.tsMs) best = m;
    }
  }
  return best;
}

// ─── Runtime (solo si se ejecuta directamente) ────────────────────────

function main() {
  const { execFileSync, spawn } = require('child_process');
  const http = require('http');
  const readline = require('readline');
  const fs = require('fs');
  const path = require('path');

  const argv = process.argv.slice(2);
  function flag(name, def) {
    const i = argv.indexOf(name);
    return i >= 0 && argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[i + 1] : def;
  }
  const roomId = parseInt(flag('--room', process.env.LATENCY_ROOM || '0'), 10);
  const useSse = argv.indexOf('--no-sse') === -1;
  const pollMs = parseInt(flag('--db-poll-ms', '250'), 10);

  if (!roomId) {
    console.error('Uso: node bin/presence-latency-probe.js --room <id> [--no-sse] [--db-poll-ms 250]');
    process.exit(2);
  }

  function ts(ms) {
    return new Date(ms).toISOString();
  }

  console.log('═══ presence-latency-probe (F47/RF-53) ═══');
  console.log('  room_id: ' + roomId + ' | SSE: ' + (useSse ? 'on' : 'off') + ' | sin cuota Tuya');
  console.log('  marcas: escribe en stdin (p.ej. "DELANTE_SENSOR") o en ' + MARKER_FILE);
  console.log('');

  /** @type {Array<{label:string, tsMs:number}>} */
  const marks = [];
  const seenIds = new Set();
  const pendingDbToSse = new Map(); // clave "sensor|occurredAt" → {receivedAtMs}
  let lastId = 0;
  let sseConnected = false;

  function addMark(m) {
    if (!m) return;
    marks.push(m);
    if (marks.length > 100) marks.shift();
    console.log('  📍 MARCA ' + m.label + ' @ ' + ts(m.tsMs));
  }

  function printEvent(row) {
    const tuyaT = row.tuyaT;
    const receivedAtMs = toMs(row.receivedAt);
    const occurredMs = toMs(row.occurredAt);
    const refMs = tuyaT !== null ? tuyaT : occurredMs;
    const mark = pickMarkForEvent(marks, refMs, MARK_MATCH_WINDOW_MS);
    const deltas = computeDeltas({
      tuyaT: tuyaT,
      receivedAtMs: receivedAtMs,
      markMs: mark ? mark.tsMs : null,
      sseServerTs: null,
    });

    console.log('');
    console.log('[' + ts(receivedAtMs !== null ? receivedAtMs : Date.now()) + '] ' +
      row.sensor + ' ' + row.value + ' (id=' + row.id + ')');
    console.log('  marca     : ' + (mark ? mark.label + ' @ ' + ts(mark.tsMs) : '—'));
    console.log('  tuya_t    : ' + (tuyaT !== null ? ts(tuyaT) : '—') +
      '   occurred_at: ' + (occurredMs !== null ? ts(occurredMs) : '—'));
    console.log('  received  : ' + (receivedAtMs !== null ? ts(receivedAtMs) : '—'));
    console.log('  deltas    : físico→dispositivo=' + formatDelta(deltas.physicalToDevice) +
      '  dispositivo→BD=' + formatDelta(deltas.deviceToDb) +
      '  BD→SSE=' + formatDelta(deltas.dbToSse));

    const key = row.sensor + '|' + Math.floor((occurredMs !== null ? occurredMs : 0) / 1000);
    pendingDbToSse.set(key, { receivedAtMs: receivedAtMs, sensor: row.sensor });
  }

  function mysqlRows(sql) {
    const out = execFileSync('mysql', [
      '-h', DB_HOST, '-P', String(DB_PORT), '-u', DB_USER, DB_NAME, '-N', '-B',
      '-e', sql,
    ], { env: Object.assign({}, process.env, { MYSQL_PWD: DB_PASS }), encoding: 'utf8', timeout: 5000 });
    return String(out || '').split('\n').filter(function (l) { return l.trim() !== ''; });
  }

  // Sincroniza el cursor al final: solo interesan los eventos NUEVOS.
  try {
    const head = mysqlRows('SELECT COALESCE(MAX(id),0) FROM presence_events WHERE room_id=' + roomId);
    if (head.length > 0) lastId = parseInt(head[0], 10) || 0;
    console.log('  cursor inicial: id > ' + lastId + ' (eventos previos ignorados)');
  } catch (e) {
    console.error('  [DB] no se pudo leer el cursor inicial: ' + e.message);
  }

  function pollDb() {
    let lines;
    try {
      lines = mysqlRows(
        'SELECT id, sensor, value, occurred_at, received_at, ' +
        "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.tuya_t')),'') " +
        'FROM presence_events WHERE room_id=' + roomId + ' AND id > ' + lastId +
        ' ORDER BY id ASC LIMIT 100'
      );
    } catch (e) {
      console.error('  [DB] error: ' + e.message);
      return;
    }
    for (const line of lines) {
      const c = line.split('\t');
      if (c.length < 6) continue;
      const id = parseInt(c[0], 10);
      if (!isFinite(id) || seenIds.has(id)) continue;
      seenIds.add(id);
      if (id > lastId) lastId = id;
      printEvent({
        id: id,
        sensor: c[1],
        value: c[2],
        occurredAt: c[3],
        receivedAt: c[4],
        tuyaT: c[5] === '' ? null : Number(c[5]),
      });
    }
  }

  function connectSse() {
    const req = http.get({
      host: API_HOST,
      port: API_PORT,
      path: '/dashboard-api/event-stream?room_id=' + roomId,
      headers: { Accept: 'text/event-stream' },
    }, function (res) {
      res.setEncoding('utf8');
      let buf = '';
      res.on('data', function (chunk) {
        buf += chunk;
        let idx;
        while ((idx = buf.indexOf('\n\n')) !== -1) {
          const block = buf.slice(0, idx);
          buf = buf.slice(idx + 2);
          handleSseBlock(block);
        }
      });
      res.on('end', function () {
        if (sseConnected) console.log('  [SSE] stream cerrado — reconectando en 1s');
        sseConnected = false;
        setTimeout(connectSse, 1000);
      });
    });
    req.on('error', function () {
      if (sseConnected) console.log('  [SSE] error — reconectando en 2s');
      sseConnected = false;
      setTimeout(connectSse, 2000);
    });
    req.on('socket', function () {
      sseConnected = true;
    });
  }

  function handleSseBlock(block) {
    let event = '';
    let data = '';
    for (const line of block.split('\n')) {
      if (line.startsWith('event:')) event = line.slice(6).trim();
      else if (line.startsWith('data:')) data += line.slice(5).trim();
    }
    if (event !== 'state' || !data) return;
    let payload;
    try { payload = JSON.parse(data); } catch (e) { return; }
    const serverTs = toMs(payload.server_ts);
    if (serverTs === null) return;

    const iot = payload.iot_session || {};
    const candidates = [
      { sensor: 'PRESENCE', at: iot.last_presence_event_at, serverTs: serverTs },
      { sensor: 'PROXIMITY', at: iot.last_door_event_at, serverTs: serverTs },
    ];
    for (const cand of candidates) {
      const atMs = toMs(cand.at);
      if (atMs === null) continue;
      const key = cand.sensor + '|' + Math.floor(atMs / 1000);
      const pending = pendingDbToSse.get(key);
      if (pending) {
        const delta = cand.serverTs - pending.receivedAtMs;
        console.log('  ↳ [SSE] ' + cand.sensor + ' estado en panel: server_ts=' + ts(cand.serverTs) +
          '  BD→SSE=' + formatDelta(delta));
        pendingDbToSse.delete(key);
      }
    }
  }

  function loadMarkerFile() {
    if (!fs.existsSync(MARKER_FILE)) return;
    let text = '';
    try { text = fs.readFileSync(MARKER_FILE, 'utf8'); } catch (e) { return; }
    const lines = text.split('\n').filter(function (l) { return l.trim() !== ''; });
    const already = marks.map(function (m) { return m.label + '|' + m.tsMs; });
    for (const l of lines) {
      const m = parseMarker(l, Date.now());
      if (m && already.indexOf(m.label + '|' + m.tsMs) === -1) addMark(m);
    }
  }

  // ── stdin: marcas físicas ──
  const rl = readline.createInterface({ input: process.stdin });
  rl.on('line', function (line) {
    addMark(parseMarker(line, Date.now()));
  });

  // ── loop de DB + fichero de marcas ──
  if (!fs.existsSync(RUN_DIR)) {
    try { fs.mkdirSync(RUN_DIR, { recursive: true }); } catch (e) { /* best-effort */ }
  }
  pollDb();
  setInterval(pollDb, Math.max(100, pollMs));
  setInterval(loadMarkerFile, 1000);

  if (useSse) connectSse();

  process.on('SIGINT', function () {
    console.log('\n═══ resumen de marcas ═══');
    marks.forEach(function (m) { console.log('  ' + m.label + ' @ ' + ts(m.tsMs)); });
    process.exit(0);
  });
}

if (require.main === module) {
  main();
}

module.exports = {
  toMs: toMs,
  parseMarker: parseMarker,
  parseTuyaT: parseTuyaT,
  computeDeltas: computeDeltas,
  formatDelta: formatDelta,
  pickMarkForEvent: pickMarkForEvent,
};
