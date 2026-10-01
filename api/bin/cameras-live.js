#!/usr/bin/env node
'use strict';

/**
 * cameras-live.js — F70/RF-80: servidor MJPEG de las cámaras del almacén.
 *
 * Transforma el RTSP de cada cámara en un flujo `multipart/x-mixed-replace`
 * (MJPEG) que el navegador puede mostrar con un simple `<img>` (patrón del
 * proyecto de reconocimiento facial: live/mjpeg-stream.js).
 *
 * - Un único `ffmpeg` por cámara, compartido (fan-out) entre espectadores.
 * - El proceso se mantiene `LIVE_IDLE_MS` sin espectadores y luego se apaga.
 * - Escucha en loopback; Apache lo expone en `/almacen-live`.
 * - La lista de cámaras se obtiene de la API local (`/almacen-api/state` +
 *   `/almacen-api/cameras`), solo cámaras habilitadas.
 *
 * Nunca se registra la URL RTSP (contiene credenciales): solo el id y el código
 * de salida de ffmpeg.
 *
 * Uso:
 *   node bin/cameras-live.js
 *
 * Variables de entorno (opcionales):
 *   CAMERAS_LIVE_HOST      (def: 127.0.0.1)
 *   CAMERAS_LIVE_PORT      (def: 8086)
 *   CAMERAS_LIVE_API       (def: http://127.0.0.1:8080)
 *   CAMERAS_LIVE_ROOM_ID   (si vacío: se descubre vía /almacen-api/state)
 *   CAMERAS_LIVE_FPS       (def: 5)
 *   CAMERAS_LIVE_SCALE     (def: 640:-2)
 *   CAMERAS_LIVE_QUALITY   (def: 6)
 *   CAMERAS_LIVE_IDLE_MS   (def: 5000)
 *   CAMERAS_LIVE_REFRESH_MS(def: 10000)
 *   CAMERAS_LIVE_FFMPEG    (def: ffmpeg)
 */

const http = require('http');
const { spawn } = require('child_process');

const HOST = process.env.CAMERAS_LIVE_HOST || '127.0.0.1';
const PORT = parseInt(process.env.CAMERAS_LIVE_PORT || '8086', 10);
const API = (process.env.CAMERAS_LIVE_API || 'http://127.0.0.1:8080').replace(/\/+$/, '');
const ROOM_ID_ENV = parseInt(process.env.CAMERAS_LIVE_ROOM_ID || '0', 10) || 0;
const FFMPEG = process.env.CAMERAS_LIVE_FFMPEG || 'ffmpeg';
const FPS = parseInt(process.env.CAMERAS_LIVE_FPS || '5', 10);
const SCALE = process.env.CAMERAS_LIVE_SCALE || '640:-2';
const QUALITY = process.env.CAMERAS_LIVE_QUALITY || '6';
const IDLE_MS = parseInt(process.env.CAMERAS_LIVE_IDLE_MS || '5000', 10);
const REFRESH_MS = parseInt(process.env.CAMERAS_LIVE_REFRESH_MS || '10000', 10);
const MAX_BUFFER = 5 * 1024 * 1024;      // descarta un frame incompleto gigante
const MAX_CLIENT_BACKLOG = 1024 * 1024;  // backpressure por cliente

const SOI = Buffer.from([0xff, 0xd8]);
const EOI = Buffer.from([0xff, 0xd9]);

const cameras = new Map(); // id(String) -> fila de la API (con rtsp_url)
const streams = new Map(); // id(String) -> { proc, subs:Set, buf, idle }
let lastRefresh = null;

function log(...args) {
  console.error(`[cameras-live ${new Date().toISOString()}]`, ...args);
}

/* ------------------------------------------------------------------ */
/* Parser puro de frames JPEG (testable)                               */
/* ------------------------------------------------------------------ */

/**
 * Extrae los JPEG completos de un buffer acumulado.
 * @param {Buffer} buf
 * @returns {{frames: Buffer[], tail: Buffer}}
 */
function extractJpegFrames(buf) {
  const frames = [];
  let b = buf;
  for (;;) {
    const ini = b.indexOf(SOI);
    if (ini === -1) return { frames, tail: Buffer.alloc(0) };
    if (ini > 0) b = b.subarray(ini);
    const fin = b.indexOf(EOI, 2);
    if (fin === -1) return { frames, tail: b };
    frames.push(b.subarray(0, fin + 2));
    b = b.subarray(fin + 2);
  }
}

/* ------------------------------------------------------------------ */
/* Descubrimiento de cámaras (API local)                               */
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

async function resolveRoomId() {
  if (ROOM_ID_ENV > 0) return ROOM_ID_ENV;
  const state = await getJson('/almacen-api/state');
  return state && state.room ? parseInt(state.room.id, 10) : 0;
}

async function refreshCameras() {
  try {
    const roomId = await resolveRoomId();
    if (!roomId) {
      log('sin sala ALMACEN_BEBIDAS todavía');
      return;
    }
    const data = await getJson(`/almacen-api/cameras?room_id=${roomId}`);
    const list = (data && data.cameras) || [];
    cameras.clear();
    for (const c of list) {
      const url = String(c.rtsp_url || '').trim();
      if (c.enabled === true && url !== '') {
        cameras.set(String(c.id), { id: c.id, position: c.position, url });
      }
    }
    lastRefresh = new Date();
    log(`caché de cámaras: ${cameras.size} habilitadas`);
  } catch (e) {
    log(`error refrescando cámaras: ${e.message}`);
  }
}

/* ------------------------------------------------------------------ */
/* Stream MJPEG compartido por cámara                                  */
/* ------------------------------------------------------------------ */

function frameMjpeg(frame) {
  const header = Buffer.from(
    `--frame\r\nContent-Type: image/jpeg\r\nContent-Length: ${frame.length}\r\n\r\n`
  );
  return Buffer.concat([header, frame, Buffer.from('\r\n')]);
}

function fanout(st, frame) {
  if (st.subs.size === 0) return;
  const packet = frameMjpeg(frame);
  for (const res of st.subs) {
    if (res.writable && res.writableLength < MAX_CLIENT_BACKLOG) {
      try {
        res.write(packet);
      } catch (_) {
        /* el cliente se fue: su handler close lo limpiará */
      }
    }
  }
}

function teardown(id, code) {
  const st = streams.get(id);
  if (!st) return;
  streams.delete(id);
  if (st.idle) clearTimeout(st.idle);
  for (const res of st.subs) {
    try {
      res.end();
    } catch (_) {}
  }
  st.subs.clear();
  if (code !== undefined && code !== 0) {
    log(`ffmpeg id=${id} salió con código ${code}`);
  }
}

function killStream(id) {
  const st = streams.get(id);
  if (!st) return;
  streams.delete(id);
  if (st.idle) clearTimeout(st.idle);
  try {
    st.proc.kill('SIGKILL');
  } catch (_) {}
}

function getStream(id, url) {
  let st = streams.get(id);
  if (st) return st;

  const args = [
    '-rtsp_transport', 'tcp',
    '-loglevel', 'error',
    '-i', url,
    '-vf', `fps=${FPS},scale=${SCALE}`,
    '-q:v', String(QUALITY),
    '-f', 'mjpeg',
    '-',
  ];
  const ff = spawn(FFMPEG, args, { stdio: ['ignore', 'pipe', 'ignore'] });
  st = { proc: ff, subs: new Set(), buf: Buffer.alloc(0), idle: null };
  streams.set(id, st);

  ff.stdout.on('data', (chunk) => {
    st.buf = Buffer.concat([st.buf, chunk]);
    const parsed = extractJpegFrames(st.buf);
    st.buf = parsed.tail.length > MAX_BUFFER ? Buffer.alloc(0) : parsed.tail;
    for (const frame of parsed.frames) fanout(st, frame);
  });

  ff.on('error', (e) => {
    log(`ffmpeg id=${id} error: ${e.message}`);
    teardown(id);
  });
  ff.on('close', (code) => teardown(id, code));
  return st;
}

function handleLive(req, res) {
  const u = new URL(req.url, `http://${HOST}:${PORT}`);
  if (u.pathname !== '/live') {
    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('not found');
    return;
  }
  const id = u.searchParams.get('id');
  if (!id) {
    res.writeHead(400, { 'Content-Type': 'text/plain' });
    res.end('id requerido');
    return;
  }
  const cam = cameras.get(String(id));
  if (!cam) {
    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('cámara no disponible');
    return;
  }

  res.writeHead(200, {
    'Content-Type': 'multipart/x-mixed-replace; boundary=frame',
    'Cache-Control': 'no-store, no-cache, must-revalidate',
    Pragma: 'no-cache',
    'X-Accel-Buffering': 'no',
  });

  const st = getStream(String(id), cam.url);
  if (st.idle) {
    clearTimeout(st.idle);
    st.idle = null;
  }
  st.subs.add(res);

  let cerrado = false;
  const cerrar = () => {
    if (cerrado) return;
    cerrado = true;
    st.subs.delete(res);
    try {
      res.end();
    } catch (_) {}
    if (st.subs.size === 0 && streams.get(String(id)) === st) {
      st.idle = setTimeout(() => killStream(String(id)), IDLE_MS);
    }
  };
  res.on('close', cerrar);
  req.on('close', cerrar);
}

function handleStatus(res) {
  const detalle = {};
  for (const [id, st] of streams) {
    detalle[id] = { espectadores: st.subs.size, idle: st.idle !== null };
  }
  res.writeHead(200, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({
    ok: true,
    cameras: cameras.size,
    streams: streams.size,
    lastRefresh: lastRefresh ? lastRefresh.toISOString() : null,
    ids: [...cameras.keys()],
    detalle,
  }));
}

/* ------------------------------------------------------------------ */
/* Servidor                                                            */
/* ------------------------------------------------------------------ */

function createServer() {
  return http.createServer((req, res) => {
    const u = new URL(req.url, `http://${HOST}:${PORT}`);
    if (u.pathname === '/live') {
      handleLive(req, res);
      return;
    }
    if (u.pathname === '/status') {
      handleStatus(res);
      return;
    }
    res.writeHead(200, { 'Content-Type': 'text/plain' });
    res.end('Cerraduras cameras-live — usa /live?id=<camara_id>');
  });
}

function start() {
  const server = createServer();
  server.listen(PORT, HOST, () => {
    log(`escuchando en http://${HOST}:${PORT} (1 ffmpeg por cámara)`);
    refreshCameras();
    setInterval(refreshCameras, REFRESH_MS);
  });

  const shutdown = () => {
    for (const id of [...streams.keys()]) killStream(id);
    server.close(() => process.exit(0));
  };
  process.on('SIGTERM', shutdown);
  process.on('SIGINT', shutdown);
  return server;
}

if (require.main === module) {
  start();
}

module.exports = {
  extractJpegFrames,
  frameMjpeg,
  createServer,
  refreshCameras,
  cameras,
  streams,
};
