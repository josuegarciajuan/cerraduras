<?php
declare(strict_types=1);

/**
 * Unit/Integration tests for RoomRepository::resetAfterPackRemoval (RF-21, TSK-PR5).
 *
 * Run:
 *   php tests/Unit/RoomPackResetTest.php
 *
 * This test validates the logic in isolation using a real PDO connection.
 * It requires the dev database to be running.
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Rooms\Room;
use App\Domain\Rooms\RoomRepository;

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

// ---- Setup PDO --------------------------------------------------------------
// Read .env for DB credentials
$envFile = __DIR__ . '/../../.env';
$env = [];
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
}

$dbHost = $env['DB_HOST'] ?? '127.0.0.1';
$dbPort = $env['DB_PORT'] ?? '3306';
$dbName = $env['DB_NAME'] ?? 'hotel_api';
$dbUser = $env['DB_USER'] ?? 'root';
$dbPass = $env['DB_PASS'] ?? '';
$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (\Throwable $e) {
    echo "  ⚠️  No se pudo conectar a la BD — tests saltados\n";
    echo "     {$e->getMessage()}\n";
    echo "  ℹ️  SKIP OK (DB no disponible)\n\n";
    exit(0);
}

$repo = new RoomRepository($pdo);

echo "RoomPackReset Unit Tests\n";
echo str_repeat("=", 60) . "\n\n";

// ── Cleanup: reset any test room we'll use ───────────────────────────────────
$pdo->exec("DELETE FROM qr_credentials WHERE room_id IN (SELECT id FROM rooms WHERE code LIKE 'TEST-PR-%')");
$pdo->exec("DELETE FROM stays WHERE room_id IN (SELECT id FROM rooms WHERE code LIKE 'TEST-PR-%')");
$pdo->exec("UPDATE rooms SET pack_id = NULL, status = 'FREE', cooldown_until = NULL WHERE code LIKE 'TEST-PR-%'");

// Ensure we have a test room
$stmt = $pdo->prepare("SELECT id, status FROM rooms WHERE code = 'TEST-PR-1' LIMIT 1");
$stmt->execute();
$testRoom = $stmt->fetch();
if (!$testRoom) {
    // Create a test room
    $pdo->prepare("INSERT INTO rooms (code, room_type_id, status) VALUES ('TEST-PR-1', 1, 'FREE')")->execute();
    $testRoomId = (int) $pdo->lastInsertId();
} else {
    $testRoomId = (int) $testRoom['id'];
    // Reset it
    $pdo->prepare("UPDATE rooms SET status = 'FREE', pack_id = NULL, cooldown_until = NULL WHERE id = :id")
        ->execute([':id' => $testRoomId]);
}

// ── Test 1: RESERVED → FREE ─────────────────────────────────────────────────
$pdo->prepare("UPDATE rooms SET status = 'RESERVED', cooldown_until = UTC_TIMESTAMP(3) WHERE id = :id")
    ->execute([':id' => $testRoomId]);
// Create a fake stay for this room
$pdo->prepare("INSERT INTO stays (room_id, status, duracion_minutos, reserved_at, vb6_codtic, vb6_codcli)
               VALUES (:rid, 'RESERVED', 60, UTC_TIMESTAMP(3), 1, 1)")
    ->execute([':rid' => $testRoomId]);

$repo->resetAfterPackRemoval($testRoomId);

$stmt = $pdo->prepare("SELECT status, cooldown_until FROM rooms WHERE id = :id");
$stmt->execute([':id' => $testRoomId]);
$room = $stmt->fetch();

if ($room['status'] === 'FREE' && $room['cooldown_until'] === null) {
    pass('RESERVED → FREE (status + cooldown cleared)');
} else {
    fail("RESERVED → FREE — expected FREE/null, got {$room['status']}/{$room['cooldown_until']}");
}

// Check stay was closed
$stmt = $pdo->prepare("SELECT status FROM stays WHERE room_id = :rid ORDER BY id DESC LIMIT 1");
$stmt->execute([':rid' => $testRoomId]);
$stayStatus = $stmt->fetchColumn();
if ($stayStatus === 'CLOSED') {
    pass('Stay closed after RESERVED reset');
} else {
    fail("Stay should be CLOSED, got {$stayStatus}");
}

// Cleanup
$pdo->prepare("UPDATE rooms SET status = 'FREE', pack_id = NULL, cooldown_until = NULL WHERE id = :id")
    ->execute([':id' => $testRoomId]);
$pdo->prepare("DELETE FROM stays WHERE room_id = :rid")->execute([':rid' => $testRoomId]);

// ── Test 2: OCCUPIED → FREE ─────────────────────────────────────────────────
$pdo->prepare("UPDATE rooms SET status = 'OCCUPIED', cooldown_until = UTC_TIMESTAMP(3) WHERE id = :id")
    ->execute([':id' => $testRoomId]);
// Create a fake stay for this room
$pdo->prepare("INSERT INTO stays (room_id, status, duracion_minutos, reserved_at, vb6_codtic, vb6_codcli)
               VALUES (:rid, 'OCCUPIED', 60, UTC_TIMESTAMP(3), 1, 1)")
    ->execute([':rid' => $testRoomId]);

$repo->resetAfterPackRemoval($testRoomId);

$stmt = $pdo->prepare("SELECT status, cooldown_until FROM rooms WHERE id = :id");
$stmt->execute([':id' => $testRoomId]);
$room = $stmt->fetch();

if ($room['status'] === 'FREE' && $room['cooldown_until'] === null) {
    pass('OCCUPIED → FREE (status + cooldown cleared)');
} else {
    fail("OCCUPIED → FREE — expected FREE/null, got {$room['status']}/{$room['cooldown_until']}");
}

// Cleanup
$pdo->prepare("UPDATE rooms SET status = 'FREE', pack_id = NULL, cooldown_until = NULL WHERE id = :id")
    ->execute([':id' => $testRoomId]);
$pdo->prepare("DELETE FROM stays WHERE room_id = :rid")->execute([':rid' => $testRoomId]);

// ── Test 3: FREE — no-op ────────────────────────────────────────────────────
$pdo->prepare("UPDATE rooms SET status = 'FREE' WHERE id = :id")
    ->execute([':id' => $testRoomId]);

$repo->resetAfterPackRemoval($testRoomId);

$stmt = $pdo->prepare("SELECT status FROM rooms WHERE id = :id");
$stmt->execute([':id' => $testRoomId]);
$status = $stmt->fetchColumn();

if ($status === 'FREE') {
    pass('FREE — remains FREE (no-op)');
} else {
    fail("FREE — expected FREE, got {$status}");
}

// ── Test 4: CLEANING — no-op ────────────────────────────────────────────────
$pdo->prepare("UPDATE rooms SET status = 'CLEANING' WHERE id = :id")
    ->execute([':id' => $testRoomId]);

$repo->resetAfterPackRemoval($testRoomId);

$stmt = $pdo->prepare("SELECT status FROM rooms WHERE id = :id");
$stmt->execute([':id' => $testRoomId]);
$status = $stmt->fetchColumn();

if ($status === 'CLEANING') {
    pass('CLEANING — remains CLEANING (no-op)');
} else {
    fail("CLEANING — expected CLEANING, got {$status}");
}

// ── Test 5: OUT_OF_SERVICE — no-op ──────────────────────────────────────────
$pdo->prepare("UPDATE rooms SET status = 'OUT_OF_SERVICE' WHERE id = :id")
    ->execute([':id' => $testRoomId]);

$repo->resetAfterPackRemoval($testRoomId);

$stmt = $pdo->prepare("SELECT status FROM rooms WHERE id = :id");
$stmt->execute([':id' => $testRoomId]);
$status = $stmt->fetchColumn();

if ($status === 'OUT_OF_SERVICE') {
    pass('OUT_OF_SERVICE — remains OUT_OF_SERVICE (no-op)');
} else {
    fail("OUT_OF_SERVICE — expected OUT_OF_SERVICE, got {$status}");
}

// ── Final cleanup ───────────────────────────────────────────────────────────
$pdo->prepare("UPDATE rooms SET status = 'FREE', pack_id = NULL, cooldown_until = NULL WHERE id = :id")
    ->execute([':id' => $testRoomId]);
$pdo->prepare("DELETE FROM stays WHERE room_id = :rid")->execute([':rid' => $testRoomId]);

// ── Result ───────────────────────────────────────────────────────────────────
echo "\n";
echo str_repeat("=", 60) . "\n";
echo "  Results: {$passed} passed, {$failed} failed\n";
echo str_repeat("=", 60) . "\n";

exit($failed > 0 ? 1 : 0);
