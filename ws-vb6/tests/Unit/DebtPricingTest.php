<?php
declare(strict_types=1);

/**
 * Unit tests for DebtPricing (F12, TSK-131).
 *
 * Tests:
 *   A_FAIR algorithm cases from design.md §18.4:
 *     exceso=1  → importe=20  (0h + 1m)
 *     exceso=30 → importe=20  (0h + 1m)
 *     exceso=31 → importe=20  (A_FAIR: 0h + 1m)
 *     exceso=60 → importe=40  (1h + 0m)
 *     exceso=61 → importe=60  (1h + 1m)
 *     exceso=90 → importe=60  (1h + 1m)
 *     exceso=91 → importe=60  (A_FAIR: 1h + 1m)
 *     exceso=120 → importe=80 (2h + 0m)
 *
 *   B_LITERAL algorithm:
 *     exceso=31 → importe=40  (1h + 0m)
 *     exceso=91 → importe=80  (2h + 0m)
 *
 * Run: php tests/Unit/DebtPricingTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use Ws\Pricing\DebtPricing;
use Ws\Support\Config;

// ============================================================================
// Test helpers
// ============================================================================
$pass = 0;
$fail = 0;

function ok(string $label): void
{
    global $pass;
    $pass++;
    echo "  PASS  $label\n";
}

function bad(string $label, string $detail = ''): void
{
    global $fail;
    $fail++;
    echo "  FAIL  $label\n";
    if ($detail !== '') {
        echo "        $detail\n";
    }
}

function assertEquals(mixed $expected, mixed $actual, string $label): void
{
    if ($expected === $actual) {
        ok($label);
    } else {
        bad($label, "Expected: " . var_export($expected, true) . " Got: " . var_export($actual, true));
    }
}

// ============================================================================
// Setup: connect to bs2026_mock which has articulos with test data
// ============================================================================

Config::load(__DIR__ . '/../../.env');

try {
    $pdoReal = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=bs2026_mock;charset=utf8mb4',
        'ws_vb6_vb6',
        'vb6_dev',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (\Throwable $e) {
    echo "  SKIP  DebtPricingTest — BD bs2026_mock not accessible: " . $e->getMessage() . "\n";
    exit(0);
}

// Ensure test data exists
$pdoReal->exec("
    INSERT INTO articulos (codart, descripcion, tiempo, precio) VALUES
        (1, 'Alquiler 30 minutos', 30, 20),
        (2, 'Alquiler 1 hora', 60, 40)
    ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion), tiempo = VALUES(tiempo), precio = VALUES(precio)
");

$pricing = new DebtPricing($pdoReal);

// ============================================================================
// A_FAIR tests
// ============================================================================
echo "\n--- A_FAIR algorithm ---\n";

$cases = [
    [1,   20, '0h+1m'],
    [30,  20, '0h+1m'],
    [31,  20, 'A_FAIR: 0h+1m (not 1h)'],
    [60,  40, '1h+0m'],
    [61,  60, '1h+1m'],
    [90,  60, '1h+1m'],
    [91,  60, 'A_FAIR: 1h+1m (not 2h)'],
    [120, 80, '2h+0m'],
];

foreach ($cases as [$exceso, $expected, $desc]) {
    $result = $pricing->calcularImporteDeuda($exceso, null, null, 'A_FAIR');
    assertEquals($expected, $result['importe'], "exceso={$exceso} ({$desc}) → importe={$expected}");
}

// ============================================================================
// B_LITERAL tests
// ============================================================================
echo "\n--- B_LITERAL algorithm ---\n";

$casesB = [
    [1,   20, '1→media'],
    [30,  20, '30→media'],
    [31,  40, 'B_LITERAL: 31→1h'],
    [60,  40, '60→1h'],
    [61,  80, 'B_LITERAL: 61→2h'],
    [90,  80, 'B_LITERAL: 90→2h (ceil(90/60)=2)'],
    [91,  80, 'B_LITERAL: 91→2h (ceil(91/60)=2)'],
    [120, 80, 'B_LITERAL: 120→2h'],
];

foreach ($casesB as [$exceso, $expected, $desc]) {
    $result = $pricing->calcularImporteDeuda($exceso, null, null, 'B_LITERAL');
    assertEquals($expected, $result['importe'], "B_LITERAL exceso={$exceso} ({$desc}) → importe={$expected}");
}

// ============================================================================
// Overflow cap test
// ============================================================================
echo "\n--- Overflow cap ---\n";

// Simulate a very large exceso (e.g. 100000 minutes) — should cap at 32767
$result = $pricing->calcularImporteDeuda(100000, null, null, 'A_FAIR');
assertEquals(32767, $result['importe'], 'overflow cap at 32767');
assertEquals(true, $result['detalle']['amount_overflow'], 'amount_overflow=true when capped');

// ============================================================================
// detalle structure
// ============================================================================
echo "\n--- detalle structure ---\n";

$result = $pricing->calcularImporteDeuda(61, null, null, 'A_FAIR');
assertEquals(1, $result['detalle']['n_horas'],      'n_horas=1 for exceso=61 A_FAIR');
assertEquals(1, $result['detalle']['n_medias'],     'n_medias=1 for exceso=61 A_FAIR');
assertEquals(2, $result['detalle']['codart_hora'],  'codart_hora=2 (from DB)');
assertEquals(1, $result['detalle']['codart_media'], 'codart_media=1 (from DB)');
assertEquals(40, $result['detalle']['precio_hora'], 'precio_hora=40');
assertEquals(20, $result['detalle']['precio_media'],'precio_media=20');

// ============================================================================
// Summary
// ============================================================================
echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
