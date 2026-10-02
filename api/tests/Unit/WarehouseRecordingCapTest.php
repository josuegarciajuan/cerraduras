<?php
declare(strict_types=1);

/**
 * Unit tests for WarehouseRecordingService::enforceRecordingCap (F73, RF-85).
 *
 * Pure + tiny PDO double: no DB, no HTTP.
 *
 * Run:
 *   php tests/Unit/WarehouseRecordingCapTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Warehouse\WarehouseRecordingService;

final class FakeCapStatement extends PDOStatement
{
    /** @var list<array<string,mixed>|null> */
    public array $executed = [];
    public int $rows = 0;

    public function __construct() {}

    public function execute($params = null): bool
    {
        $this->executed[] = $params;
        return true;
    }

    public function rowCount(): int
    {
        return $this->rows;
    }
}

final class FakeCapPdo extends PDO
{
    /** @var list<string> */
    public array $prepared = [];
    public ?FakeCapStatement $last = null;
    public int $nextRows = 0;

    public function __construct() {}

    public function prepare($query, $options = null): PDOStatement|false
    {
        $this->prepared[] = (string) $query;
        $stmt = new FakeCapStatement();
        $stmt->rows = $this->nextRows;
        $this->last = $stmt;
        return $stmt;
    }
}

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }

echo "WarehouseRecordingCap Unit Tests\n";
echo str_repeat("=", 60) . "\n\n";

// ── T1: cap <= 0 no ejecuta SQL (sin límite) ──────────────────────────────
$pdo = new FakeCapPdo();
$service = new WarehouseRecordingService($pdo);
$n = $service->enforceRecordingCap(0);
if ($n === 0 && $pdo->prepared === []) {
    pass('T1: cap=0 es no-op (sin SQL)');
} else {
    fail('T1: cap=0 no-op — prepared=' . count($pdo->prepared) . ', ret=' . $n);
}

// ── T2: cap negativo tampoco ejecuta SQL ──────────────────────────────────
$pdo = new FakeCapPdo();
$service = new WarehouseRecordingService($pdo);
$n = $service->enforceRecordingCap(-5);
if ($n === 0 && $pdo->prepared === []) {
    pass('T2: cap<0 es no-op (sin SQL)');
} else {
    fail('T2: cap<0 no-op — prepared=' . count($pdo->prepared) . ', ret=' . $n);
}

// ── T3: cap > 0 ejecuta UPDATE con el tope como parámetro ─────────────────
$pdo = new FakeCapPdo();
$pdo->nextRows = 2;
$service = new WarehouseRecordingService($pdo);
$n = $service->enforceRecordingCap(60);
$sql = $pdo->prepared[0] ?? '';
$params = $pdo->last->executed[0] ?? null;
if ($n === 2
    && str_contains($sql, 'stop_requested=1')
    && str_contains($sql, "status='RECORDING'")
    && str_contains($sql, 'INTERVAL :s SECOND')
    && ($params[':s'] ?? null) === 60
) {
    pass('T3: cap=60 marca stop_requested con :s=60');
} else {
    fail('T3: cap=60 — ret=' . $n . ', params=' . json_encode($params) . ', sql=' . $sql);
}

// ── T4: cap > 0 sin filas devuelve 0 pero sí ejecuta ──────────────────────
$pdo = new FakeCapPdo();
$pdo->nextRows = 0;
$service = new WarehouseRecordingService($pdo);
$n = $service->enforceRecordingCap(60);
if ($n === 0 && count($pdo->prepared) === 1) {
    pass('T4: cap>0 sin grabaciones devuelve 0');
} else {
    fail('T4: cap>0 sin filas — ret=' . $n . ', prepared=' . count($pdo->prepared));
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Resultado: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
