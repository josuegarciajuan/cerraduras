#!/usr/bin/env node
'use strict';

/**
 * Unit tests for the presence-poller capture gate (Fase 41, TSK-F41-11 / TSK-F41-12).
 *
 * Trazabilidad: RF-45.1, RF-45.2, RF-45.3 · design.md §4.3/§4.4 · contracts.md §7.
 *
 * Pure-logic tests only: no network, no DB, no Tuya quota. The module under
 * test guards `main()` with `require.main === module`, so requiring it here does
 * not start the poller.
 *
 * Run: node api/tests/Unit/presence-poller-gate.test.js
 */

const path = require('path');

const {
  shouldCapture,
  nextCaptureState,
  entryInProgress,
  verificationWindow,
  epochMs,
  resolveEntryWindowMs,
  resolveExitCheckMs,
  ENTRY_WINDOW_MS,
  EXIT_CHECK_MARGIN_MS,
  CAPTURE_MAX_MS,
  CAPTURE_COOLDOWN_MS,
} = require(path.join(__dirname, '..', '..', 'bin', 'tuya-presence-poller.js'));

let passed = 0;
let failed = 0;

function check(name, cond) {
  if (cond) {
    passed++;
    console.log('  \u2705 ' + name);
  } else {
    failed++;
    console.error('  \u274c ' + name);
  }
}

const NOW = 1800000000000; // fixed epoch for deterministic tests
function iso(ms) { return new Date(ms).toISOString(); }
// Production /live shape: MySQL UTC DATETIME without zone (what the poller
// actually receives). Regression: these used to be parsed as local time.
function mysqlUtc(ms) { return iso(ms).replace('T', ' ').replace('Z', ''); }

function live({ iot = {}, stay = null, deadline = null, qr = {}, gap = 15, entryWindow = null, exitCheck = null } = {}) {
  const out = {
    iot_session: iot,
    active_stay: stay,
    exit_deadline: deadline,
    qr_status: qr,
    gap_seconds: gap,
  };
  if (entryWindow !== null) out.entry_window_seconds = entryWindow;
  if (exitCheck !== null) out.exit_check_seconds = exitCheck;
  return out;
}

console.log('\n\u2500\u2500 presence-poller gate (Fase 41) \u2500\u2500');

// ─── 1. Stops by domain state ──────────────────────────────────────────
check('inside (OCCUPIED + PRESENT + CLOSED + entry_confirmed_at) \u2192 stop',
  shouldCapture(live({
    iot: { door_state: 'CLOSED', presence_state: 'PRESENT' },
    stay: { status: 'OCCUPIED', entry_confirmed_at: iso(NOW - 1000), first_entry_at: iso(NOW - 60000) },
  }), NOW) === false);

check('inside without entry_confirmed_at is NOT considered inside',
  shouldCapture(live({
    iot: { door_state: 'CLOSED', presence_state: 'PRESENT', last_close_at: iso(NOW - 1000) },
    stay: { status: 'OCCUPIED', entry_confirmed_at: null, first_entry_at: iso(NOW - 60000) },
  }), NOW) === true);

check('empty (no stay + ABSENT + no deadline) \u2192 stop',
  shouldCapture(live({
    iot: { door_state: 'CLOSED', presence_state: 'ABSENT' },
    stay: null,
  }), NOW) === false);

check('null snapshot \u2192 stop',
  shouldCapture(null, NOW) === false);

check('unknown state without any justification \u2192 stop',
  shouldCapture(live({ iot: { door_state: 'CLOSED', presence_state: 'UNKNOWN' } }), NOW) === false);

// ─── 2. Captures by domain state ───────────────────────────────────────
check('door OPEN \u2192 capture',
  shouldCapture(live({ iot: { door_state: 'OPEN', presence_state: 'PRESENT' } }), NOW) === true);

check('active exit_deadline \u2192 capture',
  shouldCapture(live({
    iot: { door_state: 'CLOSED', presence_state: 'ABSENT' },
    stay: { status: 'OCCUPIED', entry_confirmed_at: iso(NOW - 5000) },
    deadline: iso(NOW + 15000),
  }), NOW) === true);

check('expired exit_deadline does not capture',
  shouldCapture(live({
    iot: { door_state: 'CLOSED', presence_state: 'ABSENT' },
    stay: { status: 'OCCUPIED', entry_confirmed_at: iso(NOW - 5000) },
    deadline: iso(NOW - 1),
  }), NOW) === false);

check('entry in progress (qr consumed, not consolidated, recent) \u2192 capture',
  shouldCapture(live({
    iot: { door_state: 'CLOSED', presence_state: 'ABSENT' },
    stay: { status: 'OCCUPIED', entry_confirmed_at: null, first_entry_at: iso(NOW - 30000) },
    qr: { consumed: true, stay_id: 1 },
  }), NOW) === true);

check('entryInProgress expires after ENTRY_WINDOW_MS',
  entryInProgress(live({
    iot: { door_state: 'CLOSED', presence_state: 'ABSENT' },
    stay: { status: 'OCCUPIED', entry_confirmed_at: null, first_entry_at: iso(NOW - ENTRY_WINDOW_MS - 1000) },
    qr: { consumed: true, stay_id: 1 },
  }), NOW) === false);

check('entryInProgress false once entry_confirmed_at is set',
  entryInProgress(live({
    stay: { status: 'OCCUPIED', entry_confirmed_at: iso(NOW - 1000), first_entry_at: iso(NOW - 30000) },
    qr: { consumed: true, stay_id: 1 },
  }), NOW) === false);

check('verification window after recent close \u2192 capture',
  shouldCapture(live({
    iot: { door_state: 'CLOSED', presence_state: 'PRESENT', last_close_at: iso(NOW - 5000) },
    stay: { status: 'OCCUPIED', entry_confirmed_at: null, first_entry_at: iso(NOW - 60000) },
    gap: 15,
  }), NOW) === true);

check('verificationWindow expires after gap + 10s',
  verificationWindow(live({
    iot: { door_state: 'CLOSED', last_close_at: iso(NOW - 26000) },
    gap: 15,
  }), NOW) === false);

// ─── 3. Watchdog / cooldown (stuck OPEN door) ──────────────────────────
const stuckOpen = live({ iot: { door_state: 'OPEN', presence_state: 'UNKNOWN' } });
let state = { captureStartedAt: 0, captureBlockedUntil: 0, lastDoorState: null };

let r = nextCaptureState(stuckOpen, NOW, state);
check('watchdog: capture window starts and timer anchors at now',
  r.capturing === true && r.watchdogFired === false && r.state.captureStartedAt === NOW);

r = nextCaptureState(stuckOpen, NOW + CAPTURE_MAX_MS - 1, r.state);
check('watchdog: still capturing just before CAPTURE_MAX_MS',
  r.capturing === true && r.watchdogFired === false);

const firedAt = NOW + CAPTURE_MAX_MS + 1;
r = nextCaptureState(stuckOpen, firedAt, r.state);
check('watchdog: stuck OPEN door is cut after CAPTURE_MAX_MS',
  r.capturing === false && r.watchdogFired === true);
check('watchdog: sets cooldown for CAPTURE_COOLDOWN_MS',
  r.state.captureBlockedUntil === firedAt + CAPTURE_COOLDOWN_MS &&
  r.state.captureStartedAt === 0);

const blockedState = r.state;
r = nextCaptureState(stuckOpen, firedAt + 2000, blockedState);
check('cooldown holds while the domain gate is still true (no Tuya quota)',
  r.capturing === false && r.watchdogFired === false);

// ─── 4. Rearm on door change (new legitimate window) ───────────────────
const rearmAt = firedAt + 2000;
const rearmLive = live({
  iot: { door_state: 'CLOSED', presence_state: 'ABSENT' },
  stay: { status: 'OCCUPIED', entry_confirmed_at: iso(NOW - 1000) },
  deadline: iso(rearmAt + 60000),
});
r = nextCaptureState(rearmLive, rearmAt, blockedState);
check('rearm: door change clears cooldown and a legitimate window captures again',
  r.capturing === true && r.state.captureBlockedUntil === 0 && r.watchdogFired === false);

// ─── 5. F44: configurable windows (RF-51.2 / RF-51.4 / RF-51.6) ────────
check('resolveEntryWindowMs: default when /live does not expose it',
  resolveEntryWindowMs(live({})) === ENTRY_WINDOW_MS);

check('resolveEntryWindowMs: honors live.entry_window_seconds',
  resolveEntryWindowMs(live({ iot: {}, entryWindow: 30 })) === 30000);

check('resolveExitCheckMs: default = gap + margin',
  resolveExitCheckMs(live({ gap: 15 })) === 15000 + EXIT_CHECK_MARGIN_MS);

check('resolveExitCheckMs: honors live.exit_check_seconds',
  resolveExitCheckMs({ exit_check_seconds: 25 }) === 25000);

check('entryInProgress: uses live.entry_window_seconds (30s) instead of 90s',
  entryInProgress(live({
    iot: { door_state: 'CLOSED' },
    stay: { status: 'OCCUPIED', entry_confirmed_at: null, first_entry_at: iso(NOW - 40000) },
    qr: { consumed: true, stay_id: 1 },
    entryWindow: 30,
  }), NOW) === false);

check('verificationWindow: uses live.exit_check_seconds (25s)',
  verificationWindow(live({
    iot: { door_state: 'CLOSED', last_close_at: iso(NOW - 20000) },
    exitCheck: 25,
  }), NOW) === true);

check('verificationWindow: expires with configured exit_check_seconds',
  verificationWindow(live({
    iot: { door_state: 'CLOSED', last_close_at: iso(NOW - 26000) },
    exitCheck: 25,
  }), NOW) === false);

// ─── 6. UTC MySQL timestamps without zone (real /live format) ──────────
// Regression (F44): /live sends "YYYY-MM-DD HH:MM:SS.mmm" (UTC, no `Z`).
// `new Date()` parsed it as local time, so entry/verify windows never opened
// and the poller made zero Tuya calls after a QR entry.
check('epochMs: MySQL UTC timestamp without zone parses as UTC',
  epochMs(mysqlUtc(NOW)) === NOW);

check('epochMs: explicit-Z ISO timestamp still parses as UTC',
  epochMs(iso(NOW)) === NOW);

check('entryInProgress: MySQL UTC first_entry_at within window → capture',
  entryInProgress(live({
    iot: { door_state: 'CLOSED', presence_state: 'ABSENT' },
    stay: { status: 'OCCUPIED', entry_confirmed_at: null, first_entry_at: mysqlUtc(NOW - 30000) },
    qr: { consumed: true, stay_id: 1 },
  }), NOW) === true);

check('entryInProgress: MySQL UTC beyond ENTRY_WINDOW_MS → no capture',
  entryInProgress(live({
    iot: { door_state: 'CLOSED' },
    stay: { status: 'OCCUPIED', entry_confirmed_at: null, first_entry_at: mysqlUtc(NOW - ENTRY_WINDOW_MS - 1000) },
    qr: { consumed: true, stay_id: 1 },
  }), NOW) === false);

check('verificationWindow: MySQL UTC last_close_at within window → capture',
  verificationWindow(live({
    iot: { door_state: 'CLOSED', last_close_at: mysqlUtc(NOW - 5000) },
    gap: 15,
  }), NOW) === true);

check('verificationWindow: MySQL UTC last_close_at beyond window → no capture',
  verificationWindow(live({
    iot: { door_state: 'CLOSED', last_close_at: mysqlUtc(NOW - 30000) },
    gap: 15,
  }), NOW) === false);

check('shouldCapture: real /live shape (MySQL UTC, door CLOSED, entry pending) → capture',
  shouldCapture(live({
    iot: { door_state: 'CLOSED', presence_state: 'ABSENT', last_close_at: mysqlUtc(NOW - 2000) },
    stay: { status: 'OCCUPIED', entry_confirmed_at: null, first_entry_at: mysqlUtc(NOW - 20000) },
    qr: { consumed: true, stay_id: 1 },
    gap: 15,
    entryWindow: 90,
    exitCheck: 25,
  }), NOW) === true);

// ─── 7. Module is require-safe ─────────────────────────────────────────
check('module exports the pure gate (require did not start the poller)',
  typeof shouldCapture === 'function' && typeof nextCaptureState === 'function');

// ─── Summary ───────────────────────────────────────────────────────────
console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' presence-poller gate: ' + passed + ' passed, ' + failed + ' failed\n');
if (failed > 0) {
  process.exit(1);
}
process.exit(0);
