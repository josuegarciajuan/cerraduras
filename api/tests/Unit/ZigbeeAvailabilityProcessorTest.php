<?php
declare(strict_types=1);

/**
 * F92/TSK-92.04: Zigbee availability is connectivity evidence only.
 *
 * Run:
 *   php tests/Unit/ZigbeeAvailabilityProcessorTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use App\Domain\Presence\IotSessionService;
use App\Infrastructure\Gateways\Sensor\ZigbeeMqttEventProcessor;

$passed = 0;
$failed = 0;

function pass(string $msg): void
{
    global $passed;
    $passed++;
    echo "  ✅ {$msg}\n";
}

function fail(string $msg, string $detail = ''): void
{
    global $failed;
    $failed++;
    echo "  ❌ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

final class FakeRepoForAvailability implements DeviceRepositoryInterface
{
    /** @var array<string,Device> */
    public array $devices = [];
    /** @var list<array{id:int,online:bool}> */
    public array $onlineCalls = [];

    public function listForRoom(int $roomId): array { return []; }
    public function findAll(): array { return array_values($this->devices); }
    public function findById(int $id): ?Device { return null; }
    public function findForRoomKind(int $roomId, string $kind): ?Device { return null; }
    public function findByPackAndKind(int $packId, string $kind): array { return []; }
    public function findOneByPackAndKind(int $packId, string $kind): ?Device { return null; }
    public function findByKindAndExternalId(string $kind, string $externalId): ?Device { return null; }
    public function findByExternalId(string $externalId): ?Device { return $this->devices[$externalId] ?? null; }
    public function insert(int $packId, string $kind, string $externalId, ?string $label, ?int $apiClientId, ?array $meta): int { return 0; }
    public function update(int $id, array $fields): int { return 0; }
    public function delete(int $id): int { return 0; }
    public function markIdentified(string $externalId, bool $state): ?Device { return null; }
    public function findIdentified(): array { return []; }
    public function updateLastSeen(int $deviceId): void {}
    public function updateOnlineState(int $deviceId, bool $online): void
    {
        $this->onlineCalls[] = ['id' => $deviceId, 'online' => $online];
    }
    public function touchPackKind(int $packId, string $kind): int { return 0; }
    public function resolveRoomId(int $deviceId): ?int { return null; }
    public function updateBattery(int $deviceId, ?int $pct): void {}
}

$repo = new FakeRepoForAvailability();
$door = new Device(
    437, 532, Device::KIND_PROXIMITY, '0xc09b9efffef431fb', 'Puerta', null, null,
    null, false, null, null, Device::TRANSPORT_ZIGBEE2MQTT
);
$legacy = new Device(
    25, 305, Device::KIND_PROXIMITY, 'legacy', 'Legacy', null, null,
    null, false, null, null, null
);
$repo->devices[$door->externalId] = $door;
$repo->devices[$legacy->externalId] = $legacy;

$iot = (new ReflectionClass(IotSessionService::class))->newInstanceWithoutConstructor();
$processor = new ZigbeeMqttEventProcessor($repo, $iot);

$online = $processor->applyAvailability([
    'diagnostic' => [
        'kind' => 'availability',
        'state' => 'online',
        'device_external_id' => $door->externalId,
    ],
]);
if (($online['status'] ?? '') === 'processed' && ($online['state'] ?? '') === 'online') {
    pass('availability online → processed');
} else {
    fail('availability online', json_encode($online));
}
if ($repo->onlineCalls === [['id' => 437, 'online' => true]]) {
    pass('availability online persiste state=true');
} else {
    fail('availability online persistencia', json_encode($repo->onlineCalls));
}

$repo->onlineCalls = [];
$offline = $processor->applyAvailability([
    'diagnostic' => [
        'kind' => 'availability',
        'state' => 'offline',
        'device_external_id' => $door->externalId,
    ],
]);
if (($offline['status'] ?? '') === 'processed' && $repo->onlineCalls === [['id' => 437, 'online' => false]]) {
    pass('availability offline → processed y persiste false');
} else {
    fail('availability offline', json_encode([$offline, $repo->onlineCalls]));
}

$wrong = $processor->applyAvailability([
    'diagnostic' => [
        'kind' => 'availability',
        'state' => 'online',
        'device_external_id' => $legacy->externalId,
    ],
]);
if (($wrong['status'] ?? '') === 'discarded' && ($wrong['reason'] ?? '') === 'wrong_transport') {
    pass('transporte distinto de Zigbee → discarded');
} else {
    fail('wrong transport', json_encode($wrong));
}

$unknown = $processor->applyAvailability([
    'diagnostic' => [
        'kind' => 'availability',
        'state' => 'online',
        'device_external_id' => 'no-existe',
    ],
]);
if (($unknown['status'] ?? '') === 'discarded' && ($unknown['reason'] ?? '') === 'unknown_device') {
    pass('dispositivo desconocido → discarded');
} else {
    fail('unknown device', json_encode($unknown));
}

$invalid = $processor->applyAvailability([
    'diagnostic' => [
        'kind' => 'availability',
        'state' => 'maybe',
        'device_external_id' => $door->externalId,
    ],
]);
if (($invalid['status'] ?? '') === 'discarded' && ($invalid['reason'] ?? '') === 'invalid_availability') {
    pass('estado inválido → discarded');
} else {
    fail('invalid availability', json_encode($invalid));
}

$ignored = $processor->applyAvailability(['diagnostic' => null]);
if (($ignored['status'] ?? '') === 'ignored') {
    pass('sin diagnóstico → ignored');
} else {
    fail('no diagnostic', json_encode($ignored));
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
