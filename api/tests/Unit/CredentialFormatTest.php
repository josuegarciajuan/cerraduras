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

// ── F131: ficha EM en decimal impreso de 10 dígitos ─────────────────────
check(CredentialFormat::isPrintedDecimal('0015705531'), 'D1: 10 dígitos es número impreso');
check(!CredentialFormat::isPrintedDecimal('15705531'), 'D2: 8 dígitos no es número impreso');
check(!CredentialFormat::isPrintedDecimal('001570553A'), 'D3: 10 caracteres no decimales no es número impreso');
check(CredentialFormat::isEmHexUid('00EFA5BB'), 'D4: UID EM de 8 hex');
check(!CredentialFormat::isEmHexUid('0015705531'), 'D5: decimal no es UID hex');
check(CredentialFormat::decimalFromEmHex('00EFA5BB') === '0015705531', 'D6: 00EFA5BB → 0015705531');
check(CredentialFormat::decimalFromEmHex('00efa5bb') === '0015705531', 'D7: hex en minúsculas → decimal');
check(CredentialFormat::decimalFromEmHex('FFFFFFFF') === '4294967295', 'D8: UID máximo de 32 bits');
check(CredentialFormat::decimalFromEmHex('0015705531') === null, 'D9: decimal no se trata como hex');
check(CredentialFormat::canonicalFichaCode('0015705531') === '0015705531', 'D10: decimal conserva ceros');
check(CredentialFormat::canonicalFichaCode('00EFA5BB') === '0015705531', 'D11: hex se canonicaliza a decimal');
check(CredentialFormat::canonicalFichaCode('00EFA5BB01') === '00EFA5BB01', 'D12: 10 hex no se reinterpreta como decimal');
check(CredentialFormat::isDecimalUidInRange('0000000000'), 'D13: cero decimal válido');
check(CredentialFormat::isDecimalUidInRange('4294967295'), 'D14: máximo decimal válido');
check(!CredentialFormat::isDecimalUidInRange('4294967296'), 'D15: fuera de 32 bits rechazado');
check(!CredentialFormat::isDecimalUidInRange('9999999999'), 'D16: 10 dígitos fuera de rango rechazado');
check(CredentialFormat::fichaCandidates('00EFA5BB') === ['0015705531', '00EFA5BB'], 'D17: candidatos desde hex');
check(CredentialFormat::fichaCandidates('0015705531') === ['0015705531', '00EFA5BB'], 'D18: candidatos desde decimal');
check(CredentialFormat::fichaCandidates('  Llave Josue  ') === ['Llave Josue'], 'D19: texto libre mantiene su hash');
check(CredentialFormat::fichaCandidates('F113UNAS123') === ['F113UNAS123'], 'D20: código legado no EM sin candidatos extra');

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
