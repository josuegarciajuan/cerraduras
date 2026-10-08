#!/usr/bin/env node
'use strict';

/**
 * camera-motion-worker.js — F88/RF-122: detector de movimiento de las cámaras
 * del almacén.
 *
 * Mantiene un `ffmpeg` por cámara habilitada leyendo el restream RTSP de go2rtc
 * (`rtsp://127.0.0.1:8554/<stream>`, para compartir la conexión con las cámaras),
 * reduce a gris y baja resolución, y detecta movimiento por diferencia de frames
 * (absdiff + umbral + % mínimo de píxeles cambiados) con histéresis.
 *
 * Al iniciar un episodio de movimiento publica `POST /almacen-api/motion
 * {device_id}` (con anti-rebote). El pipeline de presencia usa ese instante para
 * corroborar el radar (F88/RF-123).
 *
 * Nunca registra la URL RTSP (contiene credenciales). Sin llamadas a Tuya.
 *
 * Uso:
 *   node bin/camera-motion-worker.js
 *
 * Variables de entorno:
 *   CAMERA_MOTION_API          (def: http://127.0.0.1:8080)
 *   CAMERA_MOTION_ROOM_ID      (vacío/0: se descubre vía /almacen-api/state)
 *   CAMERA_MOTION_RTSP_BASE    (def: rtsp://127.0.0.1:8554)
 *   CAMERA_MOTION_FFMPEG       (def: ffmpeg)
 *   CAMERA_MOTION_FPS          (def: 3)
 *   CAMERA_MOTION_SCALE        (def: 160:120)
 *   CAMERA_MOTION_THRESHOLD    (def: 25)   diferencia de gris por píxel
 *   CAMERA_MOTION_MIN_PCT      (def: 0.5)  % mínimo de píxeles cambiados
 *   CAMERA_MOTION_MIN_FRAMES   (def: 2)    frames con movimiento para disparar
 *   CAMERA_MOTION_WINDOW       (def: 3)    tamaño de la ventana de histéresis
 *   CAMERA_MOTION_POST_MIN_MS  (def: 2000) anti-rebote entre publicaciones
 *   CAMERA_MOTION_REFRESH_MS   (def: 30000) reconciliación de cámaras
 */

const http = require('http');
const { spawn } = require('child_process');

const API = (process.env.CAMERA_MOTION_API || 'http://127.0.0.1:8080').replace(/\/+$/, '');
const ROOM_ID_ENV = parseInt(process.env.CAMERA_MOTION_ROOM_ID || '0', 10) || 0;
const RTSP_BASE = (process.env.CAMERA_MOTION_RTSP_BASE || 'rtsp://127.0.0.1:8554').replace(/\/+$/, '');
const FFMPEG = process.env.CAMERA_MOTION_FFMPEG || 'ffmpeg';
const FPS = parseInt(process.env.CAMERA_MOTION_FPS || '3', 10);
const SCALE = process.env.CAMERA_MOTION_SCALE || '160:120';
const THRESHOLD = parseInt(process.env.CAMERA_MOTION_THRESHOLD || '25', 10);
const MIN_PCT = parseFloat(process.env.CAMERA_MOTION_MIN_PCT || '0.5');
const MIN_FRAMES = parseInt(process.env.CAMERA_MOTION_MIN_FRAMES || '2', 10);
const WINDOW = parseInt(process.env.CAMERA_MOTION_WINDOW || '3', 10);
const POST_MIN_MS = parseInt(process.env.CAMERA_MOTION_POST_MIN_MS || '2000', 10);
const REFRESH_MS = parseInt(process.env.CAMERA_MOTION_REFRESH_MS || '30000', 10);
const RECONNECT_MIN_MS = 1000;
const RECONNECT_MAX_MS = 30000;

function log(...args) {
  console.error(`[camera-motion ${new Date().toISOString()}]`, ...args);
}

/* ------------------------------------------------------------------ */
/* Lógica pura (testable)                                             */
/* ------------------------------------------------------------------ */

/** Parsea "160:120" o "160x120" → {w:160,h:120}. */
function parseScale(scale) {
  const m = String(scale || '').match(/^(\d+)\s*[:x]\s*(\d+)$/);
  if (!m) return { w: 160, h: 120 };
  return { w: parseInt(m[1], 10), h: parseInt(m[2], 10) };
}

/**
 * % de píxeles (0..100) cuyo valor absoluto de gris supera `threshold`.
 * @param {Buffer} prev
 * @param {Buffer} cur
 * @returns {number}
 */
function frameDiffPct(prev, cur, threshold) {
  if (!Buffer.isBuffer(prev) || !Buffer.isBuffer(cur) || prev.length === 0 || prev.length !== cur.length) {
    return 0;
  }
  let changed = 0;
  for (let i = 0; i < prev.length; i++) {
    if (Math.abs(prev[i] - cur[i]) > threshold) changed++;
  }
  return (changed / prev.length) * 100;
}

/**
 * Histéresis: true si en la ventana hay al menos `minFrames` frames con movimiento.
 * @param {number[]} list 0/1 (más reciente al final)
 */
function debounce(list, minFrames, window) {
  const w = Math.max(1, window || 3);
  const tail = list.slice(-w);
  let n = 0;
  for (const v of tail) {
    if (v === 1) n++;
  }
  return n >= Math.max(1, minFrames || 1);
}

/** Construye la URL RTSP del restream de go2rtc para un stream. */
function go2rtcUrl(base, stream) {
  return `${String(base).replace(/\/+$/, '')}/${stream}`;
}

/* ------------------------------------------------------------------ */
/* HTTP                                                               */
/* ------------------------------------------------------------------ */

function getJson(path) {
  return new Promise((resolve, reject) => {
    http
      .get(`${API}${path}`, (res) => {
        let body = '';
        res.setEncoding('utf8');
        res.on('data', (c) => (body += c));
        res.on('end', () => {
          try {
            resolve(JSON.parse(body));
          } catch (e) {
            reject(new Error(`JSON inválido en ${path}`));
          }
        });
      })
      .on('error', reject);
  });
}

function postJson(path, obj) {
  return new Promise((resolve) => {
    const data = Buffer.from(JSON.stringify(obj));
    const u = new URL(`${API}${path}`);
    const req = http.request(
      {
        hostname: u.hostname,
        port: u.port,
        path: u.pathname,
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Content-Length': data.length },
        timeout: 5000,
      },
      (res) => {
        res.resume();
        res.on('end', resolve(true));
      }
    );
    req.on('error', () => resolve(false));
    req.on('timeout', () => {
      req.destroy();
      resolve(false);
    });
    req.write(data);
    req.end();
  });
}

/* ------------------------------------------------------------------ */
/* Stream de una cámara                                               */
/* ------------------------------------------------------------------ */

class CameraMotionStream {
  constructor(deviceId, stream, cfg) {
    this.deviceId = deviceId;
    this.stream = stream;
    this.cfg = cfg;
    this.proc = null;
    this.buf = Buffer.alloc(0);
    this.prev = null;
    this.motionList = [];
    this.hay = false;
    this.lastPostAt = 0;
    this.reconnectMs = RECONNECT_MIN_MS;
    this.stopped = false;
  }

  start() {
    if (this.stopped) return;
    const url = go2rtcUrl(this.cfg.rtspBase, this.stream);
    const args = [
      '-nostdin', '-loglevel', 'error', '-rtsp_transport', 'tcp',
      '-i', url, '-an',
      '-vf', `fps=${this.cfg.fps},scale=${this.cfg.scale}`,
      '-pix_fmt', 'gray', '-f', 'rawvideo', '-',
    ];
    const ff = spawn(this.cfg.ffmpeg, args, { stdio: ['ignore', 'pipe', 'ignore'] });
    this.proc = ff;

    ff.stdout.on('data', (chunk) => this.onData(chunk));
    ff.on('error', () => this.scheduleRestart());
    ff.on('close', () => this.scheduleRestart());
  }

  onData(chunk) {
    this.buf = Buffer.concat([this.buf, chunk]);
    const size = this.cfg.frameSize;
    while (this.buf.length >= size) {
      const frame = this.buf.subarray(0, size);
      this.buf = this.buf.subarray(size);
      this.processFrame(frame);
    }
    if (this.buf.length > size * 4) this.buf = Buffer.alloc(0); // resync
  }

  processFrame(frame) {
    if (this.prev === null) {
      this.prev = Buffer.from(frame);
      return;
    }
    const pct = frameDiffPct(this.prev, frame, this.cfg.threshold);
    this.prev = Buffer.from(frame);
    const motion = pct >= this.cfg.minPct ? 1 : 0;
    this.motionList.push(motion);
    if (this.motionList.length > WINDOW * 4) this.motionList = this.motionList.slice(-WINDOW * 4);

    const hay = debounce(this.motionList, this.cfg.minFrames, WINDOW);
    const now = Date.now();
    if (hay && (now - this.lastPostAt) >= POST_MIN_MS) {
      this.lastPostAt = now;
      postJson('/almacen-api/motion', { device_id: this.deviceId }).then((ok) => {
        if (!ok) log(`POST motion falló (device=${this.deviceId})`);
      });
    }
    this.hay = hay;
  }

  scheduleRestart() {
    if (this.stopped) return;
    try {
      if (this.proc) this.proc.kill('SIGKILL');
    } catch (_) {}
    this.proc = null;
    this.buf = Buffer.alloc(0);
    this.prev = null;
    this.motionList = [];
    const delay = this.reconnectMs;
    this.reconnectMs = Math.min(this.reconnectMs * 2, RECONNECT_MAX_MS);
    log(`stream ${this.deviceId} caído, reintento en ${delay}ms`);
    this.timer = setTimeout(() => this.start(), delay);
  }

  stop() {
    this.stopped = true;
    if (this.timer) clearTimeout(this.timer);
    try {
      if (this.proc) this.proc.kill('SIGKILL');
    } catch (_) {}
  }
}

/* ------------------------------------------------------------------ */
/* Descubrimiento y ciclo principal                                   */
/* ------------------------------------------------------------------ */

async function resolveRoomId() {
  if (ROOM_ID_ENV > 0) return ROOM_ID_ENV;
  const state = await getJson('/almacen-api/state');
  return state && state.room ? parseInt(state.room.id, 10) : 0;
}

async function loadCameras() {
  const roomId = await resolveRoomId();
  if (!roomId) return [];
  const data = await getJson(`/almacen-api/cameras?room_id=${roomId}`);
  return (data && data.cameras) || [];
}

const cfg = {
  api: API,
  rtspBase: RTSP_BASE,
  ffmpeg: FFMPEG,
  fps: FPS,
  scale: SCALE,
  threshold: THRESHOLD,
  minPct: MIN_PCT,
  minFrames: MIN_FRAMES,
  frameSize: parseScale(SCALE).w * parseScale(SCALE).h,
};

const streams = new Map(); // deviceId -> CameraMotionStream

async function reconcile() {
  try {
    const cams = await loadCameras();
    const wanted = new Map();
    for (const c of cams) {
      if (c.enabled === false) continue;
      const stream = String(c.stream || '').trim();
      if (stream === '') continue;
      wanted.set(parseInt(c.id, 10), stream);
    }
    for (const [id, st] of streams) {
      if (!wanted.has(id)) {
        st.stop();
        streams.delete(id);
        log(`cámara ${id} retirada`);
      }
    }
    for (const [id, stream] of wanted) {
      if (!streams.has(id)) {
        const st = new CameraMotionStream(id, stream, cfg);
        streams.set(id, st);
        st.start();
        log(`cámara ${id} añadida (stream ${stream})`);
      }
    }
  } catch (e) {
    log(`error reconciliando cámaras: ${e.message}`);
  }
}

function start() {
  log(`iniciado (api=${API}, go2rtc=${RTSP_BASE}, fps=${FPS}, scale=${SCALE}, thr=${THRESHOLD}, min%=${MIN_PCT})`);
  reconcile();
  setInterval(reconcile, REFRESH_MS).unref();
  const shutdown = () => {
    for (const st of streams.values()) st.stop();
    process.exit(0);
  };
  process.on('SIGTERM', shutdown);
  process.on('SIGINT', shutdown);
}

if (require.main === module) {
  start();
}

module.exports = {
  parseScale,
  frameDiffPct,
  debounce,
  go2rtcUrl,
  CameraMotionStream,
};
