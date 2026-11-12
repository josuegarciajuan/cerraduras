<?php
declare(strict_types=1);

/**
 * Unit tests for AccessMovementCollapser (F129).
 * Run: php tests/Unit/AccessMovementCollapserTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Analytics\AccessMovementCollapser as C;

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }

echo "AccessMovementCollapser Unit Tests\n";
echo str_repeat("=", 60) . "\n\n";

// ── 1) NFC + QR_VALIDATE + OPEN con el mismo correlation_id → 1 fila ──
$rows = [
    [
        'id' => 3, 'occurred_at' => '2026-11-12 10:00:02', 'source' => 'ACCESS',
        'type' => 'OPEN', 'result' => 'OK', 'reason' => null,
        'correlation_id' => 'c1', 'room_id' => 7, 'room_code' => '101',
        'worker_id' => null, 'worker_name' => null, 'key_label' => null,
    ],
    [
        'id' => 10, 'occurred_at' => '2026-11-12 10:00:00', 'source' => 'KEY',
        'type' => 'NFC', 'result' => 'OK', 'reason' => null,
        'correlation_id' => 'c1', 'room_id' => 7, 'room_code' => '101',
        'worker_id' => 5, 'worker_name' => 'Ana', 'key_label' => 'Ficha A',
    ],
    [
        'id' => 2, 'occurred_at' => '2026-11-12 10:00:01', 'source' => 'ACCESS',
        'type' => 'QR_VALIDATE', 'result' => 'OK', 'reason' => null,
        'correlation_id' => 'c1', 'room_id' => 7, 'room_code' => '101',
        'worker_id' => null, 'worker_name' => null, 'key_label' => null,
    ],
];
$out = C::collapse($rows);
count($out) === 1 ? pass('NFC+QR_VALIDATE+OPEN → 1 fila') : fail('NFC+QR_VALIDATE+OPEN → 1 fila');
($out[0]['type'] ?? null) === 'NFC' ? pass('tipo KEY manda (NFC)') : fail('tipo KEY manda (NFC)');
($out[0]['key_label'] ?? null) === 'Ficha A' ? pass('key_label de la fila KEY') : fail('key_label de la fila KEY');
($out[0]['worker_name'] ?? null) === 'Ana' ? pass('worker_name de la fila KEY') : fail('worker_name de la fila KEY');
($out[0]['result'] ?? null) === 'OK' ? pass('result OK') : fail('result OK');
($out[0]['occurred_at'] ?? null) === '2026-11-12 10:00:00' ? pass('occurred_at más temprana') : fail('occurred_at más temprana');
($out[0]['source'] ?? null) === 'KEY' ? pass('source KEY') : fail('source KEY');

// ── 2) QR de huésped (QR_VALIDATE + OPEN) → 1 fila QR_VALIDATE OK ──
$rows = [
    [
        'id' => 21, 'occurred_at' => '2026-11-12 11:00:01', 'source' => 'ACCESS',
        'type' => 'OPEN', 'result' => 'OK', 'correlation_id' => 'c2',
        'room_id' => 8, 'room_code' => '102',
    ],
    [
        'id' => 20, 'occurred_at' => '2026-11-12 11:00:00', 'source' => 'ACCESS',
        'type' => 'QR_VALIDATE', 'result' => 'OK', 'correlation_id' => 'c2',
        'room_id' => 8, 'room_code' => '102',
    ],
];
$out = C::collapse($rows);
count($out) === 1 ? pass('QR_VALIDATE+OPEN → 1 fila') : fail('QR_VALIDATE+OPEN → 1 fila');
($out[0]['type'] ?? null) === 'QR_VALIDATE' ? pass('tipo QR_VALIDATE') : fail('tipo QR_VALIDATE');
($out[0]['result'] ?? null) === 'OK' ? pass('QR huésped OK') : fail('QR huésped OK');
($out[0]['source'] ?? null) === 'ACCESS' ? pass('source ACCESS') : fail('source ACCESS');

// ── 3) Ficha denegada (KEY NFC FAIL + ACCESS DENIED FAIL) → 1 fila FAIL ──
$rows = [
    [
        'id' => 30, 'occurred_at' => '2026-11-12 12:00:00', 'source' => 'KEY',
        'type' => 'NFC', 'result' => 'FAIL', 'reason' => 'ficha_no_autorizada',
        'correlation_id' => 'c3', 'room_id' => 9, 'room_code' => '103',
        'worker_id' => 6, 'worker_name' => 'Luis', 'key_label' => 'Ficha B',
    ],
    [
        'id' => 31, 'occurred_at' => '2026-11-12 12:00:00', 'source' => 'ACCESS',
        'type' => 'DENIED', 'result' => 'FAIL', 'reason' => 'access_denied',
        'correlation_id' => 'c3', 'room_id' => 9, 'room_code' => '103',
    ],
];
$out = C::collapse($rows);
count($out) === 1 ? pass('NFC FAIL+DENIED FAIL → 1 fila') : fail('NFC FAIL+DENIED FAIL → 1 fila');
($out[0]['result'] ?? null) === 'FAIL' ? pass('result FAIL') : fail('result FAIL');
($out[0]['reason'] ?? null) === 'ficha_no_autorizada' ? pass('reason del primer FAIL') : fail('reason del primer FAIL');

// ── 4) OPEN aislado → 1 fila ──
$rows = [[
    'id' => 40, 'occurred_at' => '2026-11-12 13:00:00', 'source' => 'ACCESS',
    'type' => 'OPEN', 'result' => 'OK', 'correlation_id' => 'c4',
]];
$out = C::collapse($rows);
count($out) === 1 ? pass('OPEN aislado → 1 fila') : fail('OPEN aislado → 1 fila');
($out[0]['type'] ?? null) === 'OPEN' ? pass('OPEN aislado conserva tipo') : fail('OPEN aislado conserva tipo');

// ── 5) AUTO_LOCK no se mezcla con OPEN del mismo correlation_id ──
$rows = [
    [
        'id' => 50, 'occurred_at' => '2026-11-12 14:00:00', 'source' => 'ACCESS',
        'type' => 'AUTO_LOCK', 'result' => 'OK', 'correlation_id' => 'c5',
    ],
    [
        'id' => 51, 'occurred_at' => '2026-11-12 14:00:01', 'source' => 'ACCESS',
        'type' => 'OPEN', 'result' => 'OK', 'correlation_id' => 'c5',
    ],
];
$out = C::collapse($rows);
count($out) === 2 ? pass('AUTO_LOCK no se mezcla → 2 filas') : fail('AUTO_LOCK no se mezcla → 2 filas');
$types = array_column($out, 'type');
sort($types);
$types === ['AUTO_LOCK', 'OPEN'] ? pass('conserva AUTO_LOCK y OPEN') : fail('conserva AUTO_LOCK y OPEN');

// ── 6) correlation_id nulo en dos filas → no se mezclan ──
$rows = [
    [
        'id' => 60, 'occurred_at' => '2026-11-12 15:00:00', 'source' => 'ACCESS',
        'type' => 'OPEN', 'result' => 'OK', 'correlation_id' => null,
    ],
    [
        'id' => 61, 'occurred_at' => '2026-11-12 15:00:01', 'source' => 'ACCESS',
        'type' => 'OPEN', 'result' => 'OK', 'correlation_id' => '',
    ],
];
$out = C::collapse($rows);
count($out) === 2 ? pass('correlation nulo/vacío no mezcla → 2 filas') : fail('correlation nulo/vacío no mezcla → 2 filas');

// ── 7) Orden DESC por occurred_at ──
$rows = [
    [
        'id' => 70, 'occurred_at' => '2026-11-12 16:00:00', 'source' => 'ACCESS',
        'type' => 'OPEN', 'result' => 'OK', 'correlation_id' => 'c7a',
    ],
    [
        'id' => 71, 'occurred_at' => '2026-11-12 18:00:00', 'source' => 'ACCESS',
        'type' => 'OPEN', 'result' => 'OK', 'correlation_id' => 'c7b',
    ],
    [
        'id' => 72, 'occurred_at' => '2026-11-12 17:00:00', 'source' => 'ACCESS',
        'type' => 'OPEN', 'result' => 'OK', 'correlation_id' => 'c7c',
    ],
];
$out = C::collapse($rows);
$order = array_column($out, 'occurred_at');
$order === ['2026-11-12 18:00:00', '2026-11-12 17:00:00', '2026-11-12 16:00:00']
    ? pass('orden DESC por occurred_at')
    : fail('orden DESC por occurred_at');

// ── 8) Tolerancia: array vacío y claves ausentes ──
C::collapse([]) === [] ? pass('array vacío → []') : fail('array vacío → []');
$out = C::collapse([['id' => 80], ['credential_type' => 'NFC', 'correlation_id' => 'c8', 'id' => 81]]);
count($out) === 2 ? pass('claves ausentes toleradas') : fail('claves ausentes toleradas');

echo "\n" . ($failed === 0 ? '✅' : '❌') . " AccessMovementCollapser: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
