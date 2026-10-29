<?php
declare(strict_types=1);

/**
 * EspVariantTest — F97/RF-174..RF-177.
 *
 * Static/pure checks for the two ESP actuator variants and the Zigbee door
 * channel used by ESP-imán. No hardware or network required.
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\Device;

$passed = 0;
$failed = 0;
function checkEsp(bool $condition, string $message): void
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . "\n";
}

$root = dirname(__DIR__, 3);
$canonical = (string) file_get_contents($root . '/docs/esp32-qr-reader/esp-cerradura.ino');
$cerradura = (string) file_get_contents($root . '/firmware/esp-cerradura/esp-cerradura.ino');
$iman = (string) file_get_contents($root . '/docs/esp32-qr-reader/esp-iman.ino');
$imanFlash = (string) file_get_contents($root . '/firmware/esp-iman/esp-iman.ino');
$legacy = (string) file_get_contents($root . '/docs/esp32-qr-reader/scanner-relay-prod-r35d.ino');
$controller = (string) file_get_contents($root . '/api/src/Http/Controllers/ImanEventStreamController.php');
$routes = (string) file_get_contents($root . '/api/public/index.php');
$panel = (string) file_get_contents($root . '/api/public/panel/index.html');
$migration = (string) file_get_contents($root . '/api/migrations/0131_esp_variants.sql');

// ── Modelo ────────────────────────────────────────────────────────────────
checkEsp(Device::SUBTYPE_ESP_CERRADURA === 'CERRADURA', 'subtipo CERRADURA definido');
checkEsp(Device::SUBTYPE_ESP_IMAN === 'IMAN', 'subtipo IMAN definido');
checkEsp(Device::normalizeEspVariant(null) === 'CERRADURA', 'RPI sin subtipo normaliza a CERRADURA');
checkEsp(Device::normalizeEspVariant('iman') === 'IMAN', 'variante IMAN normalizada a mayúsculas');
checkEsp(Device::isValidSubtype('RPI', 'CERRADURA'), 'CERRADURA válido para RPI');
checkEsp(Device::isValidSubtype('RPI', 'IMAN'), 'IMAN válido para RPI');
checkEsp(!Device::isValidSubtype('RPI', 'EXTERIOR'), 'una cámara no puede ser variante de ESP');
checkEsp(!Device::isValidSubtype('PROXIMITY', 'IMAN'), 'un sensor no puede llevar variante ESP');
checkEsp(Device::isValidSubtype('CAMERA', 'EXTERIOR'), 'la matriz de cámaras no cambia');

// ── Migración ─────────────────────────────────────────────────────────────
checkEsp(str_contains($migration, "SET `subtype` = 'CERRADURA'")
    && str_contains($migration, "WHERE `kind` = 'RPI'")
    && str_contains($migration, 'firmware_variant')
    && str_contains($migration, 'firmware_version'),
    'migración: backfill CERRADURA + variante/versión de firmware');

// ── ESP-cerradura conserva el sketch actual ───────────────────────────────
checkEsp(hash_file('sha256', $root . '/docs/esp32-qr-reader/esp-cerradura.ino')
    === hash_file('sha256', $root . '/docs/esp32-qr-reader/scanner-relay-prod-r35d.ino'),
    'ESP-cerradura es idéntico al sketch productivo anterior');
checkEsp(hash_file('sha256', $root . '/firmware/esp-cerradura/esp-cerradura.ino')
    === hash_file('sha256', $root . '/docs/esp32-qr-reader/esp-cerradura.ino'),
    'copia flash-ready de ESP-cerradura idéntica');
checkEsp(str_contains($legacy, 'startLockSequence') && str_contains($legacy, 'LOCK_SEQ_HOLD_MS'),
    'el sketch anterior se conserva para compatibilidad');

// ── ESP-imán: variante, estados y seguridad eléctrica ─────────────────────
checkEsp(str_contains($iman, '#define IMAN_VARIANT                 "IMAN"')
    && str_contains($iman, '#define IMAN_VERSION                 "2.1-iman-hold950"'),
    'ESP-imán declara variante y versión');
checkEsp(str_contains($iman, 'IMAN_HOLD_ON_MS              950')
    && str_contains($iman, 'IMAN_HOLD_OFF_MS             50'),
    'perfil de ensayo 950 ms ON / 50 ms OFF');
checkEsp(str_contains($iman, 'mantenimiento %d ms ON / %d ms OFF'),
    'el arranque registra el patrón de mantenimiento');
checkEsp(str_contains($iman, 'IMAN_OPEN_WAIT_MS            4000')
    && str_contains($iman, 'IMAN_CLOSE_STABLE_MS         300'),
    'ventana de apertura 4 s y cierre estable 300 ms');
checkEsp(str_contains($iman, 'IMAN_UNSYNCED') && str_contains($iman, 'IMAN_HOLDING')
    && str_contains($iman, 'IMAN_WAIT_OPEN') && str_contains($iman, 'IMAN_OPEN')
    && str_contains($iman, 'IMAN_CLOSING'),
    'máquina de estados completa');
checkEsp(str_contains($iman, '(now - waitStarted) >= IMAN_OPEN_WAIT_MS')
    && str_contains($iman, '(now - closeStarted) >= IMAN_CLOSE_STABLE_MS'),
    'el rearme depende de tiempos y del estado del sensor, no del QR');
checkEsp(str_contains($iman, 'imanReleaseRequest = true')
    && str_contains($iman, 'imanOutput(false)'),
    'un QR autorizado libera el imán');
checkEsp(str_contains($iman, 'diagnóstico NUNCA pulsa el imán'),
    'el diagnóstico de dispositivos no pulsa el imán');
checkEsp(str_contains($iman, 'F97: ESP-imán conserva WiFi')
    && !str_contains($iman, 'limpiando NVS'),
    'el flasheo conserva WiFi/NVS (no deja la placa en provisioning)');
checkEsp(!str_contains($iman, 'tuya') && !str_contains($iman, 'presence-poller'),
    'ESP-imán no introduce polling ni cuota Tuya');

// ── Canal de puerta ───────────────────────────────────────────────────────
checkEsp(str_contains($iman, '/dashboard-api/door-events')
    && str_contains($iman, 'since_id=')
    && str_contains($iman, 'X-API-Key')
    && str_contains($iman, 'text/event-stream'),
    'ESP-imán consume SSE autenticado con since_id');
checkEsp(str_contains($iman, 'imanJsonTrue(data, "contiguous")')
    && str_contains($iman, 'imanNeedFreshEvent')
    && str_contains($iman, 'imanDoorKnown = false'),
    'un hueco no contiguo obliga a esperar un evento nuevo');
checkEsp(str_contains($controller, "sensor = 'PROXIMITY'")
    && str_contains($controller, "transport = 'ZIGBEE2MQTT'")
    && str_contains($controller, 'variant_mismatch')
    && str_contains($controller, 'device_mismatch')
    && str_contains($controller, "'contiguous'")
    && str_contains($controller, '$contiguous'),
    'SSE resuelve la puerta Zigbee del pack y exige identidad/variante');
checkEsp(str_contains($routes, "/dashboard-api/door-events")
    && str_contains($routes, 'ImanEventStreamController'),
    'ruta SSE del imán registrada antes del dispatch');
checkEsp(str_contains($panel, 'ESP_VARIANTS') && str_contains($panel, 'ESP-cerradura')
    && str_contains($panel, 'ESP-imán') && str_contains($panel, 'fc_subtype'),
    'el panel permite elegir variante en alta manual y reclamación');
checkEsp(str_contains($panel, 'body.subtype') && str_contains($panel, 'firmware_variant'),
    'el panel envía subtipo y muestra la variante del firmware');
checkEsp(hash_file('sha256', $root . '/firmware/esp-iman/esp-iman.ino')
    === hash_file('sha256', $root . '/docs/esp32-qr-reader/esp-iman.ino'),
    'copia flash-ready de ESP-imán idéntica al canónico');

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
