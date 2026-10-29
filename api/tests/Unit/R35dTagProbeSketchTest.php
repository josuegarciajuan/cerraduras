<?php
declare(strict_types=1);

/**
 * Unit tests for the R35D-B tag/QR probe sketch.
 *
 * Trazabilidad: F96/RF-168 (fichas) + diagnóstico físico del R35D-B.
 * Verifica que el sketch de prueba:
 *   - conserva el pinout validado del R35D-B (UART1 18/17 + DIR 7);
 *   - no usa WiFi, API ni NVS;
 *   - deja la cerradura deshabilitada (GPIO15 LOW, nunca HIGH);
 *   - vuelca tramas crudas (HEX/ASCII) y emite ALIVE;
 *   - no hardcodea tokens ni introduce polling Tuya.
 *
 * Run:
 *   php tests/Unit/R35dTagProbeSketchTest.php
 */

$PASS = 0;
$FAIL = 0;

function pass(string $m): void
{
    global $PASS;
    $PASS++;
    echo "  ✅ {$m}\n";
}

function fail(string $m): void
{
    global $FAIL;
    $FAIL++;
    echo "  ❌ {$m}\n";
}

echo "R35dTagProbeSketch Unit Tests\n";
echo str_repeat("=", 60) . "\n\n";

$root = dirname(__DIR__, 3);
$path = $root . '/docs/esp32-qr-reader/r35d-tag-probe.ino';

if (!is_file($path)) {
    fail("sketch no encontrado: {$path}");
    echo "\nTotal: {$PASS} passed, {$FAIL} failed\n";
    exit(1);
}

$src = (string) file_get_contents($path);

$flashPath = $root . '/firmware/r35d-tag-probe/r35d-tag-probe.ino';
if (!is_file($flashPath)) {
    fail("copia flash-ready no encontrada: {$flashPath}");
} elseif (hash_file('sha256', $flashPath) === hash_file('sha256', $path)) {
    pass('copia flash-ready idéntica al sketch canónico');
} else {
    fail('la copia flash-ready difiere de docs/esp32-qr-reader/r35d-tag-probe.ino');
}

$contains = static fn (string $needle): bool => str_contains($src, $needle);
$matches  = static fn (string $pattern): bool => preg_match($pattern, $src) === 1;

// ── 1. Pinout y UART del R35D-B ──────────────────────────────────────────
$contains('#define R35D_BAUD             9600')
    && $contains('#define R35D_RX_PIN           18')
    && $contains('#define R35D_TX_PIN           17')
    && $contains('#define R35D_DIR_PIN          7')
    ? pass('pinout UART1 9600 RX=18 TX=17 DIR=7')
    : fail('pinout UART1 incorrecto');

$contains('Serial1.begin(R35D_BAUD, SERIAL_8N1, R35D_RX_PIN, R35D_TX_PIN)')
    ? pass('inicializa Serial1 9600 8N1')
    : fail('no inicializa Serial1 como el R35D-B');

$contains('Serial1.setRxBufferSize(R35D_RX_BUFFER)')
    ? pass('buffer RX ampliado')
    : fail('falta setRxBufferSize');

$contains('digitalWrite(R35D_DIR_PIN, LOW)')
    && !$contains('digitalWrite(R35D_DIR_PIN, HIGH)')
    ? pass('DIR queda en LOW (solo lectura)')
    : fail('DIR no está fijado en lectura');

// ── 2. Cerradura deshabilitada ───────────────────────────────────────────
$contains('#define LOCK_PIN              15')
    && $contains('digitalWrite(LOCK_PIN, LOW)')
    && !$contains('digitalWrite(LOCK_PIN, HIGH)')
    ? pass('cerradura GPIO15 en LOW y nunca se activa')
    : fail('el sketch podría activar la cerradura');

// ── 3. Sin red, sin API, sin NVS ────────────────────────────────────────
!$contains('#include <WiFi') && !$contains('HTTPClient') && !$contains('Preferences')
    && !$contains('WiFi.begin') && !$contains('WiFiManager')
    ? pass('sin WiFi, API ni escritura NVS')
    : fail('el diagnóstico no debe usar red/NVS');

// ── 4. Volcado crudo y señal de vida ────────────────────────────────────
$contains('void pollR35d()') && $contains('frameLen')
    ? pass('acumula bytes sin filtro de longitud')
    : fail('no acumula tramas crudas');

$contains('[TAG] HEX:') && $contains('[TAG] ASCII:')
    ? pass('imprime HEX y ASCII de cada trama')
    : fail('falta el volcado HEX/ASCII');

$contains('[ALIVE]') && $contains('ALIVE_MS')
    ? pass('emite ALIVE periódico')
    : fail('falta la señal ALIVE');

$contains('R35D_GAP_MS') && $contains('frameHadLf')
    ? pass('cierra trama por LF o por silencio')
    : fail('no delimita tramas por LF/silencio');

$contains('hint=possible-EM-UID')
    ? pass('marca la pista de UID EM 8/10 hex')
    : fail('falta la pista de UID EM');

// ── 5. Sin secretos, tokens ni cuota Tuya ───────────────────────────────
!$contains('95l8bpUfThEF')
    ? pass('no hardcodea el token de prueba local')
    : fail('el token de prueba local está hardcodeado');

!$matches('/tuya/i') && !$contains('presence-poller')
    ? pass('sin referencias a Tuya ni pollers')
    : fail('introduce polling/cuota Tuya');

echo "\nTotal: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
