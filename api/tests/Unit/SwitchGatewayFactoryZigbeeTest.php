<?php
declare(strict_types=1);

/**
 * F91/TSK-91.08 — factory selects Zigbee gateway by explicit transport.
 *
 * Run:
 *   php tests/Unit/SwitchGatewayFactoryZigbeeTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use App\Domain\Rooms\Room;
use App\Infrastructure\Gateways\Switch\SimulatedSwitchGateway;
use App\Infrastructure\Gateways\Switch\SwitchGatewayFactory;
use App\Infrastructure\Gateways\Switch\TuyaSwitchGateway;
use App\Infrastructure\Gateways\Switch\ZigbeeSwitchGateway;

final class FakeRepoForSwitchFactory implements DeviceRepositoryInterface
{
    public ?Device $device = null;
    public function listForRoom(int $roomId): array { return []; }
    public function findAll(): array { return $this->device ? [$this->device] : []; }
    public function findById(int $id): ?Device { return $this->device; }
    public function findForRoomKind(int $roomId, string $kind): ?Device { return $this->device; }
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
    public function touchPackKind(int $packId, string $kind): int { return 0; }
    public function resolveRoomId(int $deviceId): ?int { return null; }
    public function updateBattery(int $deviceId, ?int $pct): void {}
}

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }
function check(string $m, bool $c): void { $c ? pass($m) : fail($m); }

$repo = new FakeRepoForSwitchFactory();
$zigbee = new Device(1, 532, Device::KIND_SWITCH, 'zb', null, null, ['topic' => 'zigbee2mqtt/almacen-zb-luz'], null, false, null, null, 'ZIGBEE2MQTT');
$tuya = new Device(2, 531, Device::KIND_SWITCH, 'tuya', null, null, null, null, false, null, null, 'TUYA');
$room = new Room(14, 'almacenZigbee', 6, 532, 'FREE', false, null);

$repo->device = $zigbee;
check('Zigbee transport selects ZigbeeSwitchGateway', SwitchGatewayFactory::make($room, $repo, $zigbee) instanceof ZigbeeSwitchGateway);

$repo->device = $tuya;
check('Tuya/unknown transport keeps TuyaSwitchGateway', SwitchGatewayFactory::make($room, $repo, $tuya) instanceof TuyaSwitchGateway);

$simRoom = new Room(15, 'sim', 1, 99, 'FREE', true, null);
check('simulated_override still selects SimulatedSwitchGateway', SwitchGatewayFactory::make($simRoom, $repo, $zigbee) instanceof SimulatedSwitchGateway);

echo "\nTotal: {$passed} passed, {$failed} failed\n\n";
exit($failed === 0 ? 0 : 1);
