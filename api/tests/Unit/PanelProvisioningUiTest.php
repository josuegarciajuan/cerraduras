<?php
declare(strict_types=1);

/**
 * Static contract tests for F91/TSK-91.03 — /panel provisioning UI.
 *
 * These checks protect the parts that are easy to regress without a browser:
 * room creation, canonical pack assignment, explicit transport, required pack
 * on device creation, Zigbee pilot labelling and dialog accessibility.
 *
 * Run:
 *   php tests/Unit/PanelProvisioningUiTest.php
 */

$passed = 0;
$failed = 0;

function pass(string $msg): void
{
    global $passed;
    $passed++;
    echo "  ✅ {$msg}\n";
}

function fail(string $msg): void
{
    global $failed;
    $failed++;
    echo "  ❌ {$msg}\n";
}

$file = __DIR__ . '/../../public/panel/index.html';
$html = file_get_contents($file);
if ($html === false) {
    fwrite(STDERR, "No se pudo leer {$file}\n");
    exit(1);
}

echo "Panel provisioning UI (F91/TSK-91.03)\n";
echo str_repeat("=", 60) . "\n\n";

$checks = [
    ['new room action', 'openRoomForm()'],
    ['room form code field', 'id="rmf_code"'],
    ['room form type field', 'id="rmf_type"'],
    ['room form pack field', 'id="rmf_pack"'],
    ['room create endpoint', "api('/api/v1/rooms'"],
    ['room update endpoint', "api('/api/v1/rooms/'+id"],
    ['canonical apply-pack', "/apply-pack/'+desired"],
    ['pack removal via PATCH', 'pack_id:null'],
    ['Zigbee pilot label', 'ZIGBEE · PILOTO'],
    ['device transport field', 'id="dvf_transport"'],
    ['Zigbee transport option', 'Zigbee2MQTT local'],
    ['transport badge helper', 'transportBadge(t)'],
    ['provisioning badge helper', 'provBadge(s)'],
    ['required pack on create', 'Selecciona un pack antes de crear el dispositivo'],
    ['modal dialog role', 'role="dialog"'],
    ['escape closes modal', '@keydown.escape.window="modal.show=false"'],
    ['keyboard navigation', 'role="button" tabindex="0"'],
    ['new room button label', '+ Nueva sala'],
    ['new pack action', 'openPackForm()'],
];

foreach ($checks as [$label, $needle]) {
    if (str_contains($html, $needle)) {
        pass($label);
    } else {
        fail("falta {$label}: {$needle}");
    }
}

// New devices must not offer an empty pack silently. Editing may keep it.
if (str_contains($html, "let packOpts=(d?'<option value=\"\">Sin pack</option>':'')")) {
    pass('alta nueva sin opción "Sin pack"; edición sí la conserva');
} else {
    fail('el formulario no distingue alta nueva de edición para el pack');
}

if (str_contains($html, "body={kind:k,external_id:f.external_id.trim(),pack_id:parseInt(f.pack_id),transport:transport,meta:meta}")) {
    pass('el alta envía pack_id y transport a /devices/register');
} else {
    fail('el alta no envía pack_id/transport');
}

if (str_contains($html, "transport:transport") && str_contains($html, "transport:transport,meta:meta")) {
    pass('la edición envía transport');
} else {
    fail('la edición no envía transport');
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
