<?php
declare(strict_types=1);

/**
 * Unit tests for F91/TSK-91.02: DeviceIntegrationStatusService.
 *
 * Pure logic with temporary files; no DB, MQTT, Tuya, probe or polling.
 *
 * Run:
 *   php tests/Unit/DeviceIntegrationStatusTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceIntegrationStatusService;

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

$tmp = sys_get_temp_dir() . '/f91-status-' . getmypid();
@mkdir($tmp, 0700, true);
$mapFile = $tmp . '/map.json';
$spoolDir = $tmp . '/spool';
@mkdir($spoolDir, 0700, true);

$map = [
    'version' => 2,
    'devices' => [
        'zigbee2mqtt/almacen-zb-puerta' => [
            'external_id' => '0xc09b9efffef431fb',
            'entities' => [],
        ],
        'zigbee2mqtt/almacen-zb-presencia' => [
            'external_id' => '0xa4c138b2a3c49dbe',
            'entities' => [],
        ],
        'zigbee2mqtt/almacen-zb-luz' => [
            'external_id' => '0xf044d3fffecd9e8e',
            'entities' => [],
        ],
    ],
];
file_put_contents($mapFile, json_encode($map));

$service = new DeviceIntegrationStatusService($mapFile, $spoolDir, 180);

function row(array $overrides = []): array
{
    return array_merge([
        'id' => 1,
        'pack_id' => 10,
        'kind' => Device::KIND_PRESENCE,
        'external_id' => '0xa4c138b2a3c49dbe',
        'transport' => 'ZIGBEE2MQTT',
        'last_seen_at' => null,
    ], $overrides);
}

echo "Device integration status (F91/TSK-91.02)\n";
echo str_repeat("=", 60) . "\n\n";

// 1. No pack → draft
$st = $service->forRow(row(['pack_id' => null]));
if ($st['status'] === 'draft' && $st['reason'] === 'no_pack') {
    pass('sin pack → draft/no_pack');
} else {
    fail('sin pack inesperado: ' . json_encode($st));
}

// 2. Unclassified transport → associated/unknown_transport
$st = $service->forRow(row(['transport' => null]));
if ($st['status'] === 'associated' && $st['reason'] === 'unknown_transport') {
    pass('transport null → associated/unknown_transport');
} else {
    fail('transport null inesperado: ' . json_encode($st));
}

// 3. Explicit non-Zigbee transport → associated, no mapping
$st = $service->forRow(row(['transport' => 'TUYA']));
if ($st['status'] === 'associated' && $st['reason'] === null && $st['mapping_configured'] === false) {
    pass('TUYA → associated sin mapping');
} else {
    fail('TUYA inesperado: ' . json_encode($st));
}

// 4. Zigbee with no map file → pending_mapping
$noMap = new DeviceIntegrationStatusService($tmp . '/missing.json', $spoolDir, 180);
$st = $noMap->forRow(row());
if ($st['status'] === 'pending_mapping' && $st['mapping_configured'] === false) {
    pass('Zigbee sin mapa → pending_mapping');
} else {
    fail('Zigbee sin mapa inesperado: ' . json_encode($st));
}

// 5. Mapped but never seen → waiting_signal
$st = $service->forRow(row());
if ($st['status'] === 'waiting_signal' && $st['mapping_configured'] === true
    && $st['last_signal_at'] === null && $st['last_signal_age_seconds'] === null) {
    pass('mapeado sin señal → waiting_signal');
} else {
    fail('mapeado sin señal inesperado: ' . json_encode($st));
}

// 6. Recent presence → operational
$recent = gmdate('Y-m-d H:i:s.000', time() - 5);
$st = $service->forRow(row(['last_seen_at' => $recent]));
if ($st['status'] === 'operational' && $st['reason'] === null
    && is_int($st['last_signal_age_seconds']) && $st['last_signal_age_seconds'] >= 4) {
    pass('presencia reciente → operational con edad');
} else {
    fail('presencia reciente inesperada: ' . json_encode($st));
}
if (is_string($st['last_signal_at']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $st['last_signal_at']) === 1) {
    pass('last_signal_at se expone en ISO-8601 UTC con ms');
} else {
    fail('last_signal_at no es ISO con ms: ' . var_export($st['last_signal_at'], true));
}

// 7. Stale presence → degraded/stale
$oldPresence = gmdate('Y-m-d H:i:s.000', time() - 1000);
$st = $service->forRow(row(['last_seen_at' => $oldPresence]));
if ($st['status'] === 'degraded' && $st['reason'] === 'stale') {
    pass('presencia antigua → degraded/stale');
} else {
    fail('presencia antigua inesperada: ' . json_encode($st));
}

// 8. Quiet door/relay are not degraded by age
$oldDoor = gmdate('Y-m-d H:i:s.000', time() - 86400);
$stDoor = $service->forRow(row([
    'kind' => Device::KIND_PROXIMITY,
    'external_id' => '0xc09b9efffef431fb',
    'last_seen_at' => $oldDoor,
]));
$stRelay = $service->forRow(row([
    'kind' => Device::KIND_SWITCH,
    'external_id' => '0xf044d3fffecd9e8e',
    'last_seen_at' => $oldDoor,
]));
if ($stDoor['status'] === 'operational' && $stRelay['status'] === 'operational') {
    pass('puerta/relé silenciosos siguen operational (edge-triggered)');
} else {
    fail('puerta/relé silenciosos degradados: ' . json_encode([$stDoor, $stRelay]));
}

// 9. Unknown external_id → pending_mapping even with signal
$st = $service->forRow(row(['external_id' => '0xdeadbeef', 'last_seen_at' => $recent]));
if ($st['status'] === 'pending_mapping') {
    pass('external_id no mapeado → pending_mapping');
} else {
    fail('external_id no mapeado inesperado: ' . json_encode($st));
}

// 10. Quarantine → attention (precedence over status)
@mkdir($spoolDir . '/quarantine', 0700, true);
file_put_contents($spoolDir . '/quarantine/bad.json', '{}');
$st = $service->forRow(row(['last_seen_at' => $recent]));
if ($st['status'] === 'attention' && $st['reason'] === 'quarantine') {
    pass('cuarentena → attention/quarantine');
} else {
    fail('cuarentena inesperada: ' . json_encode($st));
}
@unlink($spoolDir . '/quarantine/bad.json');

// 11. forDevice() uses Device properties
$device = new Device(
    9,
    10,
    Device::KIND_PRESENCE,
    '0xa4c138b2a3c49dbe',
    'Zigbee · Presencia',
    null,
    null,
    null,
    false,
    null,
    null,
    'ZIGBEE2MQTT',
    $recent
);
$st = $service->forDevice($device);
if ($st['status'] === 'operational' && $st['mapping_configured'] === true) {
    pass('forDevice() resuelve desde Device');
} else {
    fail('forDevice() inesperado: ' . json_encode($st));
}

// Cleanup
@unlink($mapFile);
@rmdir($spoolDir . '/quarantine');
@rmdir($spoolDir);
@rmdir($tmp);

echo "\n" . str_repeat("=", 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
