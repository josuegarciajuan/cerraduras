<?php
declare(strict_types=1);

/**
 * Unit tests for the SWITCH push path of TuyaSensorIngress (F50 / Bug 2).
 *
 * The SWITCH (EAWCBT-J light) is now tracked by the Pulsar consumer, so its raw
 * push reaches normalize(). These tests pin:
 *   - DP `switch` / `switch_1` (case-insensitive) → persisted `switch_state`
 *     ON/OFF + `switch_state_at` in devices.meta_json, returned as a `_noop`.
 *   - No room resolved → `_noop` with discard_reason='room_not_found' (never a
 *     500 that would make Tuya drop the push, N10).
 *   - PROXIMITY/PRESENCE are NOT affected.
 *
 * Pure and network-free: fake repository, no DB, no Tuya credentials.
 *
 * Run:
 *   php tests/Unit/TuyaSwitchIngressTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use App\Infrastructure\Gateways\Sensor\TuyaSensorIngress;

// ============================================================================
// Fake repository (records update() calls)
// ============================================================================

final class FakeDeviceRepoForSwitch implements DeviceRepositoryInterface
{
    /** @var array<string, Device> key = external_id */
    public array $byExt = [];
    /** @var array<int, int> device id → room id */
    public array $roomByDevice = [];
    /** @var list<array{id:int,fields:array<string,mixed>}> */
    public array $updates = [];
    public int $lastSeenUpdates = 0;

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
    public function update(int $id, array $fields): int
    {
        $this->updates[] = ['id' => $id, 'fields' => $fields];
        return 1;
    }
    public function delete(int $id): int { return 0; }
    public function markIdentified(string $externalId, bool $state): ?Device { return null; }
    public function findIdentified(): array { return []; }
    public function updateLastSeen(int $deviceId): void { $this->lastSeenUpdates++; }
    public function touchPackKind(int $packId, string $kind): int { return 0; }
    public function resolveRoomId(int $deviceId): ?int
    {
        return $this->roomByDevice[$deviceId] ?? null;
    }
    public function updateBattery(int $deviceId, ?int $pct): void {}
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

/** Decode the meta_json of the last recorded update for a device id. */
function lastMetaFor(FakeDeviceRepoForSwitch $repo, int $deviceId): ?array
{
    for ($i = count($repo->updates) - 1; $i >= 0; $i--) {
        $u = $repo->updates[$i];
        if ($u['id'] !== $deviceId) continue;
        $json = $u['fields']['meta_json'] ?? null;
        if (!is_string($json)) return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
    return null;
}

// ============================================================================
// Fixtures: SWITCH in room 21 + PRESENCE with no room
// ============================================================================

$repo = new FakeDeviceRepoForSwitch();

$repo->addDevice(new Device(
    701, 401, Device::KIND_SWITCH, 'switch-dev-a', 'Luz A', null,
    ['model' => 'EAWCBT-J', 'last_command' => 'ON', 'commanded_at' => '2026-09-20T10:00:00.000Z']
), 21);

$repo->addDevice(new Device(
    702, 401, Device::KIND_SWITCH, 'switch-dev-b', 'Luz B', null,
    ['model' => 'EAWCBT-J']
), 21);

// pack_id = null → resolveRoomId() is never called → room_id null.
$repo->addDevice(new Device(
    703, null, Device::KIND_PRESENCE, 'pres-dev-c', 'Presencia C', null, null
), null);

$ingress = new TuyaSensorIngress($repo);

echo "TuyaSensorIngress — SWITCH push (F50)\n";
echo str_repeat('=', 60) . "\n\n";

// ---------------------------------------------------------------------------
// T1: DP `switch` = true → switch_state=ON + switch_state_at
// ---------------------------------------------------------------------------
$tA = 1789000000000;
$evA = $ingress->normalize([
    'devId'  => 'switch-dev-a',
    'status' => [['code' => 'switch', 'value' => true, 't' => $tA]],
]);

$metaA = lastMetaFor($repo, 701);
$expectedAtA = gmdate('Y-m-d\TH:i:s\Z', (int) ($tA / 1000));
if (($evA['meta']['_noop'] ?? false) === true
    && ($evA['meta']['discard_reason'] ?? '') === 'switch_state'
    && ($evA['meta']['switch_state'] ?? '') === 'ON'
    && ($evA['value'] ?? '') === 'ON'
) {
    pass('switch=true → _noop switch_state/ON');
} else {
    fail('switch=true → expected noop switch_state/ON; got ' . json_encode($evA));
}

if ($metaA !== null && ($metaA['switch_state'] ?? '') === 'ON'
    && ($metaA['switch_state_at'] ?? '') === $expectedAtA
    && ($metaA['last_command'] ?? '') === 'ON'
) {
    pass('switch=true → meta_json persistido (switch_state=ON, switch_state_at, merge intacto)');
} else {
    fail('switch=true → meta_json esperado ON + ' . $expectedAtA . '; got ' . json_encode($metaA));
}

// ---------------------------------------------------------------------------
// T2: DP `Switch_1` = false (case-insensitive) → switch_state=OFF
// ---------------------------------------------------------------------------
$tB = 1789000060000;
$evB = $ingress->normalize([
    'devId'  => 'switch-dev-b',
    'status' => [['code' => 'Switch_1', 'value' => false, 't' => $tB]],
]);

$metaB = lastMetaFor($repo, 702);
$expectedAtB = gmdate('Y-m-d\TH:i:s\Z', (int) ($tB / 1000));
if (($evB['meta']['discard_reason'] ?? '') === 'switch_state'
    && ($evB['meta']['switch_state'] ?? '') === 'OFF'
    && $metaB !== null
    && ($metaB['switch_state'] ?? '') === 'OFF'
    && ($metaB['switch_state_at'] ?? '') === $expectedAtB
) {
    pass('Switch_1=false (case-insensitive) → switch_state=OFF persistido');
} else {
    fail('Switch_1=false → expected OFF + ' . $expectedAtB . '; got ev=' . json_encode($evB) . ' meta=' . json_encode($metaB));
}

// ---------------------------------------------------------------------------
// T3: room nula → _noop discard_reason=room_not_found (no excepción)
// ---------------------------------------------------------------------------
$threw = false;
$evC = null;
try {
    $evC = $ingress->normalize([
        'devId'  => 'pres-dev-c',
        'status' => [['code' => 'presence_state', 'value' => 'presence', 't' => 1789000120000]],
    ]);
} catch (\Throwable $e) {
    $threw = true;
}

if (!$threw
    && is_array($evC)
    && ($evC['meta']['_noop'] ?? false) === true
    && ($evC['meta']['discard_reason'] ?? '') === 'room_not_found'
    && ($evC['meta']['tuya_dev_id'] ?? '') === 'pres-dev-c'
    && ($evC['meta']['kind'] ?? '') === Device::KIND_PRESENCE
) {
    pass('room nula → _noop room_not_found (sin excepción, sin perder el push)');
} else {
    fail('room nula → expected noop room_not_found; threw=' . ($threw ? 'yes' : 'no') . ' got ' . json_encode($evC));
}

// ---------------------------------------------------------------------------
// T4: el pipeline PROXIMITY/PRESENCE no se altera (presencia con sala)
// ---------------------------------------------------------------------------
$repo->addDevice(new Device(
    704, 402, Device::KIND_PRESENCE, 'pres-dev-d', 'Presencia D', null, null
), 22);
$evD = $ingress->normalize([
    'devId'  => 'pres-dev-d',
    'status' => [['code' => 'presence_state', 'value' => 'move', 't' => 1789000180000]],
]);
if (($evD['sensor'] ?? '') === 'PRESENCE'
    && ($evD['value'] ?? '') === 'PRESENT'
    && ($evD['room_id'] ?? null) === 22
    && !isset($evD['meta']['_noop'])
) {
    pass('PRESENCE sigue mapeando move → PRESENT (sin regresión)');
} else {
    fail('PRESENCE move → expected PRESENT room 22; got ' . json_encode($evD));
}

echo "\n";
echo str_repeat('=', 60) . "\n";
echo sprintf("Total: %d passed, %d failed\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
