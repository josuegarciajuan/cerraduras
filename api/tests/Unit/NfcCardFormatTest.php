<?php
declare(strict_types=1);

/**
 * Unit tests for F131 — NFC card creation with printed decimal codes.
 *
 * Uses an in-memory SQLite database so the real repository SQL is exercised
 * without touching MariaDB. No server or network required.
 *
 * Run: php tests/Unit/NfcCardFormatTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\AccessKeys\NfcCardRepository;
use App\Domain\AccessKeys\NfcCardService;
use App\Support\Errors\ConflictException;

$pass = 0;
$fail = 0;

function ok(string $label): void
{
    global $pass;
    $pass++;
    echo "  PASS  {$label}\n";
}

function bad(string $label, string $detail = ''): void
{
    global $fail;
    $fail++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function check(bool $condition, string $label, string $detail = ''): void
{
    $condition ? ok($label) : bad($label, $detail);
}

echo "NfcCardFormat Unit Tests (F131)\n";
echo str_repeat('=', 60) . "\n\n";

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIP  pdo_sqlite no disponible; el alta real se cubre en BLOCK 69\n";
    echo "\nTotal: 0 passed, 0 failed\n";
    exit(0);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE access_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        label TEXT
    )'
);
$pdo->exec(
    'CREATE TABLE nfc_cards (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        number TEXT NOT NULL UNIQUE,
        code_hash TEXT NOT NULL UNIQUE,
        code_last4 TEXT NULL,
        status TEXT NOT NULL DEFAULT "AVAILABLE",
        current_key_id INTEGER NULL,
        notes TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )'
);

$service = new NfcCardService(new NfcCardRepository($pdo));

// ── Alta decimal impresa ────────────────────────────────────────────────
$card = $service->create([
    'number' => '0015705531',
    'code'   => '0015705531',
    'notes'  => 'Lote F131',
]);
check($card['number'] === '0015705531', 'A1: number visible es el decimal impreso');
check($card['status'] === 'AVAILABLE', 'A2: la ficha nace disponible');
check($card['code_last4'] === '5531', 'A3: code_last4 usa el decimal canónico');
check($card['code_masked'] === '••••5531', 'A4: code_masked no revela el código');

$storedHash = $pdo->query('SELECT code_hash FROM nfc_cards WHERE id=' . (int) $card['id'])->fetchColumn();
check($storedHash === hash('sha256', '0015705531'), 'A5: se hashea el decimal canónico');
check($storedHash !== hash('sha256', '00EFA5BB'), 'A6: no se guarda el hash hexadecimal');

// ── El mismo UID en hexadecimal no duplica la ficha ─────────────────────
$duplicate = false;
try {
    $service->create(['number' => 'F131-DUP', 'code' => '00EFA5BB']);
} catch (ConflictException $e) {
    $duplicate = $e->errorCode() === 'nfc_code_exists';
}
check($duplicate, 'A7: alta hexadecimal equivalente → conflicto');

// ── number opcional: se usa el canónico ─────────────────────────────────
$card2 = $service->create(['code' => '00EFA5BC']);
check($card2['number'] === '0015705532', 'A8: sin number se usa el decimal canónico');
check($card2['code_last4'] === '5532', 'A9: code_last4 del hex convertido');

// ── Compatibilidad con códigos de texto libre existentes ────────────────
$legacy = $service->create(['number' => 'F131-LEGACY', 'code' => 'F131LEGACY']);
check($legacy['number'] === 'F131-LEGACY', 'A10: texto libre sigue admitiéndose');
$legacyHash = $pdo->query('SELECT code_hash FROM nfc_cards WHERE id=' . (int) $legacy['id'])->fetchColumn();
check($legacyHash === hash('sha256', 'F131LEGACY'), 'A11: texto libre conserva su hash');

// ── Fuera del espacio de 32 bits ────────────────────────────────────────
$rejected = false;
try {
    $service->create(['number' => 'F131-RANGE', 'code' => '9999999999']);
} catch (\App\Support\Errors\UnprocessableException $e) {
    $rejected = $e->errorCode() === 'validation_error';
}
check($rejected, 'A12: decimal fuera de 32 bits → validation_error');

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
