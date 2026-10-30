<?php
declare(strict_types=1);

/**
 * FirmwareOtaTest — F100/RF-180..RF-190.
 *
 * Pure/static checks for remote firmware updates: state machine, catalogue,
 * device-facing routes, panel feedback and the OTA block in both sketches.
 * No hardware, database or network required.
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Firmware\FirmwareOperationState;

$passed = 0;
$failed = 0;
function checkOta(bool $condition, string $message): void
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . "\n";
}

$root = dirname(__DIR__, 3);
$migration = (string) file_get_contents($root . '/api/migrations/0135_firmware_ota.sql');
$routes = (string) file_get_contents($root . '/api/public/index.php');
$panel = (string) file_get_contents($root . '/api/public/panel/index.html');
$controller = (string) file_get_contents($root . '/api/src/Http/Controllers/FirmwareOtaController.php');
$service = (string) file_get_contents($root . '/api/src/Domain/Firmware/FirmwareOtaService.php');
$cerradura = (string) file_get_contents($root . '/firmware/esp-cerradura/esp-cerradura.ino');
$iman = (string) file_get_contents($root . '/firmware/esp-iman/esp-iman.ino');
$cerraduraCanonical = (string) file_get_contents($root . '/docs/esp32-qr-reader/esp-cerradura.ino');
$imanCanonical = (string) file_get_contents($root . '/docs/esp32-qr-reader/esp-iman.ino');

// ── State machine ─────────────────────────────────────────────────────────
checkOta(FirmwareOperationState::canTransition('WAITING', 'PREPARING'), 'WAITING → PREPARING');
checkOta(FirmwareOperationState::canTransition('PREPARING', 'DOWNLOADING'), 'PREPARING → DOWNLOADING');
checkOta(FirmwareOperationState::canTransition('DOWNLOADING', 'VERIFYING'), 'DOWNLOADING → VERIFYING');
checkOta(FirmwareOperationState::canTransition('VERIFYING', 'REBOOTING'), 'VERIFYING → REBOOTING');
checkOta(FirmwareOperationState::canTransition('REBOOTING', 'VALIDATING'), 'REBOOTING → VALIDATING');
checkOta(FirmwareOperationState::canTransition('VALIDATING', 'COMPLETED'), 'VALIDATING → COMPLETED');
checkOta(!FirmwareOperationState::canTransition('WAITING', 'COMPLETED'), 'no se puede completar sin dispositivo');
checkOta(!FirmwareOperationState::canTransition('COMPLETED', 'DOWNLOADING'), 'COMPLETED es terminal');
checkOta(FirmwareOperationState::canTransition('UNCONFIRMED', 'COMPLETED'), 'una confirmación tardía reconcilia UNCONFIRMED');
checkOta(FirmwareOperationState::isTerminal('ROLLED_BACK'), 'ROLLED_BACK es terminal');
checkOta(!FirmwareOperationState::isTerminal('VALIDATING'), 'VALIDATING no es terminal');
checkOta(FirmwareOperationState::publicLabel('UNCONFIRMED') === 'Sin confirmación', 'etiqueta Sin confirmación');
checkOta(FirmwareOperationState::clampProgress('WAITING', 80) === 0, 'WAITING no inventa progreso');
checkOta(FirmwareOperationState::clampProgress('DOWNLOADING', 150) === 100, 'progreso acotado a 100');
checkOta(FirmwareOperationState::clampProgress('COMPLETED', 0) === 100, 'COMPLETED siempre 100');

// ── Persistencia ──────────────────────────────────────────────────────────
checkOta(str_contains($migration, 'firmware_releases') && str_contains($migration, 'device_firmware_operations'),
    'migración crea catálogo y operaciones');
checkOta(str_contains($migration, 'uq_fw_variant_version') && str_contains($migration, 'uq_fw_sha256'),
    'versiones y binarios son inmutables/únicos');
checkOta(str_contains($migration, 'idx_fwop_device_status'), 'consulta de operación activa indexada');
checkOta(!str_contains($migration, 'confirm_hash'), 'sin secreto de confirmación en claro');

// ── Contratos y seguridad ─────────────────────────────────────────────────
checkOta(str_contains($routes, '/dashboard-api/firmware-update')
    && str_contains($routes, '/dashboard-api/firmware-download')
    && str_contains($routes, '/dashboard-api/firmware-progress')
    && str_contains($routes, '/dashboard-api/firmware-confirm'),
    'rutas del dispositivo registradas');
checkOta(str_contains($routes, '/api/v1/devices/{id}/firmware-update')
    && str_contains($routes, '/api/v1/firmware-operations/{id}/cancel'),
    'rutas del panel registradas');
checkOta(str_contains($service, 'firmware_operation_mismatch')
    && str_contains($service, 'hash_equals'),
    'la operación solo puede confirmarla su dispositivo');
checkOta(str_contains($service, 'ota_not_capable'), 'equipo sin OTA se identifica explícitamente');
checkOta(str_contains($service, 'firmware_operation_active'), 'una sola operación activa por dispositivo');
checkOta(str_contains($service, 'firmware_completion_requires_confirmation'),
    'COMPLETED exige el endpoint de confirmación');
checkOta(str_contains($service, 'matches = $variant'), 'la confirmación compara variante/versión');
checkOta(str_contains($service, "\$this->devices->update(\$device->id, ['subtype' => \$variant])"),
    'el subtipo efectivo se consolida solo al confirmar');
checkOta(str_contains($controller, 'Device credential required'), 'las rutas de dispositivo exigen credencial');
checkOta(str_contains($service, 'firmware_binary_corrupt'), 'se re-verifica el hash del binario al servir');

// ── Panel ─────────────────────────────────────────────────────────────────
checkOta(str_contains($panel, 'loadFirmwareOps') && str_contains($panel, 'refreshFirmwareOps'),
    'el panel refresca operaciones OTA');
checkOta(str_contains($panel, 'Cambio solicitado') && str_contains($panel, 'status_label'),
    'el panel muestra estado y destino del cambio');
checkOta(str_contains($panel, 'variantChanged') && str_contains($panel, 'firmware-update'),
    'cambiar variante al guardar lanza la actualización');
checkOta(str_contains($panel, 'Actualizar firmware'), 'acción de actualización de la misma variante');

// ── Firmware ──────────────────────────────────────────────────────────────
checkOta(str_contains($cerradura, '#include <Update.h>') && str_contains($iman, '#include <Update.h>'),
    'ambos sketches incluyen Update.h');
checkOta(str_contains($cerradura, 'otaPerformUpdate') && str_contains($iman, 'otaPerformUpdate'),
    'ambos sketches implementan descarga/instalación');
checkOta(str_contains($cerradura, 'Update.begin') && str_contains($iman, 'Update.begin'),
    'escritura en la partición OTA inactiva');
checkOta(str_contains($cerradura, 'otaSavePending') && str_contains($iman, 'otaSavePending'),
    'la operación sobrevive al reinicio');
checkOta(str_contains($cerradura, 'firmware-confirm') && str_contains($iman, 'firmware-confirm'),
    'ambos confirman tras reiniciar');
checkOta(str_contains($cerradura, 'esp_ota_mark_app_invalid_rollback_and_reboot')
    && str_contains($iman, 'esp_ota_mark_app_invalid_rollback_and_reboot'),
    'rollback disponible ante versión no coincidente');
checkOta(str_contains($cerradura, 'ota_capable') && str_contains($iman, 'ota_capable'),
    'el heartbeat anuncia capacidad OTA');
checkOta(hash('sha256', $cerradura) === hash('sha256', $cerraduraCanonical),
    'copia canónica de ESP-cerradura sincronizada');
checkOta(hash('sha256', $iman) === hash('sha256', $imanCanonical),
    'copia canónica de ESP-imán sincronizada');

// ── F100.1: endurecimiento del cliente OTA ───────────────────────────────
checkOta(str_contains($iman, '"2.6-ota"') && str_contains($cerradura, '"2.6-ota"'),
    'versión 2.6-ota en ambos sketches');
checkOta(str_contains($iman, 'code == 200 && isJsonResponse(resp)')
    && str_contains($cerradura, 'code == 200 && isJsonResponse(resp)'),
    'la confirmación solo limpia la operación con respuesta JSON de la API');
checkOta(str_contains($iman, '!isJsonResponse(mresp)')
    && str_contains($cerradura, '!isJsonResponse(mresp)'),
    'el manifiesto exige JSON y reintenta si llega el gate');
checkOta(str_contains($iman, 'X-Firmware-SHA256') && str_contains($cerradura, 'X-Firmware-SHA256')
    && str_contains($iman, 'application/octet-stream') && str_contains($cerradura, 'application/octet-stream'),
    'la descarga valida tipo octet-stream y SHA-256 anunciados');
foreach (['IMAN' => $iman, 'CERRADURA' => $cerradura] as $name => $src) {
    $savePos = strpos($src, 'otaSavePending(opId, variant, version);');
    $verifyPos = strpos($src, 'otaReport(opId, "VERIFYING", 100);');
    checkOta($savePos !== false && $verifyPos !== false && $savePos < $verifyPos,
        "{$name}: la operación se guarda antes del reporte VERIFYING");
}

// ── F100.2: flasheo directo y documentación ──────────────────────────────
$flashScriptPath = $root . '/firmware/flash-variant.sh';
$flashScript = is_file($flashScriptPath) ? (string) file_get_contents($flashScriptPath) : '';
checkOta($flashScript !== '' && is_executable($flashScriptPath),
    'script de flasheo directo presente y ejecutable');
checkOta(str_contains($flashScript, '--variant') && str_contains($flashScript, 'read_flash')
    && str_contains($flashScript, 'upload') && str_contains($flashScript, 'firmware-publish.php'),
    'el script compila, hace backup, flashea y deja el comando de publicación');
$ops = (string) file_get_contents($root . '/docs/ops.md');
checkOta(str_contains($ops, '/dashboard-api/firmware-update')
    && str_contains($ops, '/dashboard-api/firmware-download')
    && str_contains($ops, '/dashboard-api/firmware-progress')
    && str_contains($ops, '/dashboard-api/firmware-confirm'),
    'ops.md documenta las cuatro rutas OTA de dispositivo');
checkOta(str_contains($ops, '2.6-ota') && str_contains($ops, 'flash-variant.sh'),
    'ops.md documenta versión y flasheo directo');

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
