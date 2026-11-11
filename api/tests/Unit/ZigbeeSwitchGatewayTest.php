<?php
declare(strict_types=1);

/**
 * F91/TSK-91.08 — Zigbee switch gateway: topic/payload and delivery result.
 * Publisher is injected; no MQTT, broker, hardware or Tuya.
 *
 * Run:
 *   php tests/Unit/ZigbeeSwitchGatewayTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use App\Infrastructure\Gateways\Switch\ZigbeeSwitchGateway;

final class FakeDeviceRepoForZigbeeSwitchGateway implements DeviceRepositoryInterface
{
    /** @var array<int, Device> */
    public array $byRoom = [];
    public ?Device $device = null;

    public function listForRoom(int $roomId): array { return []; }
    public function findAll(): array { return $this->device ? [$this->device] : []; }
    public function findById(int $id): ?Device { return $this->device; }
    public function findForRoomKind(int $roomId, string $kind): ?Device
    {
        return ($kind === Device::KIND_SWITCH) ? ($this->byRoom[$roomId] ?? null) : null;
    }
    public function findByPackAndKind(int $packId, string $kind): array { return []; }
    public function findOneByPackAndKind(int $packId, string $kind): ?Device { return null; }
    public function findByKindAndExternalId(string $kind, string $externalId): ?Device { return null; }
    public function findByExternalId(string $externalId): ?Device { return null; }
    public function insert(int $packId, string $kind, string $externalId, ?string $label, ?int $apiClientId, ?array $meta): int { return 0; }
    public function update(int $id, array $fields): int { return 1; }
    public function delete(int $id): int { return 0; }
    public function markIdentified(string $externalId, bool $state): ?Device { return null; }
    public function findIdentified(): array { return []; }
    public function updateLastSeen(int $deviceId): void {}
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
function check(string $m, bool $c): void { $c ? pass($m) : fail($m); }

$repo = new FakeDeviceRepoForZigbeeSwitchGateway();
$device = new Device(
    439,
    532,
    Device::KIND_SWITCH,
    '0xf044d3fffecd9e8e',
    'Zigbee · Luz · almacenZigbee',
    null,
    ['topic' => 'zigbee2mqtt/almacen-zb-luz', 'model' => 'ZBMINIL2'],
    null,
    false,
    null,
    null,
    'ZIGBEE2MQTT'
);
$repo->device = $device;
$repo->byRoom[14] = $device;

$calls = [];
$publisher = function (string $topic, string $payload, array $env = []) use (&$calls): array {
    $calls[] = [$topic, $payload, $env];
    return ['ok' => true, 'error' => null];
};
$gateway = new ZigbeeSwitchGateway($repo, $publisher);

echo "Zigbee switch gateway (F91/TSK-91.08)\n";
echo str_repeat("=", 60) . "\n\n";

same('provider is ZIGBEE2MQTT', $gateway->getProvider(), 'ZIGBEE2MQTT');
same('set topic built from meta.topic', $gateway->setTopic($device), 'zigbee2mqtt/almacen-zb-luz/set');
same('payload ON', ZigbeeSwitchGateway::payloadForState('ON'), '{"state":"ON"}');
same('payload OFF', ZigbeeSwitchGateway::payloadForState('OFF'), '{"state":"OFF"}');

$on = $gateway->turnOnForDevice($device);
check('turnOnForDevice ok', ($on['ok'] ?? false) === true && ($on['pending_confirmation'] ?? false) === true);
same('publisher topic', $calls[0][0] ?? null, 'zigbee2mqtt/almacen-zb-luz/set');
same('publisher payload', $calls[0][1] ?? null, '{"state":"ON"}');
same('producción sin overrides', $calls[0][2] ?? null, []);

$off = $gateway->turnOff(14);
check('turnOff(room) resolves device and publishes', ($off['ok'] ?? false) === true);
same('room turnOff payload', $calls[1][1] ?? null, '{"state":"OFF"}');

$failPublisher = function (): array { return ['ok' => false, 'error' => 'broker_down']; };
$failing = new ZigbeeSwitchGateway($repo, $failPublisher);
$failedResult = $failing->turnOnForDevice($device);
check('publisher failure propagates', ($failedResult['ok'] ?? true) === false && ($failedResult['error'] ?? null) === 'broker_down');

$badDevice = new Device(440, 532, Device::KIND_SWITCH, 'bad', null, null, ['topic' => 'zigbee2mqtt/luz-oficina'], null, false, null, null, 'ZIGBEE2MQTT');
$bad = (new ZigbeeSwitchGateway($repo, $publisher))->turnOnForDevice($badDevice);
check('*-oficina topic rejected', ($bad['ok'] ?? true) === false && ($bad['error'] ?? null) === 'invalid_topic');

$reads = [];
$reader = function (string $topic, array $env = []) use (&$reads): array {
    $reads[] = [$topic, $env];
    return ['ok' => true, 'error' => null];
};
$probe = (new ZigbeeSwitchGateway($repo, null, $reader))->probeForDevice($device);
check('F109: probe publica lectura /get', ($probe['ok'] ?? false) === true);
same('F109: topic de sonda', $reads[0][0] ?? null, 'zigbee2mqtt/almacen-zb-luz/get');
same('F109: producción sin overrides', $reads[0][1] ?? null, []);

// F125.1.2: dispositivo dev con perfil MQTT 1886 y credenciales dev.
$apiDir = dirname(__DIR__, 2);
$runDir = $apiDir . '/run';
if (!is_dir($runDir)) {
    mkdir($runDir, 0775, true);
}
$passFile = $runDir . '/test-mqtt-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.pass';
file_put_contents($passFile, "dummy\n");
$configFile = sys_get_temp_dir() . '/zigbee-profiles-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.json';
file_put_contents($configFile, json_encode([
    'version' => 1,
    'profiles' => ['dev' => ['port' => 1886, 'username' => 'zigbee2mqtt-dev', 'password_file' => $passFile]],
], JSON_UNESCAPED_SLASHES));
$devDevice = new Device(
    1339,
    532,
    Device::KIND_SWITCH,
    '0xa4c138718a950dcf',
    'Dev · Luz',
    null,
    ['topic' => 'zigbee2mqtt/dev-zb-luz', 'mqtt_profile' => 'dev'],
    null,
    false,
    null,
    null,
    'ZIGBEE2MQTT'
);
$devCalls = [];
$devPublisher = function (string $topic, string $payload, array $env = []) use (&$devCalls): array {
    $devCalls[] = [$topic, $payload, $env];
    return ['ok' => true, 'error' => null];
};
$devGateway = new ZigbeeSwitchGateway($repo, $devPublisher, null, $configFile);
$devOn = $devGateway->turnOnForDevice($devDevice);
check('dev: turnOn ok', ($devOn['ok'] ?? false) === true);
same('dev: topic', $devCalls[0][0] ?? null, 'zigbee2mqtt/dev-zb-luz/set');
same('dev: env port', $devCalls[0][2]['ZIGBEE_MQTT_PORT'] ?? null, '1886');
same('dev: env username', $devCalls[0][2]['ZIGBEE_MQTT_USERNAME'] ?? null, 'zigbee2mqtt-dev');
same('dev: env password file', $devCalls[0][2]['ZIGBEE_MQTT_PASSWORD_FILE'] ?? null, $passFile);

$devReads = [];
$devReader = function (string $topic, array $env = []) use (&$devReads): array {
    $devReads[] = [$topic, $env];
    return ['ok' => true, 'error' => null];
};
$devProbe = (new ZigbeeSwitchGateway($repo, null, $devReader, $configFile))->probeForDevice($devDevice);
check('dev: probe ok', ($devProbe['ok'] ?? false) === true);
same('dev: probe env port', $devReads[0][1]['ZIGBEE_MQTT_PORT'] ?? null, '1886');

@unlink($passFile);
@unlink($configFile);

$noDevice = (new ZigbeeSwitchGateway($repo, $publisher))->turnOn(99);
check('missing switch returns error', ($noDevice['ok'] ?? true) === false && ($noDevice['error'] ?? null) === 'switch_not_found');

echo "\nTotal: {$passed} passed, {$failed} failed\n\n";
exit($failed === 0 ? 0 : 1);
