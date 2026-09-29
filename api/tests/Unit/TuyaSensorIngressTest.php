<?php
declare(strict_types=1);

/**
 * Unit tests for TuyaSensorIngress::tsToIso (F58 / RF-65).
 *
 * El sello del DP `status[].t` es un epoch de 13 dígitos en milisegundos.
 * El orden del mismo segundo depende de conservar la fracción.
 *
 * Run:
 *   php tests/Unit/TuyaSensorIngressTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Gateways\Sensor\TuyaSensorIngress;

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

// 13 dígitos (ms): conserva la fracción.
eq(
    'F58 tsToIso: 13 dígitos ms → .904Z',
    TuyaSensorIngress::tsToIso(1790670449904),
    '2026-09-29T08:27:29.904Z'
);

// Fallback de 10 dígitos (segundos).
eq(
    'F58 tsToIso: 10 dígitos s → .000Z',
    TuyaSensorIngress::tsToIso(1790670449),
    '2026-09-29T08:27:29.000Z'
);

// Milisegundos < 100 → padding a 3 dígitos.
eq(
    'F58 tsToIso: padding de ms (007)',
    TuyaSensorIngress::tsToIso(1790670449007),
    '2026-09-29T08:27:29.007Z'
);

// Sello inválido → now con fracción, nunca sin ms.
$nowIso = TuyaSensorIngress::tsToIso(null);
if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $nowIso) === 1) {
    pass('F58 tsToIso: sello inválido → now con ms');
} else {
    fail('F58 tsToIso: sello inválido debe devolver now con ms, got ' . $nowIso);
}

// Longitud ISO con fracción (contrato de formato).
$iso = TuyaSensorIngress::tsToIso(1790670449904);
if (strlen($iso) === 24) {
    pass('F58 tsToIso: longitud ISO con ms (24)');
} else {
    fail('F58 tsToIso: longitud inesperada ' . strlen($iso) . ' (' . $iso . ')');
}

echo "\nTotal: {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
