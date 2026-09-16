<?php
declare(strict_types=1);

/**
 * Unit test: QrTestController (RF-20)
 *
 * Tests the QR test panel logic without database.
 * Validates response structures and error handling.
 */

// ── Bootstrap ──
require_once __DIR__ . '/../../src/Support/Autoload.php';

use App\Http\Controllers\QrTestController;
use App\Http\Request;
use App\Http\Response;

$passed = 0;
$failed = 0;

function pass(string $label): void {
    global $passed;
    echo "  PASS  $label\n";
    $passed++;
}

function fail(string $label, string $msg = ''): void {
    global $failed;
    echo "  FAIL  $label";
    if ($msg) echo " — $msg";
    echo "\n";
    $failed++;
}

// ── Setup: SQLite in-memory DB ──
try {
    $pdo = new PDO('sqlite::memory:');
} catch (\PDOException $e) {
    echo "SKIP: SQLite not available ({$e->getMessage()})\n";
    echo "Results: 0 passed, 0 failed\n";
    exit(0);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create minimal schema for testing
$pdo->exec("CREATE TABLE rooms (id INTEGER PRIMARY KEY, code TEXT, status TEXT DEFAULT 'FREE', cooldown_until TEXT, pack_id INTEGER, simulated_override TEXT)");
$pdo->exec("CREATE TABLE stays (id INTEGER PRIMARY KEY AUTOINCREMENT, room_id INTEGER, status TEXT, duracion_minutos INTEGER, reserved_at TEXT, first_entry_at TEXT, exited_at TEXT, closed_at TEXT, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE qr_credentials (id INTEGER PRIMARY KEY AUTOINCREMENT, stay_id INTEGER, room_id INTEGER, jti TEXT UNIQUE, token_hash TEXT, issued_at TEXT, expires_at TEXT, consumed_at TEXT, revoked_at TEXT, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE iot_sessions (room_id INTEGER PRIMARY KEY, door_state TEXT, presence_state TEXT, last_open_at TEXT, last_absent_since TEXT, exit_evaluated_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE debts (id INTEGER PRIMARY KEY, room_id INTEGER, amount REAL, created_at TEXT)");
$pdo->exec("CREATE TABLE outbox (id INTEGER PRIMARY KEY, room_id INTEGER, payload TEXT, created_at TEXT)");

// Insert test room
$pdo->exec("INSERT INTO rooms (id, code, status) VALUES (1, '101', 'FREE')");

// ── Helper: create a fake request ──
function makeRequest(string $method, array $body = [], array $query = []): Request {
    $req = new class($method, $body, $query) extends Request {
        private string $m;
        private array $b;
        private array $q;
        public function __construct(string $m, array $b, array $q) {
            $this->m = $m;
            $this->b = $b;
            $this->q = $q;
        }
        public function getMethod(): string { return $this->m; }
        public function getPath(): string { return ''; }
        public function routeParam(string $name): ?string { return null; }
        public function getHeader(string $name): ?string { return null; }
    };
    // Hack: set jsonBody via reflection or property
    $ref = new ReflectionClass($req);
    if ($ref->hasProperty('jsonBody')) {
        $prop = $ref->getProperty('jsonBody');
        $prop->setAccessible(true);
        $prop->setValue($req, $body);
    }
    if ($ref->hasProperty('query')) {
        $prop = $ref->getProperty('query');
        $prop->setAccessible(true);
        $prop->setValue($req, $query);
    }
    return $req;
}

// Controller without QrTokenizer (will fail on QR generation)
$ctrl = new QrTestController($pdo, null);

echo "--- QrTestController Unit Tests ---\n";

// ── Test 1: create with missing room_id → 400 ──
try {
    $res = $ctrl->create(makeRequest('POST', []));
    if ($res->getStatusCode() === 400) {
        pass('create() missing room_id → 400');
    } else {
        fail('create() missing room_id', "expected 400, got {$res->getStatusCode()}");
    }
} catch (\Throwable $e) {
    fail('create() missing room_id', $e->getMessage());
}

// ── Test 2: create with invalid duration → 422 ──
try {
    $res = $ctrl->create(makeRequest('POST', ['room_id' => 1, 'duracion_minutos' => 10]));
    if ($res->getStatusCode() === 422) {
        pass('create() duration=10 → 422');
    } else {
        fail('create() duration=10', "expected 422, got {$res->getStatusCode()}");
    }
} catch (\Throwable $e) {
    fail('create() duration=10', $e->getMessage());
}

// ── Test 3: create with non-existent room → 404 ──
try {
    $res = $ctrl->create(makeRequest('POST', ['room_id' => 999, 'duracion_minutos' => 60]));
    if ($res->getStatusCode() === 404) {
        pass('create() bad room_id → 404');
    } else {
        fail('create() bad room_id', "expected 404, got {$res->getStatusCode()}");
    }
} catch (\Throwable $e) {
    fail('create() bad room_id', $e->getMessage());
}

// ── Test 4: create without tokenizer → 500 ──
try {
    $res = $ctrl->create(makeRequest('POST', ['room_id' => 1, 'duracion_minutos' => 60]));
    if ($res->getStatusCode() === 500) {
        pass('create() no tokenizer → 500');
    } else {
        fail('create() no tokenizer', "expected 500, got {$res->getStatusCode()}");
    }
} catch (\Throwable $e) {
    fail('create() no tokenizer', $e->getMessage());
}

// ── Test 5: roomsReset with missing room_id → 400 ──
try {
    $res = $ctrl->roomsReset(makeRequest('POST', []));
    if ($res->getStatusCode() === 400) {
        pass('roomsReset() missing room_id → 400');
    } else {
        fail('roomsReset() missing room_id', "expected 400, got {$res->getStatusCode()}");
    }
} catch (\Throwable $e) {
    fail('roomsReset() missing room_id', $e->getMessage());
}

// ── Test 6: roomsReset with non-existent room → 404 ──
try {
    $res = $ctrl->roomsReset(makeRequest('POST', ['room_id' => 999]));
    if ($res->getStatusCode() === 404) {
        pass('roomsReset() bad room_id → 404');
    } else {
        fail('roomsReset() bad room_id', "expected 404, got {$res->getStatusCode()}");
    }
} catch (\Throwable $e) {
    fail('roomsReset() bad room_id', $e->getMessage());
}

// ── Test 7: reset with missing room_id → 400 ──
try {
    $res = $ctrl->reset(makeRequest('POST', []));
    if ($res->getStatusCode() === 400) {
        pass('reset() missing room_id → 400');
    } else {
        fail('reset() missing room_id', "expected 400, got {$res->getStatusCode()}");
    }
} catch (\Throwable $e) {
    fail('reset() missing room_id', $e->getMessage());
}

// ── Test 8: roomsReset success → 200 (no tokenizer needed for reset) ──
try {
    $res = $ctrl->roomsReset(makeRequest('POST', ['room_id' => 1]));
    if ($res->getStatusCode() === 200) {
        $body = json_decode($res->getBody(), true);
        if (($body['ok'] ?? false) === true && ($body['status'] ?? '') === 'FREE') {
            pass('roomsReset() → 200 ok, status=FREE');
        } else {
            fail('roomsReset() response', 'expected ok=true, status=FREE');
        }
    } else {
        fail('roomsReset()', "expected 200, got {$res->getStatusCode()}");
    }
} catch (\Throwable $e) {
    fail('roomsReset()', $e->getMessage());
}

// ── Test 9: roomsReset cleans up stays ──
// Insert a dummy stay and QR, then reset
$pdo->exec("INSERT INTO stays (room_id, status, duracion_minutos, reserved_at, created_at, updated_at) VALUES (1, 'RESERVED', 60, datetime('now'), datetime('now'), datetime('now'))");
$stayId = $pdo->lastInsertId();
$pdo->exec("INSERT INTO qr_credentials (stay_id, room_id, jti, token_hash, issued_at, expires_at, created_at, updated_at) VALUES ($stayId, 1, 'test-jti-123', 'dummy', datetime('now'), datetime('now','+1 hour'), datetime('now'), datetime('now'))");
$pdo->exec("INSERT INTO iot_sessions (room_id, door_state, presence_state, updated_at) VALUES (1, 'OPEN', 'PRESENT', datetime('now'))");
$pdo->exec("INSERT INTO debts (room_id, amount, created_at) VALUES (1, 50.0, datetime('now'))");
$pdo->exec("INSERT INTO outbox (room_id, payload, created_at) VALUES (1, '{}', datetime('now'))");

$res = $ctrl->roomsReset(makeRequest('POST', ['room_id' => 1]));
if ($res->getStatusCode() === 200) {
    // Verify stays closed
    $stayStatus = $pdo->query("SELECT status FROM stays WHERE id = $stayId")->fetchColumn();
    if ($stayStatus === 'CLOSED') {
        pass('roomsReset() closes stays');
    } else {
        fail('roomsReset() closes stays', "expected CLOSED, got $stayStatus");
    }

    // Verify QR revoked
    $qrRevoked = $pdo->query("SELECT revoked_at FROM qr_credentials WHERE jti='test-jti-123'")->fetchColumn();
    if ($qrRevoked !== null && $qrRevoked !== false) {
        pass('roomsReset() revokes QRs');
    } else {
        fail('roomsReset() revokes QRs', 'revoked_at is null');
    }

    // Verify IoT reset (honest: UNKNOWN, no inventa CLOSED/ABSENT)
    $iotDoor = $pdo->query("SELECT door_state FROM iot_sessions WHERE room_id=1")->fetchColumn();
    if ($iotDoor === 'UNKNOWN') {
        pass('roomsReset() resets IoT session to UNKNOWN');
    } else {
        fail('roomsReset() resets IoT session to UNKNOWN', "expected UNKNOWN, got $iotDoor");
    }

    // Verify debts cleaned
    $debtCount = $pdo->query("SELECT COUNT(*) FROM debts WHERE room_id=1")->fetchColumn();
    if ((int)$debtCount === 0) {
        pass('roomsReset() cleans debts');
    } else {
        fail('roomsReset() cleans debts', "expected 0, got $debtCount");
    }

    // Verify outbox cleaned
    $outboxCount = $pdo->query("SELECT COUNT(*) FROM outbox WHERE room_id=1")->fetchColumn();
    if ((int)$outboxCount === 0) {
        pass('roomsReset() cleans outbox');
    } else {
        fail('roomsReset() cleans outbox', "expected 0, got $outboxCount");
    }

    // Verify room status is FREE
    $roomStatus = $pdo->query("SELECT status FROM rooms WHERE id=1")->fetchColumn();
    if ($roomStatus === 'FREE') {
        pass('roomsReset() sets room to FREE');
    } else {
        fail('roomsReset() sets room to FREE', "expected FREE, got $roomStatus");
    }
} else {
    fail('roomsReset() cleanup', "expected 200, got {$res->getStatusCode()}");
}

// ── Summary ──
echo "\n";
echo "Results: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
