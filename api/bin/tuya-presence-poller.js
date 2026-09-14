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
 * Only calls the Tuya Cloud API when the door is OPEN or within
 * an active verification window (exit_deadline on /live), reducing
 * API quota consumption by ~99%. The rest of the time it checks the
 * local /live endpoint (cost zero quota).
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
if (DEVICE_ID === '') {
  console.error('[config] ❌ device id required as argv[2] or PRESENCE_DEVICE_ID env (no sensor is hardcoded).');
  process.exit(1);
}
const ROOM_ID       = resolveRoomId();
const POLL_MS              = 1000;       // local /live check interval (faster door-change detection)
const TUYA_MIN_INTERVAL_MS = 5000;        // default min time between Tuya API calls (quota saving)
const TUYA_FAST_INTERVAL_MS = 2000;        // faster throttle during countdown/verification (exit_deadline active)
const WEBHOOK_URL   = 'http://127.0.0.1:8080/api/v1/tuya/webhook';
const LIVE_URL      = `http://127.0.0.1:8080/api/v1/rooms/${ROOM_ID}/live`;
const DOOR_WINDOW_MS = parseInt(process.env.PRESENCE_WINDOW_MS || '30000', 10); // max capture window after door change (default 30s)

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
let lastDoorState    = null;
let lastDoorChanged  = 0;
let wasCapturing     = false;
let presenceConfirmed = false; // stop polling once PRESENT is confirmed until next door change
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

// ─── Capture gate ─────────────────────────────────────────────────────
function isCaptureWindow(liveData) {
  if (!liveData) return false;
  const io = liveData.iot_session || {};
  const door = io.door_state || 'UNKNOWN';
  const stay = liveData.active_stay;
  const closeWindowS = liveData.gap_seconds || 15; // F31: effective gap for verification hold

  // Detect door state change → reset confirmed flag so we start polling again
  if (door !== lastDoorState) {
    lastDoorState = door;
    lastDoorChanged = Date.now();
    presenceConfirmed = false;
  }

  // Gate 1: door open → capture
  if (door === 'OPEN') return true;

  // Gate 4 (F31): verification window after door close while stay is OCCUPIED.
  // Keeps polling even if presence was previously confirmed, because the door
  // opened and closed again — we need to verify whether the guest left or
  // someone else entered. Uses last_close_at from /live as anchor.
  if (stay && stay.status === 'OCCUPIED'
      && door === 'CLOSED'
      && io.last_close_at)
  {
    const closeAt = new Date(io.last_close_at).getTime();
    // Verification window = gap_seconds + 10s margin (to tolerate radar retention)
    const verifyWindowMs = (closeWindowS + 10) * 1000;
    if ((Date.now() - closeAt) < verifyWindowMs) return true;
  }

  // Once presence is confirmed AND door is closed AND outside the verification
  // window above, stop polling until next door event (guest is inside).
  if (presenceConfirmed && door === 'CLOSED') return false;

  // Gate 2: exit deadline active (verification window)
  if (liveData.exit_deadline) {
    const dl = new Date(liveData.exit_deadline);
    if (dl > new Date()) return true;
  }

  // Gate 3: within DOOR_WINDOW_MS of last door state change
  if (lastDoorChanged > 0 && (Date.now() - lastDoorChanged) < DOOR_WINDOW_MS) return true;

  return false;
}

// ─── Main ─────────────────────────────────────────────────────────────
async function main() {
  console.log(`[${ts()}] ═══ PRESENCE Poller (GATED) ═══`);
  console.log(`  Device : ${DEVICE_ID}`);
  console.log(`  Room   : ${ROOM_ID} (live gate)`);
  console.log(`  Gate   : door OPEN | exit_deadline | ${DOOR_WINDOW_MS/1000}s post-door-change | verify-window post-close (F31)`);
  console.log(`  Tuya   : min ${TUYA_MIN_INTERVAL_MS/1000}s between calls (quota saving)`);
  console.log(`  Rule   : far_detection≤1 → ABSENT`);
  console.log(`  Stop   : PRESENT confirmed + door closed + outside verify-window → pause`);
  console.log(`  Quota  : backoff 10min on exhaustion\n`);

  // Initial token (needed even for first Tuya call)
  try { await getToken(); console.log(`[${ts()}] ✅ Tuya token ready`); }
  catch (e) { console.error(`[${ts()}] ❌ ${e.message}`); process.exit(1); }

  // Establish baseline from local state
  try {
    const live = await fetchLiveState();
    const io = live ? (live.iot_session || {}) : {};
    lastDoorState = io.door_state || 'UNKNOWN';
    lastEffective = io.presence_state || 'ABSENT';
    console.log(`[${ts()}] 📡 Baseline from /live: door=${lastDoorState} pres=${lastEffective}`);
  } catch (e) { /* non-critical */ }

  // ─── Poll loop ──────────────────────────────────────────────────
  while (running) {
    seq++;
    await new Promise(r => setTimeout(r, POLL_MS));

    // Always check local /live (zero quota cost) for gating
    let liveData = null;
    try { liveData = await fetchLiveState(); } catch (e) { /* ignore */ }

    const capturing = isCaptureWindow(liveData);
    const quotaOn = quotaBackoffUntil > Date.now();

    // Log capture-window transitions
    if (capturing && !wasCapturing) {
      console.log(`[${ts()}] 🎯 Entering capture window (door=${lastDoorState})`);
    } else if (!capturing && wasCapturing) {
      console.log(`[${ts()}] 💤 Exiting capture window — back to idle`);
    }
    wasCapturing = capturing;

    if (!capturing) {
      // Idle: no Tuya calls needed
      if (seq % 40 === 0) {  // heartbeat every ~60s
        const state = lastEffective || 'UNKNOWN';
        console.log(`[${ts()}] 💤 idle (door=${lastDoorState}, not in capture window) state=${state}`);
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

    // Throttle: dynamic — faster (2s) during countdown/verification,
    // standard (5s) during other capture windows
    const hasDeadline = liveData && liveData.exit_deadline
        && new Date(liveData.exit_deadline) > new Date();
    const minInterval = hasDeadline ? TUYA_FAST_INTERVAL_MS : TUYA_MIN_INTERVAL_MS;
    const sinceLastTuya = Date.now() - lastTuyaCallAt;
    if (sinceLastTuya < minInterval) {
      continue;  // still in capture window; will retry next tick
    }

    // ─── Capturing: call Tuya API ─────────────────────────────────
    try {
      lastTuyaCallAt = Date.now();  // mark before call to prevent parallel calls
      const { httpCode, body } = await getDeviceStatus();
      errorStreak = 0;

      // Detect quota exhaustion (exact message from Tuya)
      const msg = (body && body.msg) || '';
      if (msg.toLowerCase().includes('quota') || msg.toLowerCase().includes('exhausted')) {
        quotaBackoffUntil = Date.now() + 600000;  // 10 minutes
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

      // Effective presence: respect /simula OFF; accept both ZY-M100 ("presence")
      // and 24G V3 ("move") as PRESENT.
      const effective = (farDet <= 1)
        ? 'ABSENT'
        : ((rawPresence === 'presence' || rawPresence === 'move') ? 'PRESENT' : 'ABSENT');

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

        // Once we confirm PRESENT, stop polling until the next door change.
        // Guest is inside — no need to keep checking Tuya.
        if (effective === 'PRESENT') {
          presenceConfirmed = true;
          console.log(`[${ts()}] ✅ Presence confirmed — pausing Tuya polls until next door event`);
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

main();
