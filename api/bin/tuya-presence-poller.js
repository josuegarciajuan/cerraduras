#!/usr/bin/env node
/**
 * tuya-presence-poller — Generic gated Tuya HTTP API poller for a PRESENCE
 * sensor assigned to a pack (no sensor is hardcoded — see F43).
 *
 * One instance per sensor, launched by `presence-poller-manager.sh` with
 * `PRESENCE_DEVICE_ID` set to the Tuya device currently assigned to a pack.
 * The room is resolved dynamically via device → pack → room, so pack
 * re-assignments are followed without code edits.
 *
 * Only calls the Tuya Cloud API when the /live domain state justifies it
 * (door OPEN, exit_deadline, entry in progress or post-close verification
 * window) and stops while the guest is inside or the room is empty. A 120s
 * capture watchdog prevents infinite polling on a stuck door. The local
 * /live check itself costs zero quota.
 *
 * Respects /simula far_detection≤1 → ABSENT rule and forwards
 * transitions to /api/v1/tuya/webhook.
 *
 * Usage:
 *   node /root/cerraduras/api/bin/tuya-presence-poller.js
 */

// ─── Configuration ─────────────────────────────────────────────────────
const fs = require('fs');
const path = require('path');
// Load .env into process.env
(function loadEnv() {
  const envFile = path.join(__dirname, '..', '.env');
  if (fs.existsSync(envFile)) {
    fs.readFileSync(envFile, 'utf8').split('\n').forEach(line => {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith('#')) return;
      const eq = trimmed.indexOf('=');
      if (eq > 0) process.env[trimmed.slice(0, eq).trim()] = trimmed.slice(eq + 1).trim().replace(/^["']|["']$/g, '');
    });
  }
})();

// child_process must be required before ROOM_ID is resolved below (TDZ).
const { execFileSync } = require('child_process');

const ACCESS_ID     = process.env.TUYA_ACCESS_ID || '';
const ACCESS_SECRET = process.env.TUYA_ACCESS_SECRET || '';
// No sensor is hardcoded: the supervisor passes the device id as argv[2] (and
// PRESENCE_DEVICE_ID env), one poller instance per PRESENCE device assigned to a pack.
const DEVICE_ID     = process.argv[2] || process.env.PRESENCE_DEVICE_ID || '';
if (DEVICE_ID === '' && require.main === module) {
  console.error('[config] ❌ device id required as argv[2] or PRESENCE_DEVICE_ID env (no sensor is hardcoded).');
  process.exit(1);
}
// Direct execution resolves the room via device → pack → room. When the module
// is required by the unit tests it must stay side-effect free (no DB, no exit).
const ROOM_ID       = require.main === module
  ? resolveRoomId()
  : (parseInt(process.env.ROOM_ID || '', 10) || 1);
const POLL_MS              = 1000;       // local /live check interval (faster door-change detection)
const TUYA_MIN_INTERVAL_MS = 4000;        // F46+: 4s entre llamadas Tuya dentro de ventana (ahorra cuota; antes 2s)
const TUYA_FAST_INTERVAL_MS = 2000;        // kept for compatibility (windows are always fast now)
const WEBHOOK_URL   = 'http://127.0.0.1:8080/api/v1/tuya/webhook';
const LIVE_URL      = `http://127.0.0.1:8080/api/v1/rooms/${ROOM_ID}/live`;

// ─── Dependencies ─────────────────────────────────────────────────────
const crypto  = require('crypto');
const https   = require('https');
const http    = require('http');

/**
 * Resolve the room this PRESENCE device currently belongs to (canonical F30:
 * device → pack → room). ROOM_ID is used only to read the local /live
 * door-state gate; the Tuya webhook resolves the target room from the device
 * itself. Resolving at startup lets the poller follow pack re-assignments
 * (e.g. PROTO2 is room 12, not the historical room 1) without code edits.
 * Override with ROOM_ID env to force a specific room.
 */
function resolveRoomId() {
  const envRoom = parseInt(process.env.ROOM_ID || '', 10);
  if (Number.isFinite(envRoom) && envRoom > 0) return envRoom;
  try {
    const host = process.env.DB_HOST || '127.0.0.1';
    const port = process.env.DB_PORT || '3306';
    const db   = process.env.DB_NAME || 'cerraduras_db';
    const user = process.env.DB_USER || 'cerraduras_user';
    const out = execFileSync('mysql', [
      '-h', host, '-P', String(port), '-u', user, db, '-N', '-e',
      "SELECT r.id FROM rooms r JOIN devices d ON d.pack_id = r.pack_id " +
      "WHERE d.external_id = '" + DEVICE_ID + "' AND d.kind = 'PRESENCE' LIMIT 1",
    ], { env: { ...process.env, MYSQL_PWD: process.env.DB_PASS || '' }, encoding: 'utf8', timeout: 5000 });
    const id = parseInt(out.trim(), 10);
    if (Number.isFinite(id) && id > 0) return id;
  } catch (e) { /* DB unavailable — fall through to default */ }
  console.log('[config] ⚠ could not resolve room for ' + DEVICE_ID + ' — defaulting to room 1');
  return 1;
}

// ─── State ─────────────────────────────────────────────────────────────
let accessToken      = null;
let tokenExpiry      = 0;
let lastEffective    = null;
let lastFarDet       = null;
let wasCapturing     = false;
let wasStuckDoor     = false;   // F46+: log de transición de puerta atascada
// Watchdog state for the capture gate (RF-45.3). Pure reducer `nextCaptureState()`
// owns it; "inside/empty" is derived from each /live snapshot, never cached here.
let captureState     = { captureStartedAt: 0, captureBlockedUntil: 0, lastDoorState: null };
let seq              = 0;
let startTime        = Date.now();
let errorStreak      = 0;
let quotaBackoffUntil = 0;   // timestamp until which we skip Tuya calls
let lastTuyaCallAt    = 0;   // throttle: skip Tuya API calls closer than TUYA_MIN_INTERVAL_MS
let running           = true; // Fase 2 — T2.8: graceful shutdown flag

// ─── Tuya signing / http helpers (unchanged) ──────────────────────────
function tuyaSign(method, pathWithQuery, body, token) {
  const [path, queryString] = pathWithQuery.split('?');
  const contentSha = crypto.createHash('sha256').update(body).digest('hex');
  let strToSign = `${method}\n${contentSha}\n\n${path}`;
  if (queryString) {
    const params = new URLSearchParams(queryString);
    const sorted = [...params.entries()].sort((a, b) => a[0].localeCompare(b[0]));
    strToSign += '?' + sorted.map(([k, v]) => `${k}=${v}`).join('&');
  }
  const t = String(Date.now());
  const message = ACCESS_ID + token + t + strToSign;
  const sign = crypto.createHmac('sha256', ACCESS_SECRET).update(message).digest('hex').toUpperCase();
  return { sign, t, contentSha };
}

function tuyaRequest(method, pathWithQuery, body, token) {
  return new Promise((resolve, reject) => {
    const { sign, t, contentSha } = tuyaSign(method, pathWithQuery, body, token || '');
    const headers = {
      'client_id': ACCESS_ID, 'sign': sign, 'sign_method': 'HMAC-SHA256',
      't': t, 'Content-Type': 'application/json', 'Content-SHA256': contentSha,
    };
    if (token) headers['access_token'] = token;
    const opts = { hostname: 'openapi.tuyaeu.com', port: 443, path: pathWithQuery, method, headers, timeout: 8000 };
    const req = https.request(opts, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        const elapsed = Date.now() - Date.now();  // approximate
        try { resolve({ httpCode: res.statusCode, body: JSON.parse(data) }); }
        catch (e) { resolve({ httpCode: res.statusCode, body: { raw: data }, error: e.message }); }
      });
    });
    req.on('timeout', () => { req.destroy(); reject(new Error('timeout')); });
    req.on('error', err => reject(err));
    if (method === 'POST' && body) req.write(body);
    req.end();
  });
}

async function getToken() {
  if (accessToken && Date.now() < tokenExpiry) return accessToken;
  const { httpCode, body } = await tuyaRequest('GET', '/v1.0/token?grant_type=1', '', '');
  if (httpCode !== 200 || !body.success) throw new Error(`Token fail: ${body.msg}`);
  accessToken = body.result.access_token;
  tokenExpiry = Date.now() + (body.result.expire_time * 1000) - 60000;
  return accessToken;
}

async function getDeviceStatus() {
  const token = await getToken();
  return tuyaRequest('GET', `/v1.0/iot-03/devices/${DEVICE_ID}/status`, '', token);
}

// ─── Local /live check (zero Tuya quota) ──────────────────────────────
async function fetchLiveState() {
  return new Promise((resolve) => {
    const opts = { hostname: '127.0.0.1', port: 8080, path: `/api/v1/rooms/${ROOM_ID}/live`, method: 'GET', timeout: 3000 };
    const req = http.get(opts, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        try { resolve(JSON.parse(data)); }
        catch (e) { resolve(null); }
      });
    });
    req.on('error', () => resolve(null));
    req.on('timeout', () => { req.destroy(); resolve(null); });
  });
}

// ─── Webhook forward ───────────────────────────────────────────────────
async function forwardToWebhook(payload) {
  const body = JSON.stringify(payload);
  return new Promise((resolve, reject) => {
    const opts = { hostname: '127.0.0.1', port: 8080, path: '/api/v1/tuya/webhook', method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) }, timeout: 5000 };
    const req = http.request(opts, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => resolve({ httpCode: res.statusCode, body: data.substring(0, 200) }));
    });
    req.on('error', err => reject(err));
    req.write(body);
    req.end();
  });
}

// ─── Timestamps ────────────────────────────────────────────────────────
function ts() { return new Date().toISOString().replace('T',' ').substring(0,19); }

// ─── Tuya quota budget (F46+) ─────────────────────────────────────────────
// Contador compartido con la APP PHP en `api/run/tuya-quota.json`. Presupuesto
// por hora y por día: al superarlo, el poller deja de llamar (una sola fuente
// de verdad para poller + calibración). Evita repetir el agotamiento de cuota
// del 2026-09-17 (puerta atascada OPEN → ~1.400 llamadas/hora toda la noche).
const QUOTA_FILE = path.join(__dirname, '..', 'run', 'tuya-quota.json');
const TUYA_HOURLY_BUDGET = parseInt(process.env.TUYA_HOURLY_BUDGET || '150', 10);
const TUYA_DAILY_BUDGET  = parseInt(process.env.TUYA_DAILY_BUDGET  || '1000', 10);

function _quotaSlots(now) {
  const d = new Date(now || Date.now());
  return { day: d.toISOString().slice(0, 10), hour: d.toISOString().slice(0, 13) };
}

function quotaRead() {
  try {
    const q = JSON.parse(fs.readFileSync(QUOTA_FILE, 'utf8'));
    if (q && typeof q === 'object') return q;
  } catch (e) { /* missing/corrupt → defaults */ }
  return { day: null, hour: null, callsDay: 0, callsHour: 0, backoffUntil: 0 };
}

function quotaWrite(q) {
  try {
    fs.mkdirSync(path.dirname(QUOTA_FILE), { recursive: true });
    const tmp = QUOTA_FILE + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(q));
    fs.renameSync(tmp, QUOTA_FILE);
  } catch (e) { /* best-effort */ }
}

function quotaCheck(now) {
  const { day, hour } = _quotaSlots(now);
  let q = quotaRead();
  if (q.day !== day) q = { day, hour, callsDay: 0, callsHour: 0, backoffUntil: 0 };
  if (q.hour !== hour) { q.hour = hour; q.callsHour = 0; }
  if ((q.backoffUntil || 0) > (now || Date.now())) return { ok: false, reason: 'backoff', q };
  if ((q.callsDay || 0) >= TUYA_DAILY_BUDGET) return { ok: false, reason: 'daily_budget', q };
  if ((q.callsHour || 0) >= TUYA_HOURLY_BUDGET) return { ok: false, reason: 'hourly_budget', q };
  return { ok: true, q };
}

function quotaBump(now) {
  const { day, hour } = _quotaSlots(now);
  let q = quotaRead();
  if (q.day !== day) q = { day, hour, callsDay: 0, callsHour: 0, backoffUntil: 0 };
  if (q.hour !== hour) { q.hour = hour; q.callsHour = 0; }
  q.callsDay = (q.callsDay || 0) + 1;
  q.callsHour = (q.callsHour || 0) + 1;
  quotaWrite(q);
  return q;
}

function quotaBackoff(ms, now) {
  const { day, hour } = _quotaSlots(now);
  let q = quotaRead();
  if (q.day !== day) q = { day, hour, callsDay: 0, callsHour: 0, backoffUntil: 0 };
  q.backoffUntil = (now || Date.now()) + ms;
  quotaWrite(q);
  return q;
}

// ─── Capture gate (RF-45: domain state only, no sticky flags) ──────────
// Internal state of the Node process — never exposed via /live or the API (contracts.md §7).
const ENTRY_WINDOW_MS       = 90000;   // default: keep capturing while a QR entry is not yet consolidated
const EXIT_CHECK_MARGIN_MS  = 10000;   // default margin added to gap_seconds for the post-close check
const CAPTURE_MAX_MS        = 120000;  // watchdog: max continuous capture before forcing a stop
const CAPTURE_COOLDOWN_MS   = 30000;   // watchdog: cooldown base before a new capture window
const CAPTURE_COOLDOWN_MAX_MS = 600000; // F46+: techo del cooldown escalado (10 min)
const STUCK_DOOR_MS         = 300000;  // F46+: puerta OPEN sin cambio > 5 min → no capturar hasta evento nuevo

function epochMs(value) {
  if (!value) return null;
  let s = String(value).trim();
  // F44 fix: /live exposes MySQL UTC DATETIME strings without a zone
  // ("2026-09-16 14:23:24.297"). `new Date(s)` would read them as LOCAL time,
  // shifting the capture windows by the server offset (Europe/Madrid → +2h) and
  // so `entryInProgress`/`verificationWindow` never opened: after a QR entry the
  // poller made zero Tuya calls and presence was never detected. Force UTC when
  // the value carries no explicit zone.
  if (!/(?:Z|[+-]\d{2}:?\d{2})$/i.test(s)) {
    s = s.replace(' ', 'T') + 'Z';
  }
  const ms = Date.parse(s);
  return Number.isFinite(ms) ? ms : null;
}

// F44 (RF-51.2/51.4/51.6): ventanas configurables por habitación/tipo. El
// backend las expone en /live; si no llegan, se usan los defaults históricos.
function resolveEntryWindowMs(live) {
  const s = live ? Number(live.entry_window_seconds) : NaN;
  return (Number.isFinite(s) && s > 0) ? s * 1000 : ENTRY_WINDOW_MS;
}

function resolveExitCheckMs(live) {
  const s = live ? Number(live.exit_check_seconds) : NaN;
  if (Number.isFinite(s) && s > 0) return s * 1000;
  const gap = (live && Number(live.gap_seconds)) || 15;
  return gap * 1000 + EXIT_CHECK_MARGIN_MS;
}

// "Entrada en curso": a QR was consumed but the guest is not confirmed inside
// yet. /live does not expose qr.consumed_at, so we anchor on the stay's
// first_entry_at (set at QR validation); qr.consumed_at is used if a future
// backend adds it.
function entryInProgress(live, now) {
  const qr = live.qr_status || {};
  if (qr.consumed !== true) return false;
  const stay = live.active_stay || null;
  if (!stay || stay.entry_confirmed_at) return false; // already consolidated inside
  const anchor = epochMs(qr.consumed_at || stay.first_entry_at);
  if (anchor === null) return false;
  return (now - anchor) < resolveEntryWindowMs(live);
}

// Verification window after a door close: exit_check_seconds (o gap + margen).
function verificationWindow(live, now) {
  const io = live.iot_session || {};
  const closeAt = epochMs(io.last_close_at);
  if (closeAt === null) return false;
  return (now - closeAt) < resolveExitCheckMs(live);
}

/**
 * F44+ (RF-51.1.6): true when a credited exit cycle is still awaiting
 * verification, so the poller must NOT stop even if `presence_state=PRESENT`.
 *
 * After the guest is confirmed inside (`entry_confirmed_at`), a new opening
 * followed by a close is a *potential exit*. If we stop sampling just because
 * the radar still reads PRESENT (stale value, or a target beyond the door), the
 * real `none` when the guest leaves is never captured, `last_absent_since`
 * stays NULL and the exit rule can never fire (validated on PROTO2: the radar
 * reports `none` ~3 s after the guest exits).
 *
 * Requires a door CLOSED whose close is within `exit_check_seconds`, plus a
 * credited cycle: opening at/after entry confirmation, then a close.
 */
function pendingExitVerification(liveData, now) {
  if (!liveData) return false;
  now = now || Date.now();
  const stay = liveData.active_stay || null;
  if (!stay || !stay.entry_confirmed_at) return false;
  const io = liveData.iot_session || {};
  if ((io.door_state || 'UNKNOWN') !== 'CLOSED') return false;
  const entryTs = epochMs(stay.entry_confirmed_at);
  const openTs  = epochMs(io.last_open_at);
  const closeTs = epochMs(io.last_close_at);
  if (entryTs === null || openTs === null || closeTs === null) return false;
  // Credited cycle: an opening posterior to the entry confirmation, then closed.
  if (!(openTs >= entryTs) || !(closeTs >= openTs)) return false;
  return (now - closeTs) < resolveExitCheckMs(liveData);
}

/**
 * Pure gate: should this cycle spend Tuya quota? Decides ONLY from the /live
 * domain snapshot (RF-45.2) — no `presenceConfirmed`-style sticky flag.
 */
function shouldCapture(liveData, now) {
  if (!liveData) return false;
  now = now || Date.now();
  const io = liveData.iot_session || {};
  const door = io.door_state || 'UNKNOWN';
  const pres = io.presence_state || 'UNKNOWN';
  const stay = liveData.active_stay || null;
  const stayStatus = stay ? stay.status : null;
  const deadline = epochMs(liveData.exit_deadline);

  // ── Stops by domain state (RF-45.1) ──
  // F44+ (RF-51.1.6): no parar como "huésped dentro" si hay un ciclo de salida
  // acreditado pendiente de verificación; hay que capturar el `none` real aunque
  // la lectura actual sea PRESENT (obsoleta o de un objetivo fuera de la puerta).
  const inside = stayStatus === 'OCCUPIED' && pres === 'PRESENT' && door === 'CLOSED'
    && !!(stay && stay.entry_confirmed_at)         // huésped dentro
    && !pendingExitVerification(liveData, now);
  if (inside) return false;

  const empty = !stay && pres === 'ABSENT' && deadline === null; // habitación 100% vacía
  if (empty) return false;

  // ── Captures (RF-45.1.3) ──
  if (door === 'OPEN') return true;              // entrada/salida en curso
  if (deadline !== null && deadline > now) return true; // verificación de salida
  if (entryInProgress(liveData, now)) return true;      // QR reciente, aún no consolidado
  if (verificationWindow(liveData, now)) return true;   // cierre reciente + gap

  return false;
}

/**
 * Pure watchdog reducer (RF-45.3). Given the /live snapshot, `now` and the
 * previous watchdog state, returns the capture decision + new state. A stuck
 * OPEN door is cut after CAPTURE_MAX_MS; the caller logs `watchdogFired`.
 */
function nextCaptureState(liveData, now, state) {
  now = now || Date.now();
  const prev = state || {};
  const io = (liveData && liveData.iot_session) || {};
  const door = io.door_state || 'UNKNOWN';

  let captureStartedAt = prev.captureStartedAt || 0;
  let captureBlockedUntil = prev.captureBlockedUntil || 0;
  let consecutiveWatchdogs = prev.consecutiveWatchdogs || 0;
  let doorChangedAt = prev.doorChangedAt || 0;
  const lastDoorState = prev.lastDoorState != null ? prev.lastDoorState : null;

  // A door change is a NEW legitimate event: rearm cooldown and reset the
  // stuck-door / escalating-cooldown counters (RF-45.3.4).
  if (door !== lastDoorState) {
    captureBlockedUntil = 0;
    consecutiveWatchdogs = 0;
    doorChangedAt = now;
  }
  if (!doorChangedAt) doorChangedAt = now;

  // F46+ (anti-drenaje): una puerta OPEN que no cambia durante > STUCK_DOOR_MS
  // se considera atascada (p. ej. se perdió el CLOSED). Dejamos de capturar
  // hasta un evento de puerta nuevo; así no se repite el bucle watchdog +
  // re-captura que agotó la cuota de Tuya.
  const stuckDoor = (door === 'OPEN') && ((now - doorChangedAt) > STUCK_DOOR_MS);

  let want = shouldCapture(liveData, now);
  if (stuckDoor) want = false;

  if (!want) {
    // No domain-justified window: clear the watchdog entirely.
    captureStartedAt = 0;
    captureBlockedUntil = 0;
  } else if (captureStartedAt === 0) {
    captureStartedAt = now;
  }

  // Watchdog: max continuous capture (stuck OPEN door / inconsistent state).
  // El cooldown ESCALA con los watchdog consecutivos (30s→1→2→5→10 min) para
  // que un OPEN persistente no consuma cuota sin límite.
  let watchdogFired = false;
  if (want && captureStartedAt > 0 && (now - captureStartedAt) > CAPTURE_MAX_MS) {
    watchdogFired = true;
    consecutiveWatchdogs++;
    const cooldown = Math.min(
      CAPTURE_COOLDOWN_MS * Math.pow(2, consecutiveWatchdogs - 1),
      CAPTURE_COOLDOWN_MAX_MS
    );
    captureBlockedUntil = now + cooldown;
    captureStartedAt = 0;
    want = false;
  }

  // Cooldown beats a still-true domain gate.
  if (now < captureBlockedUntil) want = false;

  return {
    capturing: want,
    watchdogFired,
    stuckDoor,
    state: { captureStartedAt, captureBlockedUntil, lastDoorState: door, consecutiveWatchdogs, doorChangedAt },
  };
}

// ─── Main ─────────────────────────────────────────────────────────────
async function main() {
  console.log(`[${ts()}] ═══ PRESENCE Poller (GATED) ═══`);
  console.log(`  Device : ${DEVICE_ID}`);
  console.log(`  Room   : ${ROOM_ID} (live gate)`);
  console.log(`  Gate   : door OPEN | exit_deadline | entry-in-progress (<${ENTRY_WINDOW_MS/1000}s) | verify-window post-close`);
  console.log(`  Tuya   : min ${TUYA_MIN_INTERVAL_MS/1000}s between calls (quota saving)`);
  console.log(`  Rule   : far_detection≤1 → ABSENT`);
  console.log(`  Stop   : inside (OCCUPIED+PRESENT+CLOSED+entry_confirmed) | empty (no stay+ABSENT) | watchdog ${CAPTURE_MAX_MS/1000}s → cooldown ${CAPTURE_COOLDOWN_MS/1000}s`);
  console.log(`  Quota  : backoff 10min on exhaustion\n`);

  // Initial token (needed even for first Tuya call)
  try { await getToken(); console.log(`[${ts()}] ✅ Tuya token ready`); }
  catch (e) { console.error(`[${ts()}] ❌ ${e.message}`); process.exit(1); }

  // Establish baseline from local state
  try {
    const live = await fetchLiveState();
    const io = live ? (live.iot_session || {}) : {};
    captureState.lastDoorState = io.door_state || 'UNKNOWN';
    lastEffective = io.presence_state || 'ABSENT';
    console.log(`[${ts()}] 📡 Baseline from /live: door=${captureState.lastDoorState} pres=${lastEffective}`);
  } catch (e) { /* non-critical */ }

  // ─── Poll loop ──────────────────────────────────────────────────
  while (running) {
    seq++;
    await new Promise(r => setTimeout(r, POLL_MS));

    // Always check local /live (zero quota cost) for gating
    let liveData = null;
    try { liveData = await fetchLiveState(); } catch (e) { /* ignore */ }

    const gate = nextCaptureState(liveData, Date.now(), captureState);
    const capturing = gate.capturing;
    captureState = gate.state;
    const quotaOn = quotaBackoffUntil > Date.now();

    if (gate.watchdogFired) {
      const io = (liveData && liveData.iot_session) || {};
      const stay = (liveData && liveData.active_stay) || null;
      const n = captureState.consecutiveWatchdogs || 0;
      const cd = Math.min(CAPTURE_COOLDOWN_MS * Math.pow(2, Math.max(0, n - 1)), CAPTURE_COOLDOWN_MAX_MS) / 1000;
      console.error(`[${ts()}] ⚠ watchdog de captura #${n}: forzando parada (door=${io.door_state || 'UNKNOWN'}, stay=${stay ? stay.status : 'none'}, deadline=${(liveData && liveData.exit_deadline) || 'none'}) — cooldown ${cd}s`);
    }
    if (gate.stuckDoor && !wasStuckDoor) {
      console.error(`[${ts()}] 🧱 puerta OPEN sin cambios > ${STUCK_DOOR_MS / 1000}s — muestreo detenido hasta nuevo evento de puerta (anti-cuota)`);
    }
    wasStuckDoor = !!gate.stuckDoor;

    // Log capture-window transitions
    if (capturing && !wasCapturing) {
      console.log(`[${ts()}] 🎯 Entering capture window (door=${captureState.lastDoorState})`);
      // F44 (RF-51.2): muestreo INMEDIATO al abrirse la ventana (p.ej. puerta
      // OPEN) sin esperar al throttle de 2 s.
      lastTuyaCallAt = 0;
    } else if (!capturing && wasCapturing) {
      console.log(`[${ts()}] 💤 Exiting capture window — back to idle`);
    }
    wasCapturing = capturing;

    if (!capturing) {
      // Idle: no Tuya calls needed
      if (seq % 40 === 0) {  // heartbeat every ~60s
        const state = lastEffective || 'UNKNOWN';
        console.log(`[${ts()}] 💤 idle (door=${captureState.lastDoorState}, not in capture window) state=${state}`);
      }
      continue;
    }

    if (quotaOn) {
      // Quota exhausted — skip Tuya but still track local state
      if (seq % 10 === 0) {
        const remaining = Math.round((quotaBackoffUntil - Date.now()) / 1000);
        console.log(`[${ts()}] ⏸ quota backoff (${remaining}s left) — skipping Tuya call`);
      }
      continue;
    }

    // Throttle (F44, RF-51.5; F46+ 4 s).
    const minInterval = TUYA_MIN_INTERVAL_MS;
    const sinceLastTuya = Date.now() - lastTuyaCallAt;
    if (sinceLastTuya < minInterval) {
      continue;  // still in capture window; will retry next tick
    }

    // F46+ (anti-cuota): presupuesto por hora/día ANTES de gastar la llamada.
    const budget = quotaCheck();
    if (!budget.ok) {
      if (seq % 20 === 0) {
        console.log(`[${ts()}] ⏸ presupuesto Tuya (${budget.reason}) día=${budget.q.callsDay} hora=${budget.q.callsHour} — sin llamadas`);
      }
      continue;
    }

    // ─── Capturing: call Tuya API ─────────────────────────────────
    try {
      lastTuyaCallAt = Date.now();  // mark before call to prevent parallel calls
      const { httpCode, body } = await getDeviceStatus();
      quotaBump();                  // F46+: contar la llamada (presupuesto compartido)
      errorStreak = 0;

      // Detect quota exhaustion (exact message from Tuya)
      const msg = (body && body.msg) || '';
      if (msg.toLowerCase().includes('quota') || msg.toLowerCase().includes('exhausted')) {
        quotaBackoffUntil = Date.now() + 600000;  // 10 minutes
        quotaBackoff(600000);                      // F46+: backoff también compartido con la app
        console.error(`[${ts()}] 🚫 Tuya quota exhausted — backing off 10 min`);
        continue;
      }

      if (httpCode !== 200 || !body.success) {
        console.error(`[${ts()}] ⚠ Tuya API: ${msg || 'HTTP '+httpCode}`);
        continue;
      }

      // Parse DPs
      const dps = {};
      for (const dp of (body.result || [])) dps[dp.code] = dp.value;

      const farDet      = dps.far_detection;
      const rawPresence = dps.presence_state || '';
      const distance    = dps.target_dis_closest;

      // Effective presence (F44, RF-52): SOLO `presence_state` decide. `far_detection`
      // es config de radio (cm), no señal de presencia: se elimina la regla far≤1
      // (heredada del ZY-M100) que convertía el radio en un falso ABSENT.
      // Se aceptan tanto ZY-M100 ("presence") como 24G V3 ("move") como PRESENT.
      const effective = (rawPresence === 'presence' || rawPresence === 'move')
        ? 'PRESENT'
        : 'ABSENT';

      // Transition → forward to webhook
      if (effective !== lastEffective && lastEffective !== null) {
        const label = effective === 'PRESENT' ? '🧑 PRESENTE' : '🚫 AUSENTE';
        console.log(`[${ts()}] 🔄 ${lastEffective} → ${label} (raw=${rawPresence}, far=${farDet}${distance != null ? ', dist='+distance+'cm' : ''})`);

        const now = new Date();
        await forwardToWebhook({
          devId: DEVICE_ID,
          status: [{ code: 'presence_state', value: rawPresence, t: now.getTime() }],
          _poller_effective: effective,
          _poller_far_detection: farDet,
        }).catch(e => console.error(`[${ts()}]   ↳ Webhook FAIL: ${e.message}`));

        lastEffective = effective;

        // No sticky pause here: the gate stops on the next /live snapshot once
        // the domain state says "inside" (OCCUPIED+PRESENT+CLOSED+entry_confirmed).
        if (effective === 'PRESENT') {
          console.log(`[${ts()}] ✅ Presence confirmed (gate will stop once stay is consolidated inside)`);
        }
      }

      if (farDet !== lastFarDet) {
        console.log(`[${ts()}] ⚙ far_detection: ${lastFarDet} → ${farDet}`);
        lastFarDet = farDet;
      }

      // Heartbeat during capture
      if (seq % 20 === 0) {
        const running = Math.round((Date.now() - startTime) / 1000);
        console.log(`[${ts()}] 📡 capturing (running ${running}s, state=${effective}, far=${farDet})`);
      }

    } catch (e) {
      errorStreak++;
      if (errorStreak <= 3) console.error(`[${ts()}] ⚠ ${e.message}`);
      if (errorStreak === 3) console.error(`[${ts()}] (suppressing further errors)`);
    }
  }

  console.log(`[${ts()}] ✅ Shutdown complete.`);
  process.exit(0);
}

// ── Graceful shutdown (Fase 2 — T2.8) ──
process.on('SIGTERM', () => {
  console.log(`[${ts()}] SIGTERM received — finishing current cycle...`);
  running = false;
});
process.on('SIGINT', () => {
  console.log(`[${ts()}] SIGINT received — finishing current cycle...`);
  running = false;
});

// Only start the poll loop when executed directly. Requiring the module (unit
// tests) must not start the poller, touch the DB or the network.
if (require.main === module) {
  main();
}

module.exports = {
  shouldCapture,
  nextCaptureState,
  entryInProgress,
  verificationWindow,
  pendingExitVerification,
  epochMs,
  resolveEntryWindowMs,
  resolveExitCheckMs,
  ENTRY_WINDOW_MS,
  EXIT_CHECK_MARGIN_MS,
  CAPTURE_MAX_MS,
  CAPTURE_COOLDOWN_MS,
  CAPTURE_COOLDOWN_MAX_MS,
  STUCK_DOOR_MS,
  TUYA_MIN_INTERVAL_MS,
};
