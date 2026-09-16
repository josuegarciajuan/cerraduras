<?php
declare(strict_types=1);

/**
 * Unit tests for QrValidateService (TSK-070).
 *
 * Uses in-memory fakes; no database required.
 *
 * Run:
 *   php tests/Unit/QrValidateServiceTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use App\Domain\Locks\AccessEventRepositoryInterface;
use App\Domain\Qr\QrCredential;
use App\Domain\Qr\QrCredentialRepositoryInterface;
use App\Domain\Qr\QrValidateService;
use App\Domain\Rooms\Room;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Stays\Stay;
use App\Domain\Stays\StayRepositoryInterface;
use App\Domain\Stays\StayStateMachine;
use App\Infrastructure\Gateways\Lock\LockGatewayInterface;
use App\Support\Clock;
use App\Support\Errors\ApiException;
use App\Support\Errors\ForbiddenException;
use App\Support\Qr\QrTokenizer;
use App\Support\Uuid;

// ============================================================================
// Fakes
// ============================================================================

final class FakeCredentialRepo implements QrCredentialRepositoryInterface
{
    /** @var array<string,QrCredential> */
    public array $byJti   = [];
    public array $byHash  = [];
    public array $consumed = [];

    public function insert(int $sId, int $rId, string $j, string $h, string $i, string $e): int
    {
        $c = new QrCredential(count($this->byJti)+1, $sId, $rId, $j, $h, $i, $e, null, null);
        $this->byJti[$j] = $c;
        $this->byHash[$h] = $c;
        return $c->id;
    }
    public function findByJti(string $j): ?QrCredential { return $this->byJti[$j] ?? null; }
    public function findByTokenHash(string $h): ?QrCredential { return $this->byHash[$h] ?? null; }
    public function markConsumed(string $j): bool
    {
        if (!isset($this->byJti[$j]) || $this->byJti[$j]->consumedAt !== null) return false;
        $this->byJti[$j]->consumedAt = '2026-04-28 10:00:00.000';
        $this->consumed[] = $j;
        return true;
    }
    public function markRevoked(string $j): bool
    {
        if (!isset($this->byJti[$j]) || $this->byJti[$j]->revokedAt !== null) return false;
        $this->byJti[$j]->revokedAt = '2026-04-28 10:00:00.000';
        return true;
    }
}

final class FakeRoomRepo implements RoomRepositoryInterface
{
    /** @var array<int,Room> */
    public array $byId = [];
    public function listFiltered(array $f, int $l = 50, int $o = 0): array { return array_values($this->byId); }
    public function findById(int $id): ?Room { return $this->byId[$id] ?? null; }
    public function findByCode(string $c): ?Room { return null; }
    public function insert(string $c, int $rt, ?bool $s): int { return 0; }
    public function update(int $id, array $f, ?string $expectedStatus = null): int { return 0; }
    public function resetAfterPackRemoval(int $roomId): void {}
    public function findByPackId(int $packId): ?Room
    {
        foreach ($this->byId as $room) {
            if ($room->packId === $packId) {
                return $room;
            }
        }
        return null;
    }
}

final class FakeStayRepo implements StayRepositoryInterface
{
    /** @var array<int,Stay> */
    public array $byId = [];
    /** @var array<int,Stay|null> room_id -> active stay */
    public array $activeByRoom = [];
    public array $updates = [];

    public function insertReserved(int $roomId, int $dur, array $refs): int { return 0; }
    public function findById(int $id): ?Stay { return $this->byId[$id] ?? null; }
    public function findActiveForRoom(int $roomId): ?Stay { return $this->activeByRoom[$roomId] ?? null; }
    public function lockActiveForRoom(int $roomId): ?Stay { return $this->findActiveForRoom($roomId); }
    public function listFiltered(array $f, int $l = 50, int $o = 0): array { return []; }
    public function update(int $id, array $fields, ?string $expectedStatus = null): int
    {
        $this->updates[] = ['id' => $id, 'fields' => $fields];
        if (isset($this->byId[$id])) {
            $s = $this->byId[$id];
            foreach ($fields as $k => $v) {
                switch ($k) {
                    case 'status': $s->status = (string) $v; break;
                    case 'first_entry_at': $s->firstEntryAt = $v; break;
                }
            }
        }
        return 1;
    }
}

// Minimal stub — no devices registered by default
final class FakeDeviceRepo implements DeviceRepositoryInterface
{
    /** @var Device[] */
    public array $devices = [];

    /** Room fake used to resolve a device's room via its pack (canonical F30). */
    public ?FakeRoomRepo $rooms = null;

    public function listForRoom(int $roomId): array { return []; }
    public function findById(int $id): ?Device
    {
        foreach ($this->devices as $d) {
            if ($d->id === $id) return $d;
        }
        return null;
    }
    public function findForRoomKind(int $roomId, string $kind): ?Device { return null; }
    public function findByKindAndExternalId(string $kind, string $externalId): ?Device
    {
        foreach ($this->devices as $d) {
            if ($d->kind === $kind && $d->externalId === $externalId) return $d;
        }
        return null;
    }
    public function findByExternalId(string $externalId): ?Device
    {
        foreach ($this->devices as $d) {
            if ($d->externalId === $externalId) return $d;
        }
        return null;
    }
    public function insert(int $packId, string $kind, string $externalId, ?string $label, ?int $apiClientId, ?array $meta): int { return 0; }
    public function update(int $id, array $fields, ?string $expectedStatus = null): int { return 0; }
    public function delete(int $id): int { return 0; }
    public function markIdentified(string $externalId, bool $state): ?Device { return null; }
    public function findIdentified(): array { return []; }
    public function findByPackAndKind(int $packId, string $kind): array { return []; }
    public function findOneByPackAndKind(int $packId, string $kind): ?Device { return null; }
    public function findAll(): array { return []; }
    public function touchPackKind(int $packId, string $kind): int { return 0; }
    public function updateLastSeen(int $deviceId): void {}
    public function resolveRoomId(int $deviceId): ?int
    {
        $d = $this->findById($deviceId);
        if ($d === null || $d->packId === null || $this->rooms === null) {
            return null;
        }
        $room = $this->rooms->findByPackId($d->packId);
        return $room !== null ? $room->id : null;
    }
    public function updateBattery(int $deviceId, ?int $pct): void {}
}

final class FakeLockGateway implements LockGatewayInterface
{
    public bool $shouldFail = false;
    public int $openCalls = 0;

    public function open(int $roomId, array $ctx = []): array
    {
        $this->openCalls++;
        return ['ok' => !$this->shouldFail, 'provider' => 'SIMULATED', 'error' => $this->shouldFail ? 'simulated_fail' : null];
    }
    public function lock(int $roomId, array $ctx = []): array
    {
        return ['ok' => true, 'provider' => 'SIMULATED', 'error' => null];
    }
    public function getProvider(): string { return 'SIMULATED'; }
}

final class FakeAccessEventRepo implements AccessEventRepositoryInterface
{
    public array $events = [];
    public function insert(int $roomId, ?int $stayId, string $kind, string $result, ?string $reason, string $provider, string $correlationId, ?array $meta, ?int $workerSessionId = null): int
    {
        $this->events[] = compact('roomId','stayId','kind','result','reason','provider','correlationId','meta');
        return count($this->events);
    }
}

// ============================================================================
// Test helpers
// ============================================================================

$PASS = 0; $FAIL = 0;

function ok(string $l): void { global $PASS; $PASS++; echo "  PASS  {$l}\n"; }
function bad(string $l, string $w = ''): void { global $FAIL; $FAIL++; echo "  FAIL  {$l} {$w}\n"; }
function expect(string $code, callable $fn, string $label): void {
    try { $fn(); bad($label, '(no exception thrown)'); }
    catch (ApiException $e) {
        if ($e->errorCode() === $code) ok($label);
        else bad($label, "(got code={$e->errorCode()} msg={$e->getMessage()})");
    }
    catch (\Throwable $e) { bad($label, "(unexpected ".get_class($e).": {$e->getMessage()})"); }
}

// ============================================================================
// Shared fixtures
// ============================================================================

$secret  = str_repeat('V', 32);
$tk      = new QrTokenizer($secret);

Clock::freeze(new DateTimeImmutable('2026-04-28T10:00:00Z', new DateTimeZone('UTC')));
$now   = Clock::nowUtc()->getTimestamp();
$exp   = $now + 1800; // 30 min from now

$roomId  = 1;
$stayId  = 10;
$jti     = Uuid::v4();
$token   = $tk->issue($roomId, $stayId, $jti, $now, $exp);

// Build shared repository fakes
$credRepo  = new FakeCredentialRepo();
$roomRepo  = new FakeRoomRepo();
$stayRepo  = new FakeStayRepo();
$devRepo   = new FakeDeviceRepo();
$lockGw    = new FakeLockGateway();
$evRepo    = new FakeAccessEventRepo();

// Populate
$credRepo->insert($stayId, $roomId, $jti, 'x', '2026-04-28 09:55:00.000', '2026-04-28 10:25:00.000');
// Fix the actual cred to have the right jti
$credRepo->byJti[$jti] = new QrCredential(1, $stayId, $roomId, $jti, 'x', '2026-04-28 09:55:00.000', '2026-04-28 10:25:00.000', null, null);

$roomRepo->byId[$roomId] = new Room($roomId, '101', 1, null, Room::STATUS_RESERVED, true, null);

$stay = new Stay($stayId, $roomId, Stay::STATUS_RESERVED, 60,
    '2026-04-28 09:55:00.000', null, null, null,
    null, null, null, null, null, null, null, null, null,
    '2026-04-28 09:55:00.000', '2026-04-28 09:55:00.000'
);
$stayRepo->byId[$stayId]     = $stay;
$stayRepo->activeByRoom[$roomId] = $stay;

$sm      = new StayStateMachine($stayRepo);
$service = new QrValidateService($tk, $credRepo, $roomRepo, $devRepo, $stayRepo, $sm, $lockGw, $evRepo);

echo "QrValidateService\n";

// ============================================================================
// Test 1: Happy path — RESERVED → OCCUPIED, jti consumed, lock opened
// ============================================================================
$result = $service->validate($token, 'rpi-101', 'test-corr-1');
if ($result['allow'] === true && $result['stay_id'] === $stayId && $result['room_id'] === $roomId) {
    ok('happy path: allow=true, stay_id and room_id correct');
} else {
    bad('happy path: allow=true', 'got ' . json_encode($result));
}

if ($stay->status === Stay::STATUS_OCCUPIED) {
    ok('happy path: stay transitioned to OCCUPIED');
} else {
    bad('happy path: stay transitioned to OCCUPIED', "got status={$stay->status}");
}

if (in_array($jti, $credRepo->consumed, true)) {
    ok('happy path: jti marked consumed');
} else {
    bad('happy path: jti marked consumed');
}

if ($lockGw->openCalls === 1) {
    ok('happy path: gateway.open() called once');
} else {
    bad('happy path: gateway.open() called once', "calls={$lockGw->openCalls}");
}

// ============================================================================
// Test 2: Re-entry — jti already consumed + stay OCCUPIED → allow
// ============================================================================
$result2 = $service->validate($token, 'rpi-101', 'test-corr-2');
if ($result2['allow'] === true) {
    ok('re-entry (jti consumed + OCCUPIED): allow=true');
} else {
    bad('re-entry (jti consumed + OCCUPIED): allow=true', json_encode($result2));
}
if ($lockGw->openCalls === 2) {
    ok('re-entry: gateway.open() called again');
} else {
    bad('re-entry: gateway.open() called again', "calls={$lockGw->openCalls}");
}

// ============================================================================
// Test 3: jti consumed + stay NOT occupied → qr_already_used
// ============================================================================
$jti2  = Uuid::v4();
$tok2  = $tk->issue($roomId, 999, $jti2, $now, $exp);
$credRepo->byJti[$jti2] = new QrCredential(2, 999, $roomId, $jti2, 'y',
    '2026-04-28 09:55:00.000', '2026-04-28 10:25:00.000',
    '2026-04-28 10:01:00.000', // consumed
    null
);
// Stay 999 not in repo → no active stay for any room → reEntry=false
expect('qr_already_used', function () use ($service, $tok2) {
    $service->validate($tok2, 'rpi-101', 'test-corr-3');
}, 'consumed jti + no active OCCUPIED stay → qr_already_used');

// ============================================================================
// Test 4: revoked jti
// ============================================================================
$jti3 = Uuid::v4();
$tok3 = $tk->issue($roomId, $stayId, $jti3, $now, $exp);
$credRepo->byJti[$jti3] = new QrCredential(3, $stayId, $roomId, $jti3, 'z',
    '2026-04-28 09:55:00.000', '2026-04-28 10:25:00.000',
    null,
    '2026-04-28 09:58:00.000' // revoked
);
expect('qr_revoked', function () use ($service, $tok3) {
    $service->validate($tok3, 'rpi-101', 'test-corr-4');
}, 'revoked jti → qr_revoked');

// ============================================================================
// Test 5: invalid signature
// ============================================================================
$badToken = $token;
$badToken[strlen($badToken)-1] = ($badToken[strlen($badToken)-1] === 'x') ? 'y' : 'x';
expect('qr_invalid_signature', function () use ($service, $badToken) {
    $service->validate($badToken, 'rpi-101', 'test-corr-5');
}, 'tampered token → qr_invalid_signature');

// ============================================================================
// Test 6: expired token (exp in the past; service checks expiry, not tokenizer)
// ============================================================================
$jti_exp  = Uuid::v4();
// Token issued and expired 90 min before $now (which was 10:00). 
// exp = $now - 5400 = 08:30. We then freeze clock at 12:00 so exp+30 < now.
$expiredToken = $tk->issue($roomId, $stayId, $jti_exp, $now - 7200, $now - 5400);
$credRepo->byJti[$jti_exp] = new QrCredential(7, $stayId, $roomId, $jti_exp, 't',
    '2026-04-28 08:00:00.000', '2026-04-28 08:30:00.000', null, null
);
Clock::freeze(new DateTimeImmutable('2026-04-28T12:00:00Z', new DateTimeZone('UTC')));
expect('qr_expired', function () use ($service, $expiredToken) {
    $service->validate($expiredToken, 'rpi-101', 'test-corr-6');
}, 'expired token → qr_expired');
Clock::freeze(new DateTimeImmutable('2026-04-28T10:00:00Z', new DateTimeZone('UTC')));

// ============================================================================
// Test 7: device_mismatch — device registered but for wrong room
// ============================================================================
putenv('SIMULATED_MODE=false'); // Force real mode so device check runs strictly
$devRepo->rooms = $roomRepo;
$roomRepo->byId[99] = new Room(99, '199', 1, 99, Room::STATUS_OCCUPIED, true, null); // Room 99 → pack 99
$devRepo->devices = [
    // Canonical (F30): the RPI belongs to pack 99 → Room 99, which is NOT the
    // token's room (1). resolveRoomId() resolves 99 → device_mismatch.
    new Device(1, 99, 'RPI', 'rpi-wrong-room', null, null, null),
];
$jti4  = Uuid::v4();
$tok4  = $tk->issue($roomId, $stayId, $jti4, $now, $exp);
$credRepo->byJti[$jti4] = new QrCredential(4, $stayId, $roomId, $jti4, 'w',
    '2026-04-28 09:55:00.000', '2026-04-28 10:25:00.000', null, null
);
expect('device_mismatch', function () use ($service, $tok4) {
    $service->validate($tok4, 'rpi-wrong-room', 'test-corr-7');
}, 'device registered to wrong room → device_mismatch');
putenv('SIMULATED_MODE=true'); // restore
unset($roomRepo->byId[99]);
$devRepo->devices = [];
$devRepo->rooms = null;

// ============================================================================
// Test 8: room_cooldown
// ============================================================================
$futureTs = (new DateTimeImmutable('2026-04-28T10:00:00Z'))->modify('+300 seconds')->format('Y-m-d H:i:s.v');
$roomRepo->byId[$roomId] = new Room($roomId, '101', 1, null, Room::STATUS_OCCUPIED, true, $futureTs);
$jti5  = Uuid::v4();
$tok5  = $tk->issue($roomId, $stayId, $jti5, $now, $exp);
$credRepo->byJti[$jti5] = new QrCredential(5, $stayId, $roomId, $jti5, 'v',
    '2026-04-28 09:55:00.000', '2026-04-28 10:25:00.000', null, null
);
expect('room_cooldown', function () use ($service, $tok5) {
    $service->validate($tok5, 'rpi-101', 'test-corr-8');
}, 'active cooldown → room_cooldown');
$roomRepo->byId[$roomId] = new Room($roomId, '101', 1, null, Room::STATUS_OCCUPIED, true, null); // restore

// ============================================================================
// Test 9: stay in invalid state (CLOSED)
// ============================================================================
$jti6  = Uuid::v4();
$tok6  = $tk->issue($roomId, $stayId, $jti6, $now, $exp);
$credRepo->byJti[$jti6] = new QrCredential(6, $stayId, $roomId, $jti6, 'u',
    '2026-04-28 09:55:00.000', '2026-04-28 10:25:00.000', null, null
);
$closedStay = new Stay($stayId, $roomId, Stay::STATUS_CLOSED, 60,
    '2026-04-28 09:55:00.000', null, null, null,
    null, null, null, null, null, null, null, null, null,
    '2026-04-28 09:55:00.000', '2026-04-28 09:55:00.000'
);
$stayRepo->byId[$stayId]     = $closedStay;
$stayRepo->activeByRoom[$roomId] = null;
expect('stay_wrong_state', function () use ($service, $tok6) {
    $service->validate($tok6, 'rpi-101', 'test-corr-9');
}, 'stay CLOSED → stay_wrong_state');

Clock::unfreeze();

echo "\nTotal: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
