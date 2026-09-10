<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\FactoryDevices\FactoryDevice;
use App\Domain\FactoryDevices\FactoryDeviceRepositoryInterface;
use App\Domain\FactoryDevices\FactoryDeviceService;
use App\Support\Errors\BadRequestException;
use App\Support\Errors\ForbiddenException;

final class InMemoryFactoryDeviceRepository implements FactoryDeviceRepositoryInterface
{
    /** @var array<int,FactoryDevice> */
    public array $items = [];
    private int $nextId = 1;
    public int $announceCalls = 0;
    public array $claimActors = [];
    public array $consumed = [];

    public function findById(int $id): ?FactoryDevice { return $this->items[$id] ?? null; }
    public function findByChipId(string $chipId): ?FactoryDevice
    {
        foreach ($this->items as $item) if ($item->chipId === $chipId) return $item;
        return null;
    }
    public function findClaimedById(int $id): ?FactoryDevice { return $this->consumed[$id] ?? (($this->items[$id] ?? null)?->status === FactoryDevice::STATUS_CLAIMED ? $this->items[$id] : null); }
    public function announce(string $chipId, string $enrollmentHash): array
    {
        $this->announceCalls++;
        $existing = $this->findByChipId($chipId);
        if ($existing !== null) {
            if ($existing->status === FactoryDevice::STATUS_CLAIMED && $enrollmentHash !== hash('sha256', str_repeat('a', 48))) throw new ForbiddenException('invalid_factory_credential', 'Factory credential rejected');
            $existing->lastAnnouncedAt = 'later';
            return [$existing, false];
        }
        foreach ($this->consumed as $claimed) if ($claimed->chipId === $chipId) {
            if ($enrollmentHash !== hash('sha256', str_repeat('a', 48))) throw new ForbiddenException('invalid_factory_credential', 'Factory credential rejected');
            return [$claimed, false];
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
    public function claimAndAudit(int $id, string $actor, ?int $actorClientId = null, ?string $label = null, ?int $packId = null): ?FactoryDevice
    {
        $claimed = $this->claim($id, $actor, $actorClientId);
        if ($claimed !== null && $claimed->status === FactoryDevice::STATUS_CLAIMED) { $this->consumed[$id] = $claimed; unset($this->items[$id]); }
        return $claimed;
    }
}

$repo = new InMemoryFactoryDeviceRepository();
$service = new FactoryDeviceService($repo);
$passed = 0; $failed = 0;
function checkFactory(bool $condition, string $message): void { global $passed, $failed; $condition ? $passed++ : $failed++; echo ($condition ? "PASS " : "FAIL ") . $message . "\n"; }

echo "FactoryDeviceTest\n";
$key = str_repeat('a', 48);
$check = $service->announce('a1b2c3d4e5f6', $key);
checkFactory($check['created'] === true && $check['data']['chip_id'] === 'a1b2c3d4e5f6' && $check['data']['status'] === 'PENDING', 'accepts the canonical six-byte lowercase chip id format');
$retry = $service->announce('112233445566', $key);
checkFactory($retry['created'] === true && count($repo->items) === 2, 'different chip ids remain distinct');
$retry = $service->announce('a1b2c3d4e5f6', $key);
checkFactory($retry['created'] === false && count($repo->items) === 2, 'announcement is idempotent by chip id');
$claimed = $service->claim(1, 'operator-1');
checkFactory($claimed['status'] === 'CLAIMED' && $claimed['claimed_by'] === 'operator-1', 'claim changes pending to claimed');
checkFactory(!isset($repo->items[1]) && $service->announce('a1b2c3d4e5f6', $key)['data']['status'] === 'CLAIMED', 'claimed announcement does not recreate a factory row');
try { $service->announce('a1b2c3d4e5f6', str_repeat('b', 48)); checkFactory(false, 'rejects mismatched post-claim credential'); } catch (ForbiddenException $e) { checkFactory(true, 'rejects mismatched post-claim credential'); }
$again = $service->claim(1, 'operator-2');
checkFactory($again['claimed_by'] === 'operator-1', 'repeated claim preserves audit actor');
checkFactory($service->announce('a1b2c3d4e5f6', $key)['data']['status'] === 'CLAIMED', 'later announcement never downgrades claimed state');
try { $service->announce('not-a-chip', $key); checkFactory(false, 'rejects non-format chip id'); } catch (BadRequestException $e) { checkFactory(true, 'rejects non-format chip id'); }
try { $service->announce('A1B2C3D4E5F6', $key); checkFactory(false, 'accepts only the firmware chip format'); } catch (BadRequestException $e) { checkFactory(true, 'accepts only the firmware chip format'); }
checkFactory($repo->announceCalls === 6, 'invalid chip id does not reach repository');
$service->claim(2, 'operator-1', 42);
checkFactory($repo->claimActors[1] === ['operator-1', 42], 'claim preserves authenticated client identity');
$source = (string) file_get_contents(__DIR__ . '/../../src/Domain/FactoryDevices/FactoryDeviceRepositoryInterface.php');
checkFactory(str_contains($source, 'claimAndAudit') && !str_contains($source, 'claimAndAudit(int $id, string $chipId'), 'claim and audit use one repository transaction boundary');
$routes = (string) file_get_contents(__DIR__ . '/../../public/index.php');
checkFactory(str_contains($routes, 'factory-devices/{id}/claim\', [$factoryDeviceController, \'claim\'], $authFactory([\'factory:claim\'])'), 'claim requires dedicated authorization scope');
checkFactory(str_contains($routes, 'factory-devices/announce\', [$factoryDeviceController, \'announce\']);'), 'announcement does not use a shared API key');
$panel = (string) file_get_contents(__DIR__ . '/../../public/panel/index.html');
checkFactory(str_contains($panel, "JSON.stringify({label:label||null,pack_id:pack?parseInt(pack):null})") && !str_contains($panel, 'fc_key'), 'panel claim sends only optional metadata and uses the record id');
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
    !str_contains($firmware, 'API_KEY')
    && str_contains($firmware, 'device-cred')
    && str_contains($firmware, 'esp_random()')
    && str_contains($firmware, 'factory_key')
    && str_contains($firmware, 'https://'),
    'firmware uses a per-device NVS credential and HTTPS without shared key'
);
checkFactory(
    str_contains($firmware, 'code >= 400 && code < 500')
    && str_contains($firmware, 'code != 429')
    && str_contains($firmware, 'anuncio detenido'),
    'terminal 4xx from announce stops retrying instead of looping every 30s'
);
$robusto = (string) file_get_contents(__DIR__ . '/../../../docs/esp32-qr-reader/scanner-relay-prod-12v-robusto.ino');
$usbCbStart = strpos($robusto, 'usb.onDeviceConnected');
$usbCbEnd = strpos($robusto, 'usb.onDeviceDisconnected');
$usbConnectCb = ($usbCbStart !== false && $usbCbEnd !== false && $usbCbEnd > $usbCbStart)
    ? substr($robusto, $usbCbStart, $usbCbEnd - $usbCbStart) : '';
checkFactory(
    $usbConnectCb !== ''
    && str_contains($usbConnectCb, 'scannerBeatPending')
    && !str_contains($usbConnectCb, 'beginApiRequest')
    && !str_contains($usbConnectCb, 'HTTPClient')
    && substr_count($robusto, 'scannerBeatPending') >= 3,
    'USB connect callback defers network I/O to loop (no shared TLS race)'
);
checkFactory(
    str_contains($robusto, '#define RELAY_ACTIVE_LOW  1')
    && str_contains($robusto, '#if RELAY_ACTIVE_LOW')
    && str_contains($robusto, 'pinMode(RELAY_PIN, INPUT);   // tri-state: reposo limpio')
    && str_contains($robusto, 'digitalWrite(RELAY_PIN, LOW);'),
    'recovered relay uses ACTIVE-LOW tri-state idle (LOW on, FLOAT off)'
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
// Canonical (F30): the INSERT no longer carries room_id — an unassigned RPI is
// created bound only to its pack (devices.room_id column was removed).
checkFactory(str_contains($repository, 'INSERT INTO devices') && str_contains($repository, 'pack_id') && !str_contains($repository, 'room_id') && str_contains($repository, 'beginTransaction'), 'claim creates an unassigned RPI bound to a pack (no direct room_id) in the same transaction');
checkFactory(str_contains($repository, 'DELETE FROM factory_devices') && str_contains($repository, 'INSERT INTO audit_log'), 'claim audits before consuming the factory row');
checkFactory(str_contains($repository, 'FROM devices d') && str_contains($repository, 'api_clients c') && str_contains($repository, 'CLAIMED'), 'post-claim announce resolves the linked RPI as logical CLAIMED');

checkFactory(
    str_contains($firmware, 'prefs.remove("ssid")')
    && str_contains($firmware, 'prefs.remove("pass")')
    && str_contains($firmware, 'prefs.remove("last_ssid")')
    && str_contains($firmware, 'prefs.remove("build_marker")')
    && !str_contains($firmware, 'prefs.clear()')
    && !str_contains($firmware, 'credentials.clear()'),
    'new builds reset only WiFi provisioning and preserve device credentials'
);
checkFactory(
    str_contains($firmware, 'ESP.getSketchMD5()')
    && str_contains($firmware, '__DATE__')
    && str_contains($firmware, '__TIME__')
    && str_contains($firmware, 'build_marker'),
    'build marker changes automatically with the compiled sketch'
);
checkFactory(
    str_contains($repository, 'c.device_id')
    && str_contains($repository, 'duplicateBinding')
    && str_contains($repository, 'already linked to another device'),
    'claim rejects duplicate device-client bindings without leaving partial resources'
);

exit($failed === 0 ? 0 : 1);
