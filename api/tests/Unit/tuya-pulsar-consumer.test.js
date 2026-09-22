#!/usr/bin/env node
'use strict';

/**
 * Unit tests for the dynamic device discovery / resync of the Tuya Pulsar
 * consumer (F44, TSK-F44-06).
 *
 * Trazabilidad: RF-50.1 (sin ids hardcodeados), RF-50.3 (resync puntual).
 *
 * Pure-logic tests only: no network, no DB, no Tuya quota. The consumer guards
 * its start with `require.main === module`, so requiring it here does not open
 * the WS nor touch the DB.
 *
 * Run: node api/tests/Unit/tuya-pulsar-consumer.test.js
 */

const path = require('path');

const {
  parseDeviceIds,
  isKnownDevice,
  buildStatusPayload,
  mapToPresenceEvent,
  TRACKED_KINDS,
  RESYNC_KINDS,
  shouldResync,
  silenceExceeded,
  receiveLatencyMs,
  pongAgeMs,
} = require(path.join(__dirname, '..', '..', 'bin', 'tuya-pulsar-consumer', 'index.js'));

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

console.log('\n\u2500\u2500 tuya-pulsar-consumer dynamic discovery (F44) \u2500\u2500');

// ─── 1. parseDeviceIds ─────────────────────────────────────────────────
check('parseDeviceIds trims blank lines and duplicated ids',
  JSON.stringify(parseDeviceIds('a\n\n b \na\n')) === JSON.stringify(['a', 'b']));

check('parseDeviceIds tolerates empty/null output',
  Array.isArray(parseDeviceIds('')) && parseDeviceIds('').length === 0 &&
  parseDeviceIds(null).length === 0);

// ─── 2. isKnownDevice (no hardcoded ids) ───────────────────────────────
check('isKnownDevice accepts an id present in the DB-derived list',
  isKnownDevice('bf9a278e76e2c3f01ay0cs', ['bf9a278e76e2c3f01ay0cs']) === true);

check('isKnownDevice rejects an id outside the list',
  isKnownDevice('deadbeef', ['bf9a278e76e2c3f01ay0cs']) === false);

check('isKnownDevice rejects empty/undefined ids',
  isKnownDevice('', ['x']) === false && isKnownDevice(undefined, ['x']) === false);

// ─── 3. buildStatusPayload (REST /status → ingress shape) ──────────────
check('buildStatusPayload maps Tuya DP list into {devId, status[]}',
  JSON.stringify(buildStatusPayload('dev1', [
    { code: 'doorcontact_state', value: true, t: 111 },
  ])) === JSON.stringify({ devId: 'dev1', status: [{ code: 'doorcontact_state', value: true, t: 111 }] }));

check('buildStatusPayload returns null for empty/non-array results',
  buildStatusPayload('dev1', []) === null &&
  buildStatusPayload('dev1', null) === null);

check('buildStatusPayload drops entries without code', (() => {
  const p = buildStatusPayload('dev1', [{ value: 1 }, { code: 'presence_state', value: 'move' }]);
  return p !== null && p.status.length === 1 && p.status[0].code === 'presence_state';
})());

// ─── 4. mapToPresenceEvent uses the dynamic list ───────────────────────
const ids = ['bf4c7e7d2cef28cea2nkwk', 'bf9a278e76e2c3f01ay0cs'];

check('mapToPresenceEvent maps door OPEN for a tracked device',
  (() => {
    const ev = mapToPresenceEvent({ devId: 'bf4c7e7d2cef28cea2nkwk', status: [{ code: 'doorcontact_state', value: true }] }, ids);
    return ev && ev.doorOpen === 'OPEN' && ev.doorcontact_state === true;
  })());

check('mapToPresenceEvent maps door CLOSED for a tracked device',
  (() => {
    const ev = mapToPresenceEvent({ devId: 'bf4c7e7d2cef28cea2nkwk', status: [{ code: 'doorcontact_state', value: false }] }, ids);
    return ev && ev.doorOpen === 'CLOSED';
  })());

check('mapToPresenceEvent maps 24G move → present for a tracked device',
  (() => {
    const ev = mapToPresenceEvent({ devId: 'bf9a278e76e2c3f01ay0cs', status: [{ code: 'presence_state', value: 'move' }] }, ids);
    return ev && ev.present === true && ev.presence_state === 'move';
  })());

check('mapToPresenceEvent ignores devices outside the DB-derived list',
  mapToPresenceEvent({ devId: 'unknown-dev', status: [{ code: 'doorcontact_state', value: true }] }, ids) === null);

// ─── 5. Module is require-safe ─────────────────────────────────────────
check('module exports the pure helpers (require did not start the consumer)',
  typeof parseDeviceIds === 'function' && typeof buildStatusPayload === 'function');

// ─── 6. F47 (RF-54): reconexión / resync / watchdog ────────────────────
const NOW = 1_800_000_000_000;

check('shouldResync always syncs the first time',
  shouldResync(NOW, 0, 0, 20000, 10000) === true);

check('shouldResync syncs after a real gap even if rate-limited recently',
  shouldResync(NOW, NOW - 15000, NOW - 5000, 20000, 10000) === true);

check('shouldResync throttles a blip inside the min interval',
  shouldResync(NOW, NOW - 2000, NOW - 5000, 20000, 10000) === false);

check('shouldResync allows a blip once the min interval elapsed',
  shouldResync(NOW, NOW - 2000, NOW - 25000, 20000, 10000) === true);

check('silenceExceeded is false without tracked devices',
  silenceExceeded(NOW, NOW - 200000, 0, 90000) === false);

check('silenceExceeded is false before any message/silence budget',
  silenceExceeded(NOW, 0, 2, 90000) === false && silenceExceeded(NOW, NOW - 5000, 2, 90000) === false);

check('silenceExceeded is true when tracked devices go mute',
  silenceExceeded(NOW, NOW - 95000, 2, 90000) === true);

check('receiveLatencyMs computes recv - tuya_t and tolerates junk',
  receiveLatencyMs(NOW, NOW - 1200) === 1200 &&
  receiveLatencyMs(NOW, null) === null &&
  receiveLatencyMs(NOW, 'nope') === null &&
  receiveLatencyMs(NOW, 0) === null);

// ─── 7. F50: SWITCH por push sin cuota / watchdog largo / DNS / pong ───
console.log('\n\u2500\u2500 tuya-pulsar-consumer F50 \u2500\u2500');

check('TRACKED_KINDS trackea SWITCH (estado real por push, Bug 2)',
  Array.isArray(TRACKED_KINDS) && TRACKED_KINDS.includes('SWITCH') &&
  TRACKED_KINDS.includes('PROXIMITY') && TRACKED_KINDS.includes('PRESENCE'));

check('RESYNC_KINDS NO incluye SWITCH (no se consume cuota IoT Core)',
  Array.isArray(RESYNC_KINDS) && !RESYNC_KINDS.includes('SWITCH') &&
  RESYNC_KINDS.includes('PROXIMITY') && RESYNC_KINDS.includes('PRESENCE'));

check('el backstop de 15 min no dispara por silencio en reposo (14 min)',
  silenceExceeded(NOW, NOW - 14 * 60 * 1000, 3, 15 * 60 * 1000) === false);

check('pongAgeMs devuelve la antigüedad del pong y tolera ausencia',
  pongAgeMs(NOW, NOW - 30000) === 30000 &&
  pongAgeMs(NOW, 0) === null &&
  pongAgeMs(NOW, null) === null &&
  pongAgeMs(NOW, 'nope') === null);

// ─── Summary ───────────────────────────────────────────────────────────
console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' tuya-pulsar-consumer: ' + passed + ' passed, ' + failed + ' failed\n');
if (failed > 0) {
  process.exit(1);
}
process.exit(0);
