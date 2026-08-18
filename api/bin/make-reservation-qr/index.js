#!/usr/bin/env node
/**
 * make-reservation-qr — Emite un QR de reserva vía la API y genera un PNG.
 *
 * Uso:
 *   node index.js --room 1 --min 60 [--codtic 0] [--codhab 101]
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
const API_BASE  = 'http://127.0.0.1:8080';
const API_KEY   = '5a78442da74f9eba7bfb151678ad716567e47b41b3119155'; // VB6-MAIN
const OUTPUT_DIR = '/tmp/cerraduras-qr';

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

const roomId = parseInt(args.room || args['room-id'] || '0', 10);
const duracion = parseInt(args.min || args.duration || '60', 10);
const codtic = parseInt(args.codtic || '0', 10);
const codhab = args.codhab || args.room || '101';

if (!roomId || roomId < 1) {
  console.error('ERROR: --room <id> required');
  console.error('Usage: node index.js --room 1 --min 60 [--codtic 0]');
  process.exit(1);
}

// ─── Call API ──────────────────────────────────────────────────────
async function main() {
  const body = JSON.stringify({
    room_id: roomId,
    duracion_minutos: duracion,
    vb6_refs: {
      codtic: codtic,
      codhab: String(codhab),
      temporada: String(new Date().getFullYear()),
      empresa: 1,
      departamento: 2,
    }
  });

  console.log(`\n📡 Emitiendo QR para habitación ${roomId} (${duracion} min)...`);

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
  console.log(`   Habitación: ${roomId}`);

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
  console.log(`\x1b[32msshpass -p 'P2R6dABhDnta' scp admin@92.113.151.136:${filepath} ./${filename}\x1b[0m\n`);
  console.log(`   (El QR se puede escanear con el lector USB del ESP32)\n`);
}

main().catch(err => {
  console.error('❌ Error:', err.message);
  process.exit(1);
});
