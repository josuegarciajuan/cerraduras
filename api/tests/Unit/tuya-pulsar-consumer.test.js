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

// ─── Summary ───────────────────────────────────────────────────────────
console.log('\n' + (failed === 0 ? '\u2705' : '\u274c') + ' tuya-pulsar-consumer: ' + passed + ' passed, ' + failed + ' failed\n');
if (failed > 0) {
  process.exit(1);
}
process.exit(0);
