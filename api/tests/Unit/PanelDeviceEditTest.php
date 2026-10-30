<?php
declare(strict_types=1);

/**
 * PanelDeviceEditTest — F101/RF-201..RF-206.
 *
 * Regresión estática del formulario de dispositivos del panel. Protege:
 *  - la selección reactiva de tipo y variante (el bug original: el botón
 *    cambiaba el estado pero no la clase visual),
 *  - el orden atómico variante → PATCH (sin guardados a medias),
 *  - los campos editables (ID externo, etiqueta, transporte, pack y ficha),
 *  - los mensajes de error de OTA y la cancelación de la operación.
 *
 * Run: php tests/Unit/PanelDeviceEditTest.php
 */

$passed = 0;
$failed = 0;

function passEdit(string $msg): void
{
    global $passed;
    $passed++;
    echo "  ✅ {$msg}\n";
}

function failEdit(string $msg): void
{
    global $failed;
    $failed++;
    echo "  ❌ {$msg}\n";
}

function checkEdit(bool $ok, string $msg): void
{
    $ok ? passEdit($msg) : failEdit($msg);
}

$file = __DIR__ . '/../../public/panel/index.html';
$html = file_get_contents($file);
if ($html === false) {
    fwrite(STDERR, "No se pudo leer {$file}\n");
    exit(1);
}

echo "Panel · edición de dispositivos (F101)\n";
echo str_repeat("=", 60) . "\n\n";

// ── Selección reactiva de variante ────────────────────────────────────────
checkEdit(
    str_contains($html, ':class="devForm.subtype===') && str_contains($html, ':aria-checked="devForm.subtype==='),
    'la variante usa :class/:aria-checked reactivos'
);
checkEdit(
    !str_contains($html, "class=\"seg-btn'+(this.devForm.subtype===v?' act':'')"),
    'la variante ya no pinta el estado activo como texto estático'
);
checkEdit(
    str_contains($html, ':class="devForm.kind===') && str_contains($html, ':aria-checked="devForm.kind==='),
    'el tipo de dispositivo también es reactivo'
);
checkEdit(substr_count($html, 'role="radio"') >= 2, 'los segmentos exponen role="radio"');

// ── Aviso de variante efectiva / solicitada ───────────────────────────────
checkEdit(str_contains($html, 'Variante efectiva confirmada'), 'la edición muestra la variante efectiva');
checkEdit(str_contains($html, 'Cambio solicitado a'), 'la edición muestra el cambio ya solicitado');
checkEdit(str_contains($html, '(sin firmware)'), 'avisa cuando una variante no tiene firmware publicado');
checkEdit(str_contains($html, 'El equipo aún no anuncia OTA'), 'explica el requisito de OTA antes de guardar');

// ── Guardado atómico de la variante ───────────────────────────────────────
$saveStart = strpos($html, 'async saveDevice(');
$saveEnd = strpos($html, 'deviceSaveError(e){');
checkEdit($saveStart !== false && $saveEnd !== false && $saveEnd > $saveStart, 'se localiza el guardado del dispositivo');
$save = ($saveStart !== false && $saveEnd !== false) ? substr($html, $saveStart, $saveEnd - $saveStart) : '';

$otaPos = strpos($save, 'firmware-update');
$patchPos = strpos($save, "method:'PATCH'");
checkEdit($otaPos !== false && $patchPos !== false && $otaPos < $patchPos,
    'la operación OTA se solicita antes del PATCH de los demás campos');
checkEdit(str_contains($save, 'variantAlreadyRequested'), 'no duplica una operación ya solicitada para la misma variante');
checkEdit(str_contains($save, "if(k==='RPI'&&!variantChanged)body.subtype=targetVariant"),
    'no se envía un cambio de subtipo directo al PATCH');
checkEdit(str_contains($save, "firmware-operations/'+createdOp.id+'/cancel'"),
    'si el guardado falla tras crear la OTA, la operación se cancela');
checkEdit(str_contains($save, 'f.effectiveSubtype'), 'la comparación usa la variante efectiva capturada al abrir el formulario');

// ── Errores comprensibles ─────────────────────────────────────────────────
checkEdit(str_contains($html, 'e.code=d.error?.code'), 'la API del panel conserva el código de error');
foreach ([
    'ota_not_capable',
    'firmware_release_not_found',
    'firmware_operation_active',
    'firmware_update_required',
    'device_external_id_taken',
    'invalid_transport',
] as $code) {
    checkEdit(str_contains($html, "{$code}:"), "mensaje en español para {$code}");
}
checkEdit(str_contains($html, 'deviceSaveError(e)'), 'los toasts usan el traductor de errores de dispositivo');

// ── Cancelación de la operación activa ────────────────────────────────────
checkEdit(str_contains($html, 'cancelFirmwareOp(id)'), 'se puede cancelar una operación OTA en curso');
checkEdit(str_contains($html, "op.status==='WAITING'") && str_contains($html, '/cancel'),
    'solo se ofrece cancelar cuando el backend lo permite');

// ── Resto de campos editables ─────────────────────────────────────────────
foreach ([
    'id="dvf_ext"' => 'ID externo',
    'id="dvf_label"' => 'etiqueta',
    'id="dvf_transport"' => 'origen/transporte',
    'id="dvf_pack"' => 'pack',
    'x-model="devForm.meta.' => 'ficha técnica del tipo',
] as $needle => $label) {
    checkEdit(str_contains($html, $needle), "campo editable: {$label}");
}
checkEdit(str_contains($html, 'delete meta[key]') && str_contains($html, 'meta[key]=String(v).trim()'),
    'la ficha se reemplaza sin perder metadatos de runtime');
checkEdit(str_contains($html, 'pack_id:f.pack_id?parseInt(f.pack_id):null'),
    'la edición permite asignar o quitar el pack');

echo "\n" . str_repeat("=", 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
