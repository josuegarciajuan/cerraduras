<?php
declare(strict_types=1);

/**
 * F91/TSK-91.06 — confirmed SWITCH state persistence.
 *
 * Run:
 *   php tests/Unit/ZigbeeSwitchStateServiceTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use App\Infrastructure\Gateways\Sensor\ZigbeeSwitchStateService;

final class FakeDeviceRepoForZigbeeSwitch implements DeviceRepositoryInterface
{
    /** @var list<array{id:int,fields:array<string,mixed>}> */
    public array $updates = [];
    public int $lastSeen = 0;

    public function listForRoom(int $roomId): array { return []; }
    public function findAll(): array { return []; }
    public function findById(int $id): ?Device { return null; }
    public function findForRoomKind(int $roomId, string $kind): ?Device { return null; }
    public function findByPackAndKind(int $packId, string $kind): array { return []; }
    public function findOneByPackAndKind(int $packId, string $kind): ?Device { return null; }
    public function findByKindAndExternalId(string $kind, string $externalId): ?Device { return null; }
    public function findByExternalId(string $externalId): ?Device { return null; }
    public function insert(int $packId, string $kind, string $externalId, ?string $label, ?int $apiClientId, ?array $meta): int { return 0; }
    public function update(int $id, array $fields): int { $this->updates[] = ['id' => $id, 'fields' => $fields]; return 1; }
    public function delete(int $id): int { return 0; }
    public function markIdentified(string $externalId, bool $state): ?Device { return null; }
    public function findIdentified(): array { return []; }
    public function updateLastSeen(int $deviceId): void { $this->lastSeen++; }
    public function updateOnlineState(int $deviceId, bool $online): void {}
    public function touchPackKind(int $packId, string $kind): int { return 0; }
    public function resolveRoomId(int $deviceId): ?int { return null; }
    public function updateBattery(int $deviceId, ?int $pct): void {}
}

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void
{
    if ($a === $e) { pass($m); return; }
    fail($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}

echo "Zigbee SWITCH state service (F91/TSK-91.06)\n";
echo str_repeat("=", 60) . "\n\n";

$repo = new FakeDeviceRepoForZigbeeSwitch();
$service = new ZigbeeSwitchStateService($repo);
$device = new Device(
    439,
    532,
    Device::KIND_SWITCH,
    '0xf044d3fffecd9e8e',
    'Zigbee · Luz · almacenZigbee',
    null,
    ['model' => 'ZBMINIL2', 'last_command' => 'OFF'],
    null,
    false,
    null,
    null,
    'ZIGBEE2MQTT'
);

$result = $service->apply($device, 'ON', '2026-10-27T10:00:00.123Z');
same('apply returns confirmed state', $result['state'], 'ON');
same('apply updates last_seen', $repo->lastSeen, 1);
same('apply writes one meta update', count($repo->updates), 1);

$deviceAlreadyOn = new Device(
    439,
    532,
    Device::KIND_SWITCH,
    '0xf044d3fffecd9e8e',
    'Zigbee · Luz · almacenZigbee',
    null,
    ['switch_state' => 'ON', 'switch_state_at' => '2026-10-27T10:00:00.000Z'],
    null,
    false,
    null,
    null,
    'ZIGBEE2MQTT'
);
$duplicate = $service->apply($deviceAlreadyOn, 'ON', '2026-10-27T10:00:01.000Z');
same('repeated ON is duplicate', $duplicate['duplicate'], true);
same('duplicate does not rewrite meta', count($repo->updates), 1);
same('duplicate still refreshes liveness', $repo->lastSeen, 2);

$meta = json_decode($repo->updates[0]['fields']['meta_json'], true);
same('switch_state persisted', $meta['switch_state'], 'ON');
same('switch_state_at persisted', $meta['switch_state_at'], '2026-10-27T10:00:00.123Z');
same('source marked Zigbee', $meta['switch_state_source'], 'ZIGBEE2MQTT');
same('existing meta is preserved', $meta['model'], 'ZBMINIL2');
same('last command is preserved', $meta['last_command'], 'OFF');

$bootstrapRepo = new FakeDeviceRepoForZigbeeSwitch();
$bootstrapService = new ZigbeeSwitchStateService($bootstrapRepo);
$bootstrapDevice = new Device(
    440,
    532,
    Device::KIND_SWITCH,
    '0xf044d3fffecd9e8e',
    'Zigbee · Luz',
    null,
    ['switch_state' => 'OFF'],
    null,
    false,
    null,
    null,
    'ZIGBEE2MQTT'
);
$bootstrapService->apply($bootstrapDevice, 'ON', '2026-10-27T10:00:00.123Z', false);
same('bootstrap switch no refresca liveness', $bootstrapRepo->lastSeen, 0);
same('bootstrap switch sí actualiza estado', $bootstrapRepo->updates[0]['fields']['meta_json'] !== null, true);

try {
    $service->apply($device, 'TOGGLE', '2026-10-27T10:00:00.123Z');
    fail('invalid state rejected');
} catch (InvalidArgumentException) {
    pass('invalid state rejected');
}

echo "\nTotal: {$passed} passed, {$failed} failed\n\n";
exit($failed === 0 ? 0 : 1);
