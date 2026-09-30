<?php
declare(strict_types=1);

/**
 * Unit tests for WarehouseAccessPolicy (F62, RF-69).
 *
 * Pure + tiny fakes: no DB, no HTTP.
 *
 * Run:
 *   php tests/Unit/WarehouseAccessPolicyTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Workers\WarehouseAccessPolicy;
use App\Domain\Workers\WorkerRoomOverrideRepositoryInterface;
use App\Domain\Workers\WorkerRole;
use App\Domain\Workers\WorkerRoleRepositoryInterface;

final class FakeOverrideRepo implements WorkerRoomOverrideRepositoryInterface
{
    /** @var array<string,string> "workerId:roomTypeId" => effect */
    public array $map = [];

    public function getEffect(int $workerId, int $roomTypeId): ?string
    {
        return $this->map[$workerId . ':' . $roomTypeId] ?? null;
    }
    public function setEffect(int $workerId, int $roomTypeId, string $effect): void
    {
        $this->map[$workerId . ':' . $roomTypeId] = $effect;
    }
    public function deleteEffect(int $workerId, int $roomTypeId): void
    {
        unset($this->map[$workerId . ':' . $roomTypeId]);
    }
    public function listForWorker(int $workerId): array
    {
        $out = [];
        foreach ($this->map as $k => $v) {
            [$w, $rt] = explode(':', $k);
            if ((int) $w === $workerId) { $out[(int) $rt] = $v; }
        }
        return $out;
    }
}

final class FakeRoleRepo implements WorkerRoleRepositoryInterface
{
    /** @var array<string,bool> "roleId:roomTypeId" => allowed */
    public array $map = [];

    public function findById(int $id): ?WorkerRole { return null; }
    public function findAll(): array { return []; }
    public function insert(WorkerRole $role): int { return 0; }
    public function update(int $id, array $fields): int { return 0; }
    public function delete(int $id): int { return 0; }
    public function assignRoomTypes(int $roleId, array $roomTypeIds): void {}
    public function getRoomTypesForRole(int $roleId): array { return []; }
    public function countWorkersForRole(int $roleId): int { return 0; }
    public function canAccessRoomType(int $roleId, int $roomTypeId): bool
    {
        return $this->map[$roleId . ':' . $roomTypeId] ?? false;
    }
}

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }

echo "WarehouseAccessPolicy Unit Tests\n";
echo str_repeat("=", 60) . "\n\n";

// ── resolve() matrix ─────────────────────────────────────────────────────
$matrix = [
    [null, true, true],
    [null, false, false],
    ['ALLOW', true, true],
    ['ALLOW', false, true],   // exception wins over role deny
    ['DENY', true, false],    // exception wins over role allow
    ['DENY', false, false],
];
foreach ($matrix as [$effect, $roleAllows, $expected]) {
    $got = WarehouseAccessPolicy::resolve($effect, $roleAllows);
    $label = var_export($effect, true) . '/' . var_export($roleAllows, true);
    if ($got === $expected) {
        pass("resolve({$label}) === " . var_export($expected, true));
    } else {
        fail("resolve({$label}) === " . var_export($got, true) . ", esperado " . var_export($expected, true));
    }
}

// ── canAccess() with fakes ───────────────────────────────────────────────
$overrides = new FakeOverrideRepo();
$roles = new FakeRoleRepo();
$policy = new WarehouseAccessPolicy($overrides, $roles);

$roles->map['1:7'] = true;    // role 1 can access room_type 7
$roles->map['2:7'] = false;   // role 2 cannot

// no override → role decides
if ($policy->canAccess(100, 1, 7) === true) {
    pass('canAccess(sin excepción, rol permite) === true');
} else {
    fail('canAccess(sin excepción, rol permite) !== true');
}
if ($policy->canAccess(101, 2, 7) === false) {
    pass('canAccess(sin excepción, rol deniega) === false');
} else {
    fail('canAccess(sin excepción, rol deniega) !== false');
}

// DENY override wins over role allow
$overrides->setEffect(100, 7, 'DENY');
if ($policy->canAccess(100, 1, 7) === false) {
    pass('canAccess(DENY sobre rol allow) === false');
} else {
    fail('canAccess(DENY sobre rol allow) !== false');
}

// ALLOW override wins over role deny
$overrides->setEffect(101, 7, 'ALLOW');
if ($policy->canAccess(101, 2, 7) === true) {
    pass('canAccess(ALLOW sobre rol deny) === true');
} else {
    fail('canAccess(ALLOW sobre rol deny) !== true');
}

// override for another room type does not affect this one
$overrides->setEffect(100, 99, 'DENY');
if ($policy->canAccess(100, 1, 7) === false) {
    // still DENY from the earlier 7 override; remove it and re-check
}
$overrides->deleteEffect(100, 7);
if ($policy->canAccess(100, 1, 7) === true) {
    pass('borrar excepción vuelve al rol (true)');
} else {
    fail('borrar excepción no vuelve al rol');
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
