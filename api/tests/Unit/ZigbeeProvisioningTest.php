<?php
declare(strict_types=1);

/**
 * Static safety tests for F91/TSK-91.04 provisioning command.
 *
 * The command is intentionally manual and defaults to dry-run. These checks
 * protect the isolation invariants without touching the database.
 *
 * Run:
 *   php tests/Unit/ZigbeeProvisioningTest.php
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

$scriptPath = __DIR__ . '/../../bin/provision-zigbee-almacen.php';
$script = file_get_contents($scriptPath);
if ($script === false) {
    fwrite(STDERR, "No se pudo leer {$scriptPath}\n");
    exit(1);
}
$migration = file_get_contents(__DIR__ . '/../../migrations/0129_room_presence_mode_override.sql');

echo "Zigbee provisioning command (F91/TSK-91.04)\n";
echo str_repeat("=", 60) . "\n\n";

$checks = [
    ['sala exacta', "const ROOM_CODE = 'almacenZigbee';"],
    ['tipo de almacén reutilizado', "const ROOM_TYPE_CODE = 'ALMACEN_BEBIDAS';"],
    ['modo normal de pruebas tolerado', "const NORMAL_TYPE_CODE = 'TIPO_NORMAL';"],
    ['dry-run en modo normal', 'está en modo normal de pruebas'],
    ['pack exacto', "const PACK_CODE = 'PACK_ZIGBEE';"],
    ['nombre del pack', "const PACK_NAME = 'Pack Zigbee';"],
    ['puerta IEEE', "'0xc09b9efffef431fb'"],
    ['presencia IEEE', "'0xa4c138b2a3c49dbe'"],
    ['luz IEEE', "'0xf044d3fffecd9e8e'"],
    ['puerta como PROXIMITY', "Device::KIND_PROXIMITY"],
    ['presencia como PRESENCE', "Device::KIND_PRESENCE"],
    ['luz como SWITCH', "Device::KIND_SWITCH"],
    ['transporte Zigbee', "Device::TRANSPORT_ZIGBEE2MQTT"],
    ['etiqueta puerta', "'Zigbee · Puerta · almacenZigbee'"],
    ['etiqueta presencia', "'Zigbee · Presencia · almacenZigbee'"],
    ['etiqueta luz', "'Zigbee · Luz · almacenZigbee'"],
    ['topic puerta', "'zigbee2mqtt/' . \$spec['friendly']"],
    ['modo mixed por sala', "presence_mode_override = 'mixed'"],
    ['dry-run por defecto', "if (!\$apply)"],
    ['escritura solo con --apply', "'--apply'"],
    ['transacción', '$pdo->beginTransaction()'],
    ['rollback ante fallo', '$pdo->rollBack()'],
    ['guarda de migración', 'ejecuta php bin/migrate.php'],
    ['falla si el pack está en otra sala', 'ya está asignado a la sala'],
    ['falla ante dispositivos ajenos', 'contiene un dispositivo ajeno'],
    ['falla si hay otro dispositivo del mismo tipo', 'ya tiene otro dispositivo de tipo'],
];

foreach ($checks as [$label, $needle]) {
    if (str_contains($script, $needle)) {
        pass($label);
    } else {
        fail("falta {$label}: {$needle}");
    }
}

// Hard isolation guards.
foreach ([
    ['ningún cambio de room_types', 'UPDATE room_types'],
    ['ningún borrado de datos', 'DELETE FROM'],
    ['ningún DROP', 'DROP '],
    ['ninguna llamada a Tuya', 'tuya'],
    ['ningún arranque de servicios', 'systemctl'],
    ['ningún MQTT publisher', 'mosquitto_pub'],
] as [$label, $needle]) {
    if (!str_contains($script, $needle)) {
        pass($label);
    } else {
        fail("aparece algo prohibido: {$needle}");
    }
}

$packRepo = file_get_contents(__DIR__ . '/../../src/Domain/Devices/DevicePackRepository.php');
if (is_string($packRepo) && str_contains($packRepo, 'SELECT id, pack_id, kind, subtype, external_id, label, transport')) {
    pass('el detalle de pack incluye pack_id para integration_status');
} else {
    fail('el detalle de pack no incluye pack_id');
}

if (is_string($migration) && str_contains($migration, 'presence_mode_override')
    && str_contains($migration, 'NULL AFTER `presence_check_seconds`')) {
    pass('0129 añade presence_mode_override nullable por sala');
} else {
    fail('0129 no declara rooms.presence_mode_override');
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
