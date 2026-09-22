<?php
declare(strict_types=1);

/**
 * Unit tests for QrIssueService (TSK-061).
 *
 * Run:
 *   php tests/Unit/QrIssueServiceTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Qr\QrCredential;
use App\Domain\Qr\QrCredentialRepositoryInterface;
use App\Domain\Qr\QrIssueService;
use App\Domain\Rooms\Room;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Rooms\RoomType;
use App\Domain\Rooms\RoomTypeRepositoryInterface;
use App\Domain\Stays\Stay;
use App\Domain\Stays\StayRepositoryInterface;
use App\Domain\TimeSlots\TimeSlot;
use App\Domain\TimeSlots\TimeSlotRepositoryInterface;
use App\Domain\TimeSlots\TimeSlotService;
use App\Support\Clock;
use App\Support\Errors\ApiException;
use App\Support\Qr\QrTokenizer;

// ---- Fakes -----------------------------------------------------------------

$rooms = new class implements RoomRepositoryInterface {
    /** @var array<int,Room> */ public array $byId = [];
    public function listFiltered(array $f, int $l = 50, int $o = 0): array { return array_values($this->byId); }
    public function findById(int $id): ?Room { return $this->byId[$id] ?? null; }
    public function findByCode(string $c): ?Room { foreach ($this->byId as $r) if ($r->code === $c) return $r; return null; }
    public function insert(string $c, int $rt, ?bool $s): int { return 0; }
    public function update(int $id, array $f, ?string $expectedStatus = null): int { return 0; }
    public function resetAfterPackRemoval(int $roomId): void {}
    public function findByPackId(int $packId): ?Room { return null; }
};

$roomTypes = new class implements RoomTypeRepositoryInterface {
    /** @var array<int,RoomType> */ public array $byId = [];
    public function listAll(): array { return array_values($this->byId); }
    public function findById(int $id): ?RoomType { return $this->byId[$id] ?? null; }
    public function findByCode(string $c): ?RoomType { foreach ($this->byId as $rt) if ($rt->code === $c) return $rt; return null; }
    public function insert(string $c, string $n, int $g, int $ex, int $re, int $qr): int { return 0; }
    public function update(int $id, array $fields, ?string $expectedStatus = null): int { return 0; }
};

$stayRepo = new class implements StayRepositoryInterface {
    /** @var array<int,Stay> */ public array $byId = [];
    public int $nextId = 100;
    public ?Stay $activeStay = null;

    public function insertReserved(int $roomId, int $dur, array $refs): int
    {
        $id = $this->nextId++;
        $now = '2026-04-24 10:00:00.000';
        $this->byId[$id] = new Stay(
            $id, $roomId, Stay::STATUS_RESERVED, $dur, $now, null, null, null,
            $refs['codalq'] ?? null, $refs['codtic'] ?? null,
            $refs['codcli'] ?? null, $refs['codart'] ?? null, $refs['codlot'] ?? null,
            $refs['codhab'] ?? null, $refs['temporada'] ?? null,
            $refs['empresa'] ?? null, $refs['departamento'] ?? null,
            $now, $now
        );
        return $id;
    }
    public function findById(int $id): ?Stay { return $this->byId[$id] ?? null; }
    public function findActiveForRoom(int $roomId): ?Stay
    {
        if ($this->activeStay !== null && $this->activeStay->roomId === $roomId) return $this->activeStay;
        foreach ($this->byId as $s) if ($s->roomId === $roomId && $s->isActive()) return $s;
        return null;
    }
    public function lockActiveForRoom(int $roomId): ?Stay { return $this->findActiveForRoom($roomId); }
    public function listFiltered(array $f, int $l = 50, int $o = 0): array { return array_values($this->byId); }
    public function update(int $id, array $f, ?string $expectedStatus = null): int { return 0; }
    public function findByPackId(int $packId): ?Room { return null; }
};

$timeSlotRepo = new class implements TimeSlotRepositoryInterface {
    /** @var list<TimeSlot> */ public array $slots = [];
    public function listForRoomType(int $rt): array { return $this->slots; }
    public function replaceAll(int $rt, array $slots): void { $this->slots = array_values($slots); }
};
$timeSlotService = new TimeSlotService($timeSlotRepo);

$creds = new class implements QrCredentialRepositoryInterface {
    /** @var array<string,QrCredential> */ public array $byJti = [];
    /** @var array<string,QrCredential> */ public array $byHash = [];
    public int $nextId = 1;
    public function insert(int $sId, int $rId, string $j, string $h, string $i, string $e): int
    {
        $id = $this->nextId++;
        $c = new QrCredential($id, $sId, $rId, $j, $h, $i, $e, null, null);
        $this->byJti[$j] = $c;
        $this->byHash[$h] = $c;
        return $id;
    }
    public function findByJti(string $j): ?QrCredential { return $this->byJti[$j] ?? null; }
    public function findByTokenHash(string $h): ?QrCredential { return $this->byHash[$h] ?? null; }
    public function markConsumed(string $j): bool
    {
        if (!isset($this->byJti[$j]) || $this->byJti[$j]->consumedAt !== null
            || $this->byJti[$j]->revokedAt !== null) return false;
        $this->byJti[$j]->consumedAt = '2026-04-24 10:01:00.000';
        return true;
    }
    public function markFirstUse(string $j, string $v): bool
    {
        if (!isset($this->byJti[$j]) || $this->byJti[$j]->consumedAt !== null
            || $this->byJti[$j]->revokedAt !== null) return false;
        $this->byJti[$j]->firstUsedAt = '2026-04-24 10:01:00.000';
        $this->byJti[$j]->consumedAt  = '2026-04-24 10:01:00.000';
        $this->byJti[$j]->validUntil  = $v;
        return true;
    }
    public function markRevoked(string $j): bool
    {
        if (!isset($this->byJti[$j]) || $this->byJti[$j]->revokedAt !== null) return false;
        $this->byJti[$j]->revokedAt = '2026-04-24 10:01:00.000';
        return true;
    }
};

$secret = str_repeat('S', 32);
$tokenizer = new QrTokenizer($secret);

// Pre-populate.
$rooms->byId[1] = new Room(1, '101', 1, null, Room::STATUS_FREE, null, null);
$roomTypes->byId[1] = new RoomType(1, 'STANDARD', 'Standard', 5, 15, 20, 30);
$timeSlotService->save(1, [new TimeSlot(0, 1440, TimeSlot::KIND_RENTABLE)]);

Clock::freeze(new DateTimeImmutable('2026-04-24T10:00:00Z', new DateTimeZone('UTC')));
putenv('APP_TZ=UTC');
putenv('QR_ARRIVAL_WINDOW_MINUTES=15');

$service = new QrIssueService($rooms, $roomTypes, $stayRepo, $timeSlotService, $creds, $tokenizer);

$passed = 0; $failed = 0;
function ok(string $l): void { global $passed; $passed++; echo "  PASS  {$l}\n"; }
function bad(string $l, string $w = ''): void { global $failed; $failed++; echo "  FAIL  {$l} {$w}\n"; }
function expect(string $code, callable $fn, string $label): void {
    try { $fn(); bad($label, '(no exception)'); }
    catch (ApiException $e) {
        if ($e->errorCode() === $code) ok($label);
        else bad($label, "(got code {$e->errorCode()} message={$e->getMessage()})");
    }
}

echo "QrIssueService\n";

$res = $service->issue(1, 60, ['codtic' => 19995, 'codart' => 2]);
ok('issue returned a result');
if (!empty($res['qr_text']) && !empty($res['jti']) && $res['room_id'] === 1) ok('issue returns token+jti+room_id');
else bad('issue returns token+jti+room_id');
if (substr_count($res['qr_text'], '.') === 2) ok('token has three segments');
else bad('token has three segments');

$payload = $tokenizer->parse($res['qr_text']);
// Fase 51 / Bug 4: exp = iat + (arrival 15 + duracion 60) min = 4500 s.
// The room type's qr_usage_window_minutes (30) is deprecated for guest QR.
if ($payload['exp'] - $payload['iat'] === 4500) ok('exp - iat == 4500 s (arrival 15 + duracion 60)');
else bad('exp - iat == 4500', "got " . ($payload['exp'] - $payload['iat']));
if ($payload['room_id'] === 1 && $payload['stay_id'] === $res['stay_id']) ok('payload room_id and stay_id match');
else bad('payload room_id and stay_id match');

$cred = $creds->findByJti($res['jti']);
if ($cred !== null && $cred->tokenHash === QrTokenizer::hashForStorage($res['qr_text'])) ok('credential persisted with correct token_hash');
else bad('credential persisted with correct token_hash');

// room_busy
$stayRepo->activeStay = $stayRepo->findById($res['stay_id']);
expect('room_busy', function () use ($service) { $service->issue(1, 60, []); }, 'room_busy when stay is active');
$stayRepo->activeStay = null;

// duration_out_of_range
expect('duration_out_of_range', function () use ($service) { $service->issue(1, 10, []); }, 'reject duration < 30');
expect('duration_out_of_range', function () use ($service) { $service->issue(1, 800, []); }, 'reject duration > 720');

// not_found
expect('not_found', function () use ($service) { $service->issue(999, 60, []); }, 'reject unknown room_id');

// slot_not_rentable: FREE all day
$timeSlotService->save(1, [new TimeSlot(0, 1440, TimeSlot::KIND_FREE)]);
expect('slot_not_rentable', function () use ($service) { $service->issue(1, 60, []); }, 'reject when slot is FREE');

// no slots configured
$timeSlotRepo->slots = [];
expect('slot_not_rentable', function () use ($service) { $service->issue(1, 60, []); }, 'reject when no slots configured');

// revoke flow: clear stays so the previous one isn't considered active.
$timeSlotService->save(1, [new TimeSlot(0, 1440, TimeSlot::KIND_RENTABLE)]);
$stayRepo->byId = [];
$res2 = $service->issue(1, 45, []);
$revoked = $service->revoke($res2['jti']);
if ($revoked->revokedAt !== null) ok('revoke marks revoked_at');
else bad('revoke marks revoked_at');
expect('qr_already_revoked', function () use ($service, $res2) { $service->revoke($res2['jti']); }, 'second revoke -> 409');
expect('not_found', function () use ($service) { $service->revoke('00000000-0000-4000-8000-000000000000'); }, 'revoke unknown jti -> 404');

Clock::unfreeze();

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
