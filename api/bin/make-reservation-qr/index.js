#!/usr/bin/env node
/**
 * make-reservation-qr — Emite un QR de reserva vía la API y genera un PNG.
 *
 * Uso:
 *   node index.js --room-code 101 --min 60 [--codtic 0]
 *   node index.js --room 1 --min 60 [--codtic 0]        (compatibilidad)
 *
 * Requiere CERRADURAS_API_KEY en el entorno.
 *
 * Flujo:
 *   1. Llama POST /api/v1/qr con la API key VB6-MAIN.
 *   2. Recibe qr_text (token firmado).
 *   3. Renderiza PNG con la librería qrcode.
 *   4. Guarda en /tmp/cerraduras-qr/reservation-<stay_id>.png
 *   5. Imprime el stay_id, expires_at y el comando scp.
 *
 * Requisitos: npm install (qrcode)
 */

const fs = require('fs');
const path = require('path');
const https = require('http');
const QRCode = require('qrcode');

// ─── Config ────────────────────────────────────────────────────────
const API_BASE  = process.env.CERRADURAS_API_BASE || 'http://127.0.0.1:8080';
const API_KEY   = process.env.CERRADURAS_API_KEY || '';
const OUTPUT_DIR = process.env.CERRADURAS_QR_OUTPUT_DIR || '/tmp/cerraduras-qr';

// ─── CLI args ──────────────────────────────────────────────────────
const args = {};
for (let i = 2; i < process.argv.length; i++) {
  const a = process.argv[i];
  if (a.startsWith('--')) {
    const key = a.slice(2);
    const val = process.argv[i + 1];
    args[key] = val;
    i++;
  }
}

const roomId = parseInt(args.room || args['room-id'] || '0', 10) || null;
const roomCode = (args['room-code'] || args.codhab || '').trim() || null;
const duracion = parseInt(args.min || args.duration || '60', 10);
const codtic = parseInt(args.codtic || '0', 10);

if (!API_KEY) {
  console.error('ERROR: CERRADURAS_API_KEY no está definida');
  process.exit(1);
}
if ((!roomId && !roomCode) || (roomId && roomCode)) {
  console.error('ERROR: usa exactamente uno de --room-code o --room');
  console.error('Usage: node index.js --room-code 101 --min 60 [--codtic 0]');
  process.exit(1);
}

// ─── Call API ──────────────────────────────────────────────────────
async function main() {
  const body = JSON.stringify({
    ...(roomCode ? { room_code: roomCode } : { room_id: roomId }),
    duracion_minutos: duracion,
    vb6_refs: {
      codtic: codtic,
      codhab: String(roomCode || roomId),
      temporada: String(new Date().getFullYear()),
      empresa: 1,
      departamento: 2,
    }
  });

  console.log(`\n📡 Emitiendo QR para habitación ${roomCode || roomId} (${duracion} min)...`);

  const resp = await fetch(`${API_BASE}/api/v1/qr`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-API-Key': API_KEY,
      'Idempotency-Key': 'qr-tool-' + Date.now(),
    },
    body: body,
    timeout: 10000,
  });

  const data = await resp.json();

  if (resp.status !== 201) {
    console.error(`❌ API error HTTP ${resp.status}: ${JSON.stringify(data, null, 2)}`);
    process.exit(1);
  }

  const { stay_id, qr_text, expires_at, issued_at } = data;

  console.log(`✅ QR emitido`);
  console.log(`   Stay ID:    ${stay_id}`);
  console.log(`   Emitido:    ${issued_at}`);
  console.log(`   Expira:     ${expires_at}`);
  console.log(`   Habitación: ${data.room?.code || roomCode || roomId} (id ${data.room?.id || data.room_id})`);

  // ─── Render PNG ──────────────────────────────────────────────────
  const filename = `reservation-${stay_id}.png`;
  const filepath = path.join(OUTPUT_DIR, filename);

  await QRCode.toFile(filepath, qr_text, {
    type: 'png',
    width: 400,
    margin: 4,
    color: { dark: '#000000', light: '#FFFFFF' },
  });

  console.log(`🖼️  PNG:        ${filepath}`);

  // ─── scp command ─────────────────────────────────────────────────
  console.log(`\n📋 Comando para descargar (copiar y pegar en tu terminal local):\n`);
  const sshHost = process.env.CERRADURAS_SSH_HOST || '<usuario>@<host>';
  console.log(`\x1b[32mscp ${sshHost}:${filepath} ./${filename}\x1b[0m\n`);
  console.log(`   (El QR se puede escanear con el lector USB del ESP32)\n`);
}

main().catch(err => {
  console.error('❌ Error:', err.message);
  process.exit(1);
});
