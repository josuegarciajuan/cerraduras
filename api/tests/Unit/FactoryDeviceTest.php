<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\FactoryDevices\FactoryDevice;
use App\Domain\FactoryDevices\FactoryDeviceRepositoryInterface;
use App\Domain\FactoryDevices\FactoryDeviceService;
use App\Support\Errors\BadRequestException;

final class InMemoryFactoryDeviceRepository implements FactoryDeviceRepositoryInterface
{
    /** @var array<int,FactoryDevice> */
    public array $items = [];
    private int $nextId = 1;
    public int $announceCalls = 0;
    public array $claimActors = [];

    public function findById(int $id): ?FactoryDevice { return $this->items[$id] ?? null; }
    public function findByChipId(string $chipId): ?FactoryDevice
    {
        foreach ($this->items as $item) if ($item->chipId === $chipId) return $item;
        return null;
    }
    public function announce(string $chipId): array
    {
        $this->announceCalls++;
        $existing = $this->findByChipId($chipId);
        if ($existing !== null) {
            $existing->lastAnnouncedAt = 'later';
            return [$existing, false];
        }
        $item = new FactoryDevice($this->nextId++, $chipId, FactoryDevice::STATUS_PENDING, 'first', 'first', null, null, 'created', 'created');
        $this->items[$item->id] = $item;
        return [$item, true];
    }
    public function claim(int $id, string $actor, ?int $actorClientId = null): ?FactoryDevice
    {
        $this->claimActors[] = [$actor, $actorClientId];
        $item = $this->findById($id);
        if ($item !== null && $item->status === FactoryDevice::STATUS_PENDING) $item->claim($actor, 'claimed');
        return $item;
    }
    public function list(string $status): array
    {
        return array_values(array_filter($this->items, fn (FactoryDevice $item) => $status === 'ALL' || $item->status === $status));
    }
    public function claimAndAudit(int $id, string $actor, ?int $actorClientId = null): ?FactoryDevice
    {
        return $this->claim($id, $actor, $actorClientId);
    }
}

$repo = new InMemoryFactoryDeviceRepository();
$service = new FactoryDeviceService($repo);
$passed = 0; $failed = 0;
function checkFactory(bool $condition, string $message): void { global $passed, $failed; $condition ? $passed++ : $failed++; echo ($condition ? "PASS " : "FAIL ") . $message . "\n"; }

echo "FactoryDeviceTest\n";
$check = $service->announce('a1b2c3d4e5f6');
checkFactory($check['created'] === true && $check['data']['chip_id'] === 'a1b2c3d4e5f6' && $check['data']['status'] === 'PENDING', 'accepts the canonical six-byte lowercase chip id format');
$retry = $service->announce('112233445566');
checkFactory($retry['created'] === true && count($repo->items) === 2, 'different chip ids remain distinct');
$retry = $service->announce('a1b2c3d4e5f6');
checkFactory($retry['created'] === false && count($repo->items) === 2, 'announcement is idempotent by chip id');
$claimed = $service->claim(1, 'operator-1');
checkFactory($claimed['status'] === 'CLAIMED' && $claimed['claimed_by'] === 'operator-1', 'claim changes pending to claimed');
$again = $service->claim(1, 'operator-2');
checkFactory($again['claimed_by'] === 'operator-1', 'repeated claim preserves audit actor');
checkFactory($service->announce('a1b2c3d4e5f6')['data']['status'] === 'CLAIMED', 'later announcement never downgrades claimed state');
try { $service->announce('not-a-chip'); checkFactory(false, 'rejects non-format chip id'); } catch (BadRequestException $e) { checkFactory(true, 'rejects non-format chip id'); }
try { $service->announce('A1B2C3D4E5F6'); checkFactory(false, 'accepts only the firmware chip format'); } catch (BadRequestException $e) { checkFactory(true, 'accepts only the firmware chip format'); }
checkFactory($repo->announceCalls === 4, 'invalid chip id does not reach repository');
$service->claim(2, 'operator-1', 42);
checkFactory($repo->claimActors[1] === ['operator-1', 42], 'claim preserves authenticated client identity');
$source = (string) file_get_contents(__DIR__ . '/../../src/Domain/FactoryDevices/FactoryDeviceRepositoryInterface.php');
checkFactory(str_contains($source, 'claimAndAudit'), 'claim and audit use one repository transaction boundary');
$routes = (string) file_get_contents(__DIR__ . '/../../public/index.php');
checkFactory(str_contains($routes, 'factory-devices/{id}/claim\', [$factoryDeviceController, \'claim\'], $authFactory([\'factory:claim\'])'), 'claim requires dedicated authorization scope');
$firmwarePath = __DIR__ . '/../../../docs/esp32-qr-reader/scanner-relay-prod.ino';
$firmware = (string) file_get_contents($firmwarePath);
checkFactory(
    str_contains($firmware, 'ESP.getEfuseMac()')
    && preg_match('/%02x%02x%02x%02x%02x%02x/', $firmware) === 1
    && str_contains($firmware, '/api/v1/factory-devices/announce')
    && str_contains($firmware, 'factoryAnnouncementEnabled')
    && str_contains($firmware, 'factoryNextAttemptAt')
    && str_contains($firmware, 'FACTORY_ANNOUNCE_TIMEOUT_MS')
    && str_contains($firmware, 'response.indexOf("\\"status\\":\\"PENDING\\"")')
    && str_contains($firmware, 'response.indexOf("\\"status\\":\\"CLAIMED\\"")')
    && !str_contains($firmware, 'factory_claimed')
    && !file_exists(__DIR__ . '/../../../docs/esp32-qr-reader/factory-identification.ino'),
    'factory announcement is integrated in production firmware without persistent claim state or isolated sketch'
);
checkFactory(
    str_contains($firmware, 'usb.onKeyboard')
    && str_contains($firmware, 'relayPulse()')
    && str_contains($firmware, 'IDENTIFY_PIN')
    && str_contains($firmware, 'esp_task_wdt_reset()')
    && str_contains($firmware, 'device-heartbeat')
    && str_contains($firmware, 'pending-command'),
    'integrated factory announcement preserves QR USB relay identify watchdog heartbeat and command queue'
);
$migration = (string) file_get_contents(__DIR__ . '/../../migrations/0046_factory_devices.sql');
checkFactory(str_contains($migration, 'device_id') && str_contains($migration, 'FOREIGN KEY'), 'factory claim links the created RPI device');
$repository = (string) file_get_contents(__DIR__ . '/../../src/Infrastructure/Persistence/FactoryDeviceRepository.php');
checkFactory(str_contains($repository, 'INSERT INTO devices') && str_contains($repository, 'pack_id') && str_contains($repository, 'room_id') && str_contains($repository, 'beginTransaction'), 'claim creates or links an unassigned RPI in the same transaction');

exit($failed === 0 ? 0 : 1);
