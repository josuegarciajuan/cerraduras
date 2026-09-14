<?php
declare(strict_types=1);

/**
 * Unit tests for TuyaSensorIngress presence mapping (F43).
 *
 * The 24G-Presence Sensor V3 reports transient motion as `presence_state="move"`,
 * while the ZY-M100 reports "presence"/"none". Both must normalize to the
 * canonical PRESENT/ABSENT values (regression guard).
 *
 * Run:
 *   php tests/Unit/TuyaPresenceMoveTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use App\Infrastructure\Gateways\Sensor\TuyaSensorIngress;

// ============================================================================
// Fake repository
// ============================================================================

final class FakeDeviceRepoForPresence implements DeviceRepositoryInterface
{
    /** @var array<string, Device> key = external_id */
    public array $byExt = [];
    /** @var array<int, int> device id → room id */
    public array $roomByDevice = [];
    public int $lastSeenUpdates = 0;
    public int $batteryUpdates = 0;

    public function addDevice(Device $d, ?int $roomId): void
    {
        $this->byExt[$d->externalId] = $d;
        if ($roomId !== null) {
            $this->roomByDevice[$d->id] = $roomId;
        }
    }

    public function listForRoom(int $roomId): array { return []; }
    public function findAll(): array { return array_values($this->byExt); }
    public function findById(int $id): ?Device
    {
        foreach ($this->byExt as $d) { if ($d->id === $id) return $d; }
        return null;
    }
    public function findForRoomKind(int $roomId, string $kind): ?Device { return null; }
    public function findByPackAndKind(int $packId, string $kind): array { return []; }
    public function findOneByPackAndKind(int $packId, string $kind): ?Device { return null; }
    public function findByKindAndExternalId(string $kind, string $externalId): ?Device
    {
        $d = $this->byExt[$externalId] ?? null;
        return ($d !== null && $d->kind === $kind) ? $d : null;
    }
    public function findByExternalId(string $externalId): ?Device
    {
        return $this->byExt[$externalId] ?? null;
    }
    public function insert(int $packId, string $kind, string $externalId, ?string $label, ?int $apiClientId, ?array $meta): int { return 0; }
    public function update(int $id, array $fields): int { return 0; }
    public function delete(int $id): int { return 0; }
    public function markIdentified(string $externalId, bool $state): ?Device { return null; }
    public function findIdentified(): array { return []; }
    public function updateLastSeen(int $deviceId): void { $this->lastSeenUpdates++; }
    public function touchPackKind(int $packId, string $kind): int { return 0; }
    public function resolveRoomId(int $deviceId): ?int
    {
        return $this->roomByDevice[$deviceId] ?? null;
    }
    public function updateBattery(int $deviceId, ?int $pct): void { $this->batteryUpdates++; }
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

function makePayload(string $devId, string $value): array
{
    return [
        'devId'  => $devId,
        'status' => [['code' => 'presence_state', 'value' => $value, 't' => 1789000000000]],
    ];
}

// ============================================================================
// Fixture: 24G V3 sensor in pack proto2 (room 12)
// ============================================================================

$repo = new FakeDeviceRepoForPresence();
$repo->addDevice(new Device(
    501,
    305,
    Device::KIND_PRESENCE,
    'bf9a278e76e2c3f01ay0cs',
    'Sensor Presencia 24G V3',
    null,
    ['model' => '24G-Presence Sensor V3', 'provider' => 'tuya']
), 12);

$ingress = new TuyaSensorIngress($repo);

echo "TuyaSensorIngress — 24G V3 presence mapping (F43)\n";
echo str_repeat('=', 60) . "\n\n";

// T1: move → PRESENT
$ev = $ingress->normalize(makePayload('bf9a278e76e2c3f01ay0cs', 'move'));
if ($ev['sensor'] === 'PRESENCE' && $ev['value'] === 'PRESENT' && $ev['room_id'] === 12) {
    pass('presence_state="move" → PRESENCE/PRESENT in room 12');
} else {
    fail('presence_state="move" → expected PRESENCE/PRESENT room 12; got ' . json_encode($ev));
}

// T2: presence → PRESENT (ZY-M100 compatibility)
$ev = $ingress->normalize(makePayload('bf9a278e76e2c3f01ay0cs', 'presence'));
if ($ev['sensor'] === 'PRESENCE' && $ev['value'] === 'PRESENT') {
    pass('presence_state="presence" → PRESENCE/PRESENT (ZY-M100 compatible)');
} else {
    fail('presence_state="presence" → expected PRESENT; got ' . json_encode($ev));
}

// T3: none → ABSENT
$ev = $ingress->normalize(makePayload('bf9a278e76e2c3f01ay0cs', 'none'));
if ($ev['sensor'] === 'PRESENCE' && $ev['value'] === 'ABSENT') {
    pass('presence_state="none" → PRESENCE/ABSENT');
} else {
    fail('presence_state="none" → expected ABSENT; got ' . json_encode($ev));
}

// T4: poller override wins over raw "move"
$payload = makePayload('bf9a278e76e2c3f01ay0cs', 'move');
$payload['_poller_effective'] = 'ABSENT';
$ev = $ingress->normalize($payload);
if ($ev['value'] === 'ABSENT') {
    pass('_poller_effective="ABSENT" overrides raw "move"');
} else {
    fail('_poller_effective override → expected ABSENT; got ' . json_encode($ev));
}

// T5: liveness is tracked for the resolved device
if ($repo->lastSeenUpdates === 4) {
    pass('updateLastSeen called once per event (4/4)');
} else {
    fail('updateLastSeen calls expected 4; got ' . $repo->lastSeenUpdates);
}

// T6: unknown device → BadRequestException
$threw = false;
try {
    $ingress->normalize(makePayload('desconocido-xxx', 'presence'));
} catch (\Throwable $e) {
    $threw = true;
}
if ($threw) {
    pass('unknown Tuya device → rejected');
} else {
    fail('unknown Tuya device → expected exception, none thrown');
}

echo "\n";
echo str_repeat('=', 60) . "\n";
echo sprintf("Total: %d passed, %d failed\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
