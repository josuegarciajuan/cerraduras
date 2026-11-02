'use strict';

/**
 * F108 — evidencia de distancia del ZY-M100-S_2.
 *
 * Publica un marcador `f107_distance_report` SOLO cuando la trama Zigbee
 * contiene el DP 9 (target_distance). El marcador cambia con cada reporte,
 * aunque la distancia sea igual, y se mantiene idéntico si Zigbee2MQTT
 * republica el estado cacheado por otro atributo.
 *
 * No existe consulta directa funcional: la prueba F108 demostró
 * ZIGBEE_DELIVERY_FAILED para dataQuery. Por eso este conversor no expone
 * ninguna orden de consulta ni envía comandos al dispositivo.
 */

const exposes = require('zigbee-herdsman-converters/lib/exposes');
const tuya = require('zigbee-herdsman-converters/lib/tuya');

const e = exposes.presets;
const ea = exposes.access;

const SESSION = Date.now().toString(36);
const seen = new Map();
let counter = 0;

function isDuplicate(seq, txn) {
  const key = `${seq ?? ''}|${txn ?? ''}`;
  if (key === '|') return false;
  const now = Date.now();
  for (const [k, expires] of seen) {
    if (expires < now) seen.delete(k);
  }
  if (seen.has(key)) return true;
  seen.set(key, now + 10000);
  return false;
}

function decodeDistance(dp) {
  try {
    const buf = Buffer.isBuffer(dp.data) ? dp.data : Buffer.from(dp.data || []);
    if (buf.length === 4) return buf.readInt32BE(0) / 100;
    if (buf.length === 2) return buf.readInt16BE(0) / 100;
    if (buf.length === 1) return buf.readInt8(0) / 100;
  } catch (_) {
    return null;
  }
  return null;
}

const fzDistanceReport = {
  cluster: 'manuSpecificTuya',
  type: [
    'commandDataReport',
    'commandDataResponse',
    'commandActiveStatusReport',
    'commandActiveStatusReportAlt',
  ],
  convert: (model, msg) => {
    const dp = (msg.data.dpValues || []).find((d) => d.dp === 9);
    if (!dp) return {};
    if (isDuplicate(msg.data.seq, msg.meta && msg.meta.zclTransactionSequenceNumber)) return {};
    const value = decodeDistance(dp);
    if (value === null || !Number.isFinite(value) || value < 0) return {};
    counter += 1;
    return {
      f107_distance_report: `v1|${SESSION}|${counter}|${value.toFixed(3)}|push`,
    };
  },
};

const definition = {
  fingerprint: tuya.fingerprint('TS0601', [
    '_TZE204_qasjif9e',
    '_TZE200_qasjif9e',
    '_TZE204_ztqnh5cg',
    '_TZE204_iadro9bf',
    '_TZE284_iadro9bf',
  ]),
  model: 'ZY-M100-S_2',
  vendor: 'Tuya',
  description: 'Mini human breathe sensor (F108 distance evidence)',
  extend: [tuya.modernExtend.tuyaBase({ dp: true })],
  fromZigbee: [fzDistanceReport],
  exposes: [
    e.illuminance(),
    e.presence(),
    e.numeric('target_distance', ea.STATE).withDescription('Distance to target').withUnit('m'),
    e.numeric('radar_sensitivity', ea.STATE_SET).withValueMin(0).withValueMax(9).withValueStep(1).withDescription('sensitivity of the radar'),
    e.numeric('minimum_range', ea.STATE_SET).withValueMin(0).withValueMax(9.5).withValueStep(0.15).withDescription('Minimum range').withUnit('m'),
    e.numeric('maximum_range', ea.STATE_SET).withValueMin(0).withValueMax(9.5).withValueStep(0.15).withDescription('Maximum range').withUnit('m'),
    e.numeric('detection_delay', ea.STATE_SET).withValueMin(0).withValueMax(10).withValueStep(0.1).withDescription('Detection delay').withUnit('s'),
    e.numeric('fading_time', ea.STATE_SET).withValueMin(0.5).withValueMax(1500).withValueStep(1).withDescription('Fading time').withUnit('s'),
    e.text('f107_distance_report', ea.STATE).withDescription('F108: identity of the last DP 9 report'),
  ],
  meta: {
    tuyaDatapoints: [
      [1, 'presence', tuya.valueConverter.trueFalse1],
      [9, 'target_distance', tuya.valueConverter.divideBy100],
      [12, 'illuminance', tuya.valueConverter.raw],
      [104, 'illuminance', tuya.valueConverter.raw],
      [2, 'radar_sensitivity', tuya.valueConverter.raw],
      [4, 'maximum_range', tuya.valueConverter.divideBy100],
      [3, 'minimum_range', tuya.valueConverter.divideBy100],
      [102, 'fading_time', tuya.valueConverter.divideBy10],
      [101, 'detection_delay', tuya.valueConverter.divideBy10],
    ],
  },
};

module.exports = [definition];
