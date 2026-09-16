<?php
declare(strict_types=1);

/**
 * Unit tests for the room_type presence verification windows (F44 tuning).
 *
 * Trazabilidad: RF-51.2 (ventana de entrada), RF-51.4/RF-51.6 (ventana
 * post-cierre configurable) · migración 0110.
 *
 * Run: php tests/Unit/RoomTypeWindowsTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Rooms\RoomType;
use App\Domain\Rooms\RoomTypeService;
use App\Domain\Rooms\RoomTypeRepositoryInterface;

$passed = 0;
$failed = 0;
function check(string $name, bool $cond): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  ✅ {$name}\n";
    } else {
        $failed++;
        echo "  ❌ {$name}\n";
    }
}

final class FakeRoomTypeRepoWin implements RoomTypeRepositoryInterface
{
    /** @var array<string,int|string> */
    public array $lastInsert = [];
    public ?RoomType $stored = null;

    public function listAll(): array { return []; }
    public function findById(int $id): ?RoomType { return $this->stored; }
    public function findByCode(string $code): ?RoomType { return null; }

    public function insert(
        string $code,
        string $name,
        int $graceMinutes,
        int $exitPresenceGapSeconds,
        int $reentryCooldownSeconds,
        int $qrUsageWindowMinutes,
        int $presenceEntryWindowSeconds = 90,
        int $exitCheckSeconds = 40
    ): int {
        $this->lastInsert = [
            'code' => $code, 'name' => $name,
            'grace' => $graceMinutes, 'gap' => $exitPresenceGapSeconds,
            'cool' => $reentryCooldownSeconds, 'qr' => $qrUsageWindowMinutes,
            'entry' => $presenceEntryWindowSeconds, 'exit' => $exitCheckSeconds,
        ];
        $this->stored = new RoomType(
            1, $code, $name, $graceMinutes, $exitPresenceGapSeconds,
            $reentryCooldownSeconds, $qrUsageWindowMinutes,
            $presenceEntryWindowSeconds, $exitCheckSeconds
        );
        return 1;
    }

    public function update(int $id, array $fields): int
    {
        if ($this->stored === null) {
            return 0;
        }
        if (array_key_exists('exit_check_seconds', $fields)) {
            $this->stored->exitCheckSeconds = (int) $fields['exit_check_seconds'];
        }
        if (array_key_exists('presence_entry_window_seconds', $fields)) {
            $this->stored->presenceEntryWindowSeconds = (int) $fields['presence_entry_window_seconds'];
        }
        return 1;
    }
}

echo "\n── room_type windows (F44) ──\n";

// 1. Model exposes the new fields (defaults >= 40 policy).
$rt = new RoomType(1, 'STANDARD', 'Std', 5, 15, 20, 30);
$arr = $rt->toArray();
check('RoomType defaults: entry_window=90', ($arr['presence_entry_window_seconds'] ?? null) === 90);
check('RoomType defaults: exit_check=40', ($arr['exit_check_seconds'] ?? null) === 40);

// 2. Service.create forwards explicit windows.
$repo = new FakeRoomTypeRepoWin();
$service = new RoomTypeService($repo);
$service->create('PROTO2', 'Proto2', 5, 15, 20, 60, 90, 40);
check('create forwards entry window 90', ($repo->lastInsert['entry'] ?? null) === 90);
check('create forwards exit check 40', ($repo->lastInsert['exit'] ?? null) === 40);

// 3. Service.create uses the policy defaults when omitted.
$repo2 = new FakeRoomTypeRepoWin();
$service2 = new RoomTypeService($repo2);
$service2->create('STD', 'Std', 5, 15, 20, 60);
check('create defaults entry window to 90', ($repo2->lastInsert['entry'] ?? null) === 90);
check('create defaults exit check to 40', ($repo2->lastInsert['exit'] ?? null) === 40);

// 4. Out-of-range windows are rejected.
$threwEntry = false;
try { (new RoomTypeService(new FakeRoomTypeRepoWin()))->create('X', 'X', 5, 15, 20, 60, 0, 40); }
catch (\Throwable $e) { $threwEntry = true; }
check('entry window out of range rejected', $threwEntry);

$threwExit = false;
try { (new RoomTypeService(new FakeRoomTypeRepoWin()))->create('X', 'X', 5, 15, 20, 60, 90, 9999); }
catch (\Throwable $e) { $threwExit = true; }
check('exit check out of range rejected', $threwExit);

// 5. Update accepts the new fields (validated) — fake getOrFail returns stored.
$repo3 = new FakeRoomTypeRepoWin();
$service3 = new RoomTypeService($repo3);
$service3->create('UPD', 'Upd', 5, 15, 20, 60, 90, 40);
$service3->update(1, ['exit_check_seconds' => 45]);
check('update accepts exit_check_seconds=45', ($repo3->stored->exitCheckSeconds ?? null) === 45);

echo "\n" . ($failed === 0 ? "✅" : "❌") . " room_type windows: {$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    exit(1);
}
exit(0);
