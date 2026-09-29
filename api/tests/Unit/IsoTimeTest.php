<?php
declare(strict_types=1);

/**
 * Unit tests for App\Support\IsoTime (F58 / RF-65).
 *
 * Guarda la regresión de zona horaria: un formato con `\Z` literal hace que PHP
 * interprete el sello en la TZ por defecto (Europe/Madrid) y lo desplace -2 h,
 * rompiendo el orden de eventos del mismo segundo (OPEN parecía más nuevo que
 * el CLOSED ya aplicado).
 *
 * Run:
 *   php tests/Unit/IsoTimeTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Support\IsoTime;

$PASS = 0; $FAIL = 0;
function pass(string $m): void { global $PASS; $PASS++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $FAIL; $FAIL++; echo "  ❌ {$m}\n"; }
function eq(string $label, $got, $want): void
{
    if ($got === $want) {
        pass($label);
    } else {
        fail($label . ' — expected ' . var_export($want, true) . ', got ' . var_export($got, true));
    }
}

// El caso del bug: Z con fracción debe quedar en UTC sin desplazamiento local.
eq(
    'F58 IsoTime: Z con ms → UTC intacto',
    IsoTime::toMysqlUtc('2026-09-29T09:05:15.900Z'),
    '2026-09-29 09:05:15.900'
);

// Offset explícito: se convierte a UTC (09:05+02:00 = 07:05 UTC).
eq(
    'F58 IsoTime: +02:00 con ms → UTC',
    IsoTime::toMysqlUtc('2026-09-29T09:05:15.900+02:00'),
    '2026-09-29 07:05:15.900'
);

// Sin fracción: sigue funcionando y rellena .000.
eq(
    'F58 IsoTime: Z sin ms → .000',
    IsoTime::toMysqlUtc('2026-09-29T09:05:15Z'),
    '2026-09-29 09:05:15.000'
);

// Formato desconocido → null (el llamador decide el fallback).
eq(
    'F58 IsoTime: inválido → null',
    IsoTime::toMysqlUtc('not-a-date'),
    null
);

echo "\nTotal: {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
