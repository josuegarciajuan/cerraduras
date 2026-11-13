<?php
declare(strict_types=1);

/**
 * F134 — selección de evidencia DP9 dentro de un episodio.
 *
 * Comprueba que una DP9 con distancia 0 no oculta una DP9 positiva del mismo
 * episodio y que el marcador usado solo se consume al aplicar la presencia.
 *
 * Run: php tests/Unit/IotSessionEvidenceSelectionTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\IotSessionService;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }

$class = new ReflectionClass(IotSessionService::class);
$select = $class->getMethod('selectEpisodeReport');
$select->setAccessible(true);
$distance = $class->getMethod('reportDistance');
$distance->setAccessible(true);

$report = static fn (string $marker, float $m): array => [
    'marker' => $marker,
    'distance_m' => $m,
    'received_at' => '2026-11-13 10:53:00.000',
    'config_key' => 'key',
    'session_id' => 's1',
];

echo "IotSession evidence selection (F134)\n";
echo str_repeat('=', 60) . "\n";

$zero = $report('v0', 0.0);
$positive = $report('v1', 2.59);

$picked = $select->invoke(null, $zero, $zero, $positive);
check('DP9 cero en trama y persistida → usa la última positiva', $picked === $positive);

$picked = $select->invoke(null, $positive, $zero, null);
check('DP9 positiva en trama prevalece sobre persistida cero', $picked === $positive);

$picked = $select->invoke(null, $positive, $positive, $zero);
check('DP9 persistida positiva se mantiene', $picked === $positive);

$picked = $select->invoke(null, null, null, $positive);
check('sin DP9 en trama ni persistida → usa la última positiva', $picked === $positive);

$picked = $select->invoke(null, $zero, $zero, null);
check('sin positiva disponible → conserva la DP9 cero para diagnóstico', $picked === $zero);

check('reportDistance lee metros', $distance->invoke(null, $positive) === 2.59);
check('reportDistance de cero', $distance->invoke(null, $zero) === 0.0);
check('reportDistance inválida', $distance->invoke(null, ['distance_m' => 'x']) === null);

$source = file_get_contents(__DIR__ . '/../../src/Domain/Presence/IotSessionService.php') ?: '';
check('solo la rama ya efectiva consume el marcador',
    substr_count($source, 'presenceEpisodeDistanceMarker = $correlation') === 1);
check('consume el marcador al aplicar PRESENT',
    str_contains($source, 'presenceEpisodeDistanceMarker = $gateMarker'));
check('consulta la última DP9 positiva del episodio',
    str_contains($source, 'latestPositiveForRoom') && str_contains($source, 'selectEpisodeReport'));

echo "\nTotal: {$P} passed, {$F} failed\n\n";
exit($F === 0 ? 0 : 1);
