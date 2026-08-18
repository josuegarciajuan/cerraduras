<?php
declare(strict_types=1);

/**
 * Unit tests for Worker entity, WorkerService (TSK-W20).
 *
 * Run:
 *   php tests/Unit/WorkerTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Workers\Worker;
use App\Domain\Workers\WorkerRepositoryInterface;
use App\Domain\Workers\WorkerRole;
use App\Domain\Workers\WorkerRoleRepositoryInterface;
use App\Domain\Workers\WorkerSession;
use App\Domain\Workers\WorkerSessionRepositoryInterface;
use App\Domain\Workers\WorkerService;
use App\Domain\Rooms\Room;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Support\Qr\QrTokenizer;

// ── Fakes ────────────────────────────────────────────────────────────────────

$workerRepo = new class implements WorkerRepositoryInterface {
    /** @var array<int,Worker> */ public array $byId = [];
    public int $nextId = 1;

    public function findById(int $id): ?Worker { return $this->byId[$id] ?? null; }
    public function findByJti(string $jti): ?Worker {
        foreach ($this->byId as $w) if ($w->qrJti === $jti) return $w;
        return null;
    }
    /** @param array{role_id?:int, active?:bool} $filters */
    public function findAll(array $filters = []): array {
        $result = array_values($this->byId);
        if (isset($filters['role_id'])) $result = array_filter($result, fn($w) => $w->roleId === $filters['role_id']);
        if (isset($filters['active'])) $result = array_filter($result, fn($w) => $w->active === $filters['active']);
        return array_values($result);
    }
    public function insert(Worker $worker): int {
        $id = $this->nextId++;
        $worker->id = $id;
        $this->byId[$id] = clone $worker;
        return $id;
    }
    public function update(int $id, array $fields): int {
        if (!isset($this->byId[$id])) return 0;
        $w = $this->byId[$id];
        if (isset($fields['name'])) $w->name = $fields['name'];
        if (isset($fields['role_id'])) $w->roleId = (int) $fields['role_id'];
        if (isset($fields['notes'])) $w->notes = $fields['notes'];
        if (isset($fields['qr_token_hash'])) $w->qrTokenHash = $fields['qr_token_hash'];
        if (isset($fields['qr_jti'])) $w->qrJti = $fields['qr_jti'];
        return 1;
    }
    public function softDelete(int $id): int {
        if (!isset($this->byId[$id])) return 0;
        $this->byId[$id]->deactivate();
        return 1;
    }
    public function countByRole(int $roleId): int {
        return count(array_filter($this->byId, fn($w) => $w->roleId === $roleId && $w->active));
    }
};

$roleRepo = new class implements WorkerRoleRepositoryInterface {
    /** @var array<int,WorkerRole> */ public array $byId = [];
    public int $nextId = 1;
    /** @var array<int, list<int>> */
    public array $roomTypes = [];

    public function findById(int $id): ?WorkerRole { return $this->byId[$id] ?? null; }
    public function findAll(): array { return array_values($this->byId); }
    public function insert(WorkerRole $role): int {
        $id = $this->nextId++;
        $role->id = $id;
        $this->byId[$id] = clone $role;
        return $id;
    }
    public function update(int $id, array $fields): int {
        if (!isset($this->byId[$id])) return 0;
        if (isset($fields['name'])) $this->byId[$id]->name = $fields['name'];
        if (isset($fields['description'])) $this->byId[$id]->description = $fields['description'];
        return 1;
    }
    public function delete(int $id): int {
        if (!isset($this->byId[$id])) return 0;
        unset($this->byId[$id]);
        return 1;
    }
    public function assignRoomTypes(int $roleId, array $roomTypeIds): void {
        $this->roomTypes[$roleId] = $roomTypeIds;
    }
    public function getRoomTypesForRole(int $roleId): array { return $this->roomTypes[$roleId] ?? []; }
    public function countWorkersForRole(int $roleId): int { return 0; }
    public function canAccessRoomType(int $roleId, int $roomTypeId): bool {
        return in_array($roomTypeId, $this->roomTypes[$roleId] ?? [], true);
    }
};

$sessionRepo = new class implements WorkerSessionRepositoryInterface {
    /** @var list<WorkerSession> */ public array $sessions = [];
    public int $nextId = 1;

    public function findById(int $id): ?WorkerSession {
        foreach ($this->sessions as $s) if ($s->id === $id) return $s;
        return null;
    }
    public function findActiveForRoom(int $roomId): array {
        return array_values(array_filter($this->sessions, fn($s) => $s->roomId === $roomId && $s->isActive()));
    }
    public function findActiveForWorker(int $workerId): array {
        return array_values(array_filter($this->sessions, fn($s) => $s->workerId === $workerId && $s->isActive()));
    }
    public function findForWorker(int $workerId, array $filters = []): array {
        $result = array_filter($this->sessions, fn($s) => $s->workerId === $workerId);
        if (!empty($filters['room_id'])) $result = array_filter($result, fn($s) => $s->roomId === (int)$filters['room_id']);
        $result = array_values($result);
        usort($result, fn($a, $b) => strcmp($b->enteredAt, $a->enteredAt));
        return array_slice($result, 0, $filters['limit'] ?? 50);
    }
    public function findForRoom(int $roomId, array $filters = []): array {
        return array_values(array_filter($this->sessions, fn($s) => $s->roomId === $roomId));
    }
    public function insert(WorkerSession $session): int {
        $id = $this->nextId++;
        $session->id = $id;
        $this->sessions[] = clone $session;
        return $id;
    }
    public function close(int $id, string $exitKind, string $exitedAt): int {
        foreach ($this->sessions as $s) {
            if ($s->id === $id && $s->isActive()) {
                $s->close($exitKind, $exitedAt);
                return 1;
            }
        }
        return 0;
    }
    public function countActiveForRoom(int $roomId): int {
        return count($this->findActiveForRoom($roomId));
    }
    public function findWorkersInside(): array { return []; }
};

$roomRepo = new class implements RoomRepositoryInterface {
    /** @var array<int,Room> */ public array $byId = [];
    public function listFiltered(array $f, int $l = 50, int $o = 0): array { return array_values($this->byId); }
    public function findById(int $id): ?Room { return $this->byId[$id] ?? null; }
    public function findByCode(string $c): ?Room { foreach ($this->byId as $r) if ($r->code === $c) return $r; return null; }
    public function insert(string $c, int $rt, ?bool $s): int { return 0; }
    public function update(int $id, array $f, ?string $expectedStatus = null): int { return 0; }
    public function resetAfterPackRemoval(int $roomId): void {}
    public function findByPackId(int $packId): ?Room { return null; }
};

// Pre-populate
$roleRepo->byId[1] = new WorkerRole(1, 'Limpieza', 'test', [1, 2], '', '');
$roleRepo->roomTypes[1] = [1, 2];
$roomRepo->byId[1] = new Room(1, '101', 1, null, 'FREE', null, null);
$roomRepo->byId[2] = new Room(2, '102', 2, null, 'FREE', null, null);

$secret = str_repeat('T', 32);
$tk = new QrTokenizer($secret);
$svc = new WorkerService($workerRepo, $roleRepo, $sessionRepo, $tk, $roomRepo);

$passed = 0; $failed = 0;
function ok(string $l): void { global $passed; $passed++; echo "  PASS  {$l}\n"; }
function bad(string $l, string $w = ''): void { global $failed; $failed++; echo "  FAIL  {$l} {$w}\n"; }

echo "WorkerTest\n";

// T1: Create worker with QR token
$result = $svc->create(['name' => 'Test Worker', 'role_id' => 1, 'notes' => 'Testing']);
if ($result['name'] === 'Test Worker' && $result['active'] === true && !empty($result['qr_token'])) {
    ok('T1: create returns worker with qr_token');
} else { bad('T1: create returns worker with qr_token'); }

$token = $result['qr_token'];
$wid = $result['id'];

// T2: QR token is valid JWT (round-trip via tokenizer)
$claims = $tk->parse($token);
if ($claims['sub'] === 'worker' && $claims['wid'] === $wid) {
    ok('T2: token parses as worker with correct wid');
} else { bad('T2: token parses as worker with correct wid'); }

// T3: List workers includes created one
$list = $svc->list([]);
if (count($list) === 1 && $list[0]['id'] === $wid) {
    ok('T3: list returns created worker');
} else { bad('T3: list returns created worker', 'got ' . count($list) . ' workers'); }

// T4: Show worker
$show = $svc->show($wid);
if ($show['name'] === 'Test Worker' && isset($show['role']['name'])) {
    ok('T4: show returns role info');
} else { bad('T4: show returns role info'); }

// T5: Update worker
$updated = $svc->update($wid, ['name' => 'Updated Name']);
if ($updated['name'] === 'Updated Name') {
    ok('T5: update changes name');
} else { bad('T5: update changes name', 'got ' . $updated['name']); }

// T6: Deactivate worker
$deact = $svc->deactivate($wid);
if ($deact['active'] === false && $deact['qr_revoked'] === true) {
    ok('T6: deactivate returns qr_revoked');
} else { bad('T6: deactivate returns qr_revoked'); }

// T7: Deactivated worker is inactive
$show2 = $svc->show($wid);
if ($show2['active'] === false) {
    ok('T7: deactivated worker shows active=false');
} else { bad('T7: deactivated worker shows active=false'); }

// T8: canAccessRoomType (role 1 has access to room_type 1)
if ($svc->canAccessRoomType(1, 1)) {
    ok('T8: canAccessRoomType returns true for allowed type');
} else { bad('T8: canAccessRoomType returns true for allowed type'); }

// T9: canAccessRoomType denied
if (!$svc->canAccessRoomType(1, 999)) {
    ok('T9: canAccessRoomType returns false for unknown type');
} else { bad('T9: canAccessRoomType returns false for unknown type'); }

// T10: Create another worker and regenerate QR
$result2 = $svc->create(['name' => 'Worker 2', 'role_id' => 1]);
$token1 = $result2['qr_token'];
$regen = $svc->regenerateQr($result2['id']);
if (!empty($regen['qr_token']) && $regen['qr_token'] !== $token1) {
    ok('T10: regenerateQr produces different token');
} else { bad('T10: regenerateQr produces different token'); }

// T11: getCurrentRoom returns null (no sessions)
if ($svc->getCurrentRoom($wid) === null) {
    ok('T11: getCurrentRoom null without sessions');
} else { bad('T11: getCurrentRoom null without sessions'); }

// T12: getSessions returns empty
$sessions = $svc->getSessions($wid);
if (empty($sessions)) {
    ok('T12: getSessions empty for new worker');
} else { bad('T12: getSessions empty for new worker'); }

// T13: getWorkersInside returns empty (no active sessions)
$inside = $svc->getWorkersInside();
if (empty($inside)) {
    ok('T13: getWorkersInside empty');
} else { bad('T13: getWorkersInside empty'); }

// T14: getStats returns structure
$stats = $svc->getStats($wid);
if (isset($stats['rooms_visited_today'], $stats['total_minutes_today'])) {
    ok('T14: getStats returns expected keys');
} else { bad('T14: getStats returns expected keys'); }

// T15: create fails with non-existent role
try {
    $svc->create(['name' => 'Bad', 'role_id' => 999]);
    bad('T15: create with bad role throws');
} catch (\Throwable $e) {
    ok('T15: create with bad role throws');
}

// T16: QrTokenizer hashForStorage is consistent
$hash = QrTokenizer::hashForStorage($token);
if (strlen($hash) === 64 && ctype_xdigit($hash)) {
    ok('T16: hashForStorage is 64-char hex');
} else { bad('T16: hashForStorage is 64-char hex'); }

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
