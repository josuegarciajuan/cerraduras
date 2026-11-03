<?php
declare(strict_types=1);

/**
 * Unit tests for F113 credential classification and ficha normalisation.
 *
 * Trazabilidad: RF-270 (clasificación) y RF-271 (normalización).
 * No requiere BD ni servidor.
 *
 * Run: php tests/Unit/CredentialFormatTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\AccessKeys\CredentialFormat;

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

echo "CredentialFormat Unit Tests (F113)\n";
echo str_repeat('=', 60) . "\n\n";

// ── Clasificación: ficha en texto plano ──────────────────────────────────
check(CredentialFormat::isPlainTextFicha('00EFA5BB'), 'F1: UID EM de 8 hex es ficha');
check(CredentialFormat::isPlainTextFicha('00efa5bb'), 'F2: UID en minúsculas es ficha');
check(CredentialFormat::isPlainTextFicha('00EFA5BB01'), 'F3: UID de 10 hex es ficha');
check(CredentialFormat::isPlainTextFicha('Llave Josue'), 'F4: texto imprimible con espacios');
check(!CredentialFormat::isPlainTextFicha('a.b.c'), 'F5: token con puntos NO es ficha');
check(!CredentialFormat::isPlainTextFicha(''), 'F6: vacío NO es ficha');
check(!CredentialFormat::isPlainTextFicha('abc'), 'F7: menos de 4 caracteres NO es ficha');
check(!CredentialFormat::isPlainTextFicha(str_repeat('A', 65)), 'F8: más de 64 caracteres NO es ficha');
check(!CredentialFormat::isPlainTextFicha("00EFA5BB\n"), 'F9: salto de línea NO es ficha');
check(!CredentialFormat::isPlainTextFicha("00EF\x00A5BB"), 'F10: byte no imprimible NO es ficha');
check(CredentialFormat::isPlainTextFicha(str_repeat('A', 64)), 'F11: 64 caracteres sí es ficha');
check(CredentialFormat::isPlainTextFicha('ABCD1234'), 'F12: 8 caracteres alfanuméricos es ficha');

// ── Normalización de códigos ─────────────────────────────────────────────
check(CredentialFormat::normaliseCode('00efa5bb') === '00EFA5BB', 'N1: 8 hex → mayúsculas');
check(CredentialFormat::normaliseCode('00efa5bb01') === '00EFA5BB01', 'N2: 10 hex → mayúsculas');
check(CredentialFormat::normaliseCode(' 00EFA5BB ') === '00EFA5BB', 'N3: recorta espacios');
check(CredentialFormat::normaliseCode('abcdefgh') === 'abcdefgh', 'N4: 8 no-hex conserva caja');
check(CredentialFormat::normaliseCode('abc123') === 'abc123', 'N5: 6 hex no se normaliza');
check(CredentialFormat::normaliseCode('123456789') === '123456789', 'N6: 9 hex no se normaliza');
check(CredentialFormat::normaliseCode('  Llave Josue  ') === 'Llave Josue', 'N7: texto recortado');
check(CredentialFormat::normaliseCode('') === '', 'N8: vacío permanece vacío');
check(CredentialFormat::normaliseCode('00EFA5BB') === '00EFA5BB', 'N9: mayúsculas se conservan');

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
