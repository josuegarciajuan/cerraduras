<?php
declare(strict_types=1);

/**
 * Unit tests for Device "Mírame" identify feature (TSK-ID7, RF-18).
 *
 * Covers:
 *   - identify(state=true) marks device as identified.
 *   - identify(state=false) unmarks device.
 *   - identify with unregistered external_id → throws ForbiddenException.
 *   - findIdentified() returns only marked RPI devices.
 *
 * Run:
 *   php tests/Unit/DeviceIdentifyTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use App\Domain\Devices\DeviceService;
use App\Domain\Rooms\Room;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Support\Errors\ForbiddenException;

// ============================================================================
// Fakes
// ============================================================================

final class FakeDeviceRepoForIdentify implements DeviceRepositoryInterface
{
    /** @var array<string, Device> key = kind|external_id */
    public array $byKindExt = [];
    /** @var array<int, Device> */
    public array $byId = [];
    public int $nextId = 1;

    public function addDevice(Device $d): void
    {
        $this->byKindExt[$d->kind . '|' . $d->externalId] = $d;
        if ($d->id === 0) {
            $d->id = $this->nextId++;
        }
        $this->byId[$d->id] = $d;
    }

    public function listForRoom(int $roomId): array { return []; }
    public function findById(int $id): ?Device
    {
        return $this->byId[$id] ?? null;
    }
    public function findForRoomKind(int $roomId, string $kind): ?Device { return null; }
    public function findByKindAndExternalId(string $kind, string $externalId): ?Device
    {
        return $this->byKindExt[$kind . '|' . $externalId] ?? null;
    }
    public function findByExternalId(string $externalId): ?Device { return null; }
    public function insert(int $packId, string $kind, string $externalId, ?string $label, ?int $apiClientId, ?array $meta): int { return 0; }
    public function update(int $id, array $fields, ?string $expectedStatus = null): int { return 0; }
    public function delete(int $id): int { return 0; }

    public function markIdentified(string $externalId, bool $state): ?Device
    {
        $d = $this->findByKindAndExternalId(Device::KIND_RPI, $externalId);
        if ($d === null) return null;
        $d->isIdentified = $state;
        $d->identifiedAt = $state ? date('Y-m-d\TH:i:s') : $d->identifiedAt;
        return $d;
    }

    /** @return list<array{room_id:int,code:string,device_id:int,external_id:string,identified_at:string}> */
    public function findIdentified(): array
    {
        $result = [];
        foreach ($this->byId as $d) {
            if ($d->isIdentified && $d->kind === Device::KIND_RPI) {
                $result[] = [
                    'room_id'       => $d->roomId,
                    'code'          => '101',
                    'device_id'     => $d->id,
                    'external_id'   => $d->externalId,
                    'identified_at' => $d->identifiedAt ?? '',
                ];
            }
        }
        return $result;
    }

    public function findByPackAndKind(int $packId, string $kind): array { return []; }
    public function findOneByPackAndKind(int $packId, string $kind): ?Device { return null; }
    public function findAll(): array { return array_values($this->byId); }
    public function touchPackKind(int $packId, string $kind): int { return 0; }
    public function updateLastSeen(int $deviceId): void {}
    public function updateBattery(int $deviceId, ?int $pct): void {}
    public function resolveRoomId(int $deviceId): ?int { return null; }
}

final class FakeRoomRepoForIdentify implements RoomRepositoryInterface
{
    /** @var array<int, Room> */
    public array $rooms = [];

    public function addRoom(Room $r): void { $this->rooms[$r->id] = $r; }
    public function listFiltered(array $filters, int $limit = 50, int $offset = 0): array { return []; }
    public function findById(int $id): ?Room { return $this->rooms[$id] ?? null; }
    public function findByCode(string $code): ?Room { return null; }
    public function insert(string $code, int $roomTypeId, ?bool $simulatedOverride): int { return 0; }
    public function update(int $id, array $fields, ?string $expectedStatus = null): int { return 0; }
    public function resetAfterPackRemoval(int $roomId): void {}
    public function count(array $filters): int { return 0; }
    public function findByPackId(int $packId): ?Room { return null; }
}

// ============================================================================
// Helpers
// ============================================================================

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

// ============================================================================
// Tests
// ============================================================================

echo "DeviceIdentify Unit Tests\n";
echo str_repeat("=", 60) . "\n\n";

$deviceRepo = new FakeDeviceRepoForIdentify();
$roomRepo   = new FakeRoomRepoForIdentify();
$room       = new Room(1, '101', 1, null, 'FREE', null, null);
$roomRepo->addRoom($room);

// Register an RPI device (ESP32)
$esp32 = new Device(1, 1, null, Device::KIND_RPI, '92f57630', null, null, null, null, false, null);
$deviceRepo->addDevice($esp32);

$svc = new DeviceService($deviceRepo, $roomRepo);

// ── Test 1: identify(state=true) marks device ──────────────────────────
$result = $svc->identify('92f57630', true);
if ($result['identified'] === true && $result['room_id'] === 1 && isset($result['identified_at'])) {
    pass('identify(state=true) → identified=true, room_id=1, identified_at set');
} else {
    fail('identify(state=true) → expected identified=true; got ' . json_encode($result));
}

// ── Test 2: findIdentified() returns the marked device ─────────────────
$identified = $svc->findIdentified();
if (count($identified) === 1 && $identified[0]['external_id'] === '92f57630') {
    pass('findIdentified() → returns 1 device with external_id=92f57630');
} else {
    fail('findIdentified() → expected 1 device; got ' . count($identified));
}

// ── Test 3: identify(state=false) unmarks device ───────────────────────
$result = $svc->identify('92f57630', false);
if ($result['identified'] === false) {
    pass('identify(state=false) → identified=false');
} else {
    fail('identify(state=false) → expected identified=false; got ' . json_encode($result));
}

// ── Test 4: findIdentified() after unmark returns empty ────────────────
$identified = $svc->findIdentified();
if (count($identified) === 0) {
    pass('findIdentified() after unmark → empty array');
} else {
    fail('findIdentified() after unmark → expected empty; got ' . count($identified));
}

// ── Test 5: identify with unknown external_id → ForbiddenException ─────
try {
    $svc->identify('deadbeef00', true);
    fail('identify(unknown external_id) → expected ForbiddenException');
} catch (ForbiddenException $e) {
    if ($e->getCode() === 'device_mismatch' || str_contains($e->getMessage(), 'not registered')) {
        pass('identify(unknown external_id) → throws ForbiddenException(device_mismatch)');
    } else {
        fail('identify(unknown external_id) → wrong exception type/code: ' . $e->getMessage());
    }
}

// ── Test 6: unidentify via service ─────────────────────────────────────
// Re-mark first
$svc->identify('92f57630', true);
$identifiedBefore = $svc->findIdentified();
assert(count($identifiedBefore) === 1);

$svc->unidentify(1);
$identifiedAfter = $svc->findIdentified();
if (count($identifiedAfter) === 0) {
    pass('unidentify(device_id) → device unmarked');
} else {
    fail('unidentify(device_id) → expected empty; got ' . count($identifiedAfter));
}

// ── Test 7: previous_identified_at in response when unmarking ──────────
$svc->identify('92f57630', true);
$result = $svc->identify('92f57630', false);
if (isset($result['previously_identified_at'])) {
    pass('identify(state=false) after true → includes previously_identified_at');
} else {
    fail('identify(state=false) after true → expected previously_identified_at; got keys: ' . implode(',', array_keys($result)));
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
