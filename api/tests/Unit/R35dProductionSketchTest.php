<?php
declare(strict_types=1);

/**
 * Unit tests for the production sketch of the new R35D-B board (F89).
 *
 * Trazabilidad: RF-127…RF-132 · contracts.md §F89 · design.md Anexo A.
 *
 * Static contract checks over docs/esp32-qr-reader/scanner-relay-prod-r35d.ino:
 *   - starts from the production sketch and preserves every production feature;
 *   - removes the USB host and uses R35D-B UART1 (18/17 + DIR 7);
 *   - uses GPIO15 active-high with the vendor sequence;
 *   - uses the external LEDs (1 red / 2 green) and GPIO9 button;
 *   - does not hardcode the local test token and leaves validation to the API.
 *
 * Run:
 *   php tests/Unit/R35dProductionSketchTest.php
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

echo "R35dProductionSketch (F89) Unit Tests\n";
echo str_repeat("=", 60) . "\n\n";

$root = dirname(__DIR__, 3);
$path = $root . '/docs/esp32-qr-reader/scanner-relay-prod-r35d.ino';

if (!is_file($path)) {
    fail("sketch no encontrado: {$path}");
    echo "\nTotal: {$PASS} passed, {$FAIL} failed\n";
    exit(1);
}

$src = (string) file_get_contents($path);

// Copia lista para abrir en Arduino IDE (un único .ino en su carpeta).
$flashPath = $root . '/firmware/scanner-relay-prod-r35d/scanner-relay-prod-r35d.ino';
if (!is_file($flashPath)) {
    fail("copia flash-ready no encontrada: {$flashPath}");
} elseif (hash_file('sha256', $flashPath) === hash_file('sha256', $path)) {
    pass('copia flash-ready idéntica al sketch canónico');
} else {
    fail('la copia flash-ready difiere de docs/esp32-qr-reader/scanner-relay-prod-r35d.ino');
}

$contains = static fn (string $needle): bool => str_contains($src, $needle);
$matches  = static fn (string $pattern): bool => preg_match($pattern, $src) === 1;

// ── 1. Producción conservada (WiFi/NVS, TLS, API, heartbeat, commands) ────
$contains('#include <WiFiManager.h>') ? pass('conserva WiFiManager')
    : fail('falta WiFiManager');
$contains('Preferences') && $contains('device-cred')
    ? pass('conserva NVS y credencial individual device-cred')
    : fail('falta NVS/device-cred');
$contains('clearNvsIfNewFirmware') && $contains('build_marker')
    ? pass('conserva el wipe de WiFi por build nuevo (sin tocar device-cred)')
    : fail('falta clearNvsIfNewFirmware/build_marker');
$contains('setCACert(CERRADURAS_API_CA_PEM)') && $contains('syncSystemTime')
    ? pass('conserva TLS con CA embebida y NTP')
    : fail('falta CA/NTP');
$contains('/api/v1/qr/validate') && $contains('qr_text') && $contains('device_id')
    ? pass('conserva POST /api/v1/qr/validate con qr_text/device_id')
    : fail('falta el contrato de validación QR');
$contains('/dashboard-api/device-heartbeat') && $contains('sub_kinds')
    && $contains('RPI') && $contains('SCANNER') && $contains('LOCK')
    ? pass('conserva heartbeat de RPI/SCANNER/LOCK')
    : fail('falta heartbeat');
$contains('/dashboard-api/pending-command') && $contains('/dashboard-api/command-result')
    ? pass('conserva command-queue y command-result')
    : fail('falta command-queue');
$contains('/api/v1/devices/identify') && $contains('/api/v1/factory-devices/announce')
    ? pass('conserva identify y announce de fábrica')
    : fail('falta identify/announce');
$contains('esp_task_wdt_init') && $contains('esp_task_wdt_reset')
    ? pass('conserva watchdog')
    : fail('falta watchdog');

// ── 2. Sin host USB; R35D-B por UART1 ────────────────────────────────────
!$contains('EspUsbHost') && !$contains('usb.onKeyboard') && !$contains('usb.begin')
    ? pass('elimina el host USB y sus callbacks')
    : fail('queda código del host USB');
$contains('#define R35D_RX_PIN              18')
    && $contains('#define R35D_TX_PIN              17')
    && $contains('#define R35D_DIR_PIN             7')
    ? pass('pinout UART1 RX=18 TX=17 DIR=7')
    : fail('pinout UART1 incorrecto');
$contains('Serial1.begin(R35D_BAUD, SERIAL_8N1, R35D_RX_PIN, R35D_TX_PIN)')
    ? pass('inicializa Serial1 9600 8N1 en setup')
    : fail('no inicializa Serial1 como R35D-B');
$contains('Serial1.setRxBufferSize(R35D_RX_BUFFER)')
    ? pass('buffer RX ampliado para un JWT de ~200 B')
    : fail('falta setRxBufferSize');
$contains('digitalWrite(R35D_DIR_PIN, LOW)')
    ? pass('DIR en LOW (modo lectura)')
    : fail('DIR no queda en lectura');
$contains('digitalWrite(R35D_DIR_PIN, HIGH)')
    ? fail('el firmware productivo no debe escribir en el R35D-B')
    : pass('no escribe comandos al R35D-B en operación normal');

// ── 3. Cerradura GPIO15 activo-alto + secuencia del proveedor ────────────
$contains('#define LOCK_PIN                 15')
    && $contains('#define LOCK_ACTIVE_HIGH         1')
    ? pass('cerradura en GPIO15 activo-alto')
    : fail('pin/nivel de cerradura incorrecto');
$contains('#define LOCK_SEQ_HOLD_MS         250')
    && $contains('#define LOCK_SEQ_FAST_CYCLES     125')
    && $contains('#define LOCK_SEQ_FAST_MS         1')
    && $contains('#define LOCK_SEQ_SLOW_CYCLES     30')
    && $contains('#define LOCK_SEQ_SLOW_MS         5')
    ? pass('secuencia 250 ms + 125×(1/1) + 30×(5/5)')
    : fail('secuencia del proveedor incompleta');
$contains('xTaskCreatePinnedToCore') && $contains('lockSequenceTask')
    ? pass('secuencia no bloqueante en tarea FreeRTOS')
    : fail('la secuencia no está en tarea dedicada');
$contains('digitalWrite(LOCK_PIN, LOW);   // reposo garantizado')
    ? pass('la secuencia termina en LOW')
    : fail('no garantiza LOW al final');
!$contains('RELAY_ACTIVE_LOW') && !$contains('#define RELAY_PIN')
    ? pass('no reutiliza relayOn/relayOff del prototipo')
    : fail('queda la lógica de relé del prototipo');

// ── 4. LEDs externos y pulsador GPIO9 ────────────────────────────────────
$contains('#define LED_RED_PIN              1')
    && $contains('#define LED_GREEN_PIN            2')
    ? pass('LEDs externos rojo GPIO1 / verde GPIO2')
    : fail('pines de LEDs incorrectos');
$contains('#define OPEN_LED_MS              3000')
    ? pass('ventana verde/rojo de apertura = 3 s')
    : fail('ventana de LEDs incorrecta');
$contains('void setLockedLeds()') && $contains('digitalWrite(LED_RED_PIN, HIGH)')
    ? pass('estado por defecto rojo ON / verde OFF')
    : fail('estado por defecto de LEDs incorrecto');
$contains('#define IDENTIFY_PIN             9') && $contains('INPUT_PULLUP')
    ? pass('pulsador en GPIO9 con INPUT_PULLUP')
    : fail('pulsador GPIO9 incorrecto');
$contains('factoryResetProvisioning') && $contains('while (digitalRead(IDENTIFY_PIN) == LOW)')
    ? pass('GPIO9 mantenido al arrancar reabre provisioning')
    : fail('falta reset de provisioning por GPIO9');

// ── 5. QR: sin token hardcodeado, sin filtro de puntos, cooldown ─────────
!$contains('95l8bpUfThEF')
    ? pass('no hardcodea el token de prueba local')
    : fail('el token de prueba local está hardcodeado');
!$contains('dots != 2')
    ? pass('no exige 2 puntos (el backend valida)')
    : fail('conserva el pre-filtro dots==2 del USB');
$contains('#define QR_MIN_LEN               8')
    ? pass('longitud mínima adaptada al texto plano del R35D-B')
    : fail('QR_MIN_LEN no adaptado');
$contains('#define SAME_TOKEN_COOLDOWN_MS   2000')
    && $contains('lastQrText == text')
    ? pass('cooldown/debounce del mismo token')
    : fail('falta cooldown del mismo token');
$contains('if (code == 200)') && $contains('startLockSequence()')
    ? pass('solo abre con HTTP 200')
    : fail('la apertura no está condicionada al 200');
$contains('/api/v1/devices/qr-rejected') && $contains('r35d-prod')
    ? pass('telemetría de rechazos adaptada (fw r35d-prod)')
    : fail('falta telemetría de rechazos');

// ── 6. Sin cuota Tuya ni polling ─────────────────────────────────────────
!$matches('/tuya/i') && !$contains('presence-poller')
    ? pass('sin referencias a Tuya ni pollers')
    : fail('introduce polling/cuota Tuya');

echo "\nTotal: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
