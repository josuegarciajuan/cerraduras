<?php
declare(strict_types=1);

/**
 * F125.1.6 — distancia actual del banner de Radar Studio.
 *
 * Run: php tests/Unit/RadarLiveDistanceTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarLiveDistance;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void
{
    $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}
function near(string $m, ?float $a, ?float $e, float $tol = 0.001): void
{
    if ($a === null || $e === null) {
        same($m, $a, $e);
        return;
    }
    abs($a - $e) <= $tol ? ok($m) : ko($m . ' — expected ' . $e . ', got ' . $a);
}

$now = '2026-11-12T10:00:00.000Z';

$freshTarget = RadarLiveDistance::choose(2.42, 2, $now, null, 30, $now);
near('target fresco se usa', $freshTarget['distance_m'], 2.42);
same('target fresco: edad', $freshTarget['age_seconds'], 2);
same('target fresco: origen', $freshTarget['source'], 'target');

$zeroTarget = RadarLiveDistance::choose(0.0, 1, $now, [
    'distance_m' => 0.57,
    'received_at' => '2026-11-12T09:59:55.000Z',
], 30, $now);
near('target cero → último reporte positivo', $zeroTarget['distance_m'], 0.57);
same('reporte: edad', $zeroTarget['age_seconds'], 5);
same('reporte: origen', $zeroTarget['source'], 'report');

$fresherReport = RadarLiveDistance::choose(2.42, 20, $now, [
    'distance_m' => 0.57,
    'received_at' => '2026-11-12T09:59:58.000Z',
], 30, $now);
near('se elige la fuente más reciente', $fresherReport['distance_m'], 0.57);
same('fuente más reciente: origen', $fresherReport['source'], 'report');

$oldReport = RadarLiveDistance::choose(0.0, 1, $now, [
    'distance_m' => 0.57,
    'received_at' => '2026-11-12T09:59:20.000Z',
], 30, $now);
same('reporte antiguo se descarta', $oldReport['distance_m'], null);
same('reporte antiguo: origen', $oldReport['source'], null);

$oldTarget = RadarLiveDistance::choose(2.42, 45, $now, null, 30, $now);
same('target antiguo se descarta', $oldTarget['distance_m'], null);

$negative = RadarLiveDistance::choose(-1.0, 1, $now, [
    'distance_m' => 0.0,
    'received_at' => '2026-11-12T09:59:59.000Z',
], 30, $now);
same('valores no positivos se ignoran', $negative['distance_m'], null);

$missing = RadarLiveDistance::choose(null, null, null, null, 30, $now);
same('sin datos → null', $missing['distance_m'], null);
same('sin datos: edad null', $missing['age_seconds'], null);

$future = RadarLiveDistance::choose(0.0, null, null, [
    'distance_m' => 1.1,
    'received_at' => '2026-11-12T10:00:01.000Z',
], 30, $now);
near('desfase de reloj no invalida', $future['distance_m'], 1.1);
same('desfase de reloj: edad 0', $future['age_seconds'], 0);

$clampedMax = RadarLiveDistance::choose(0.0, null, null, [
    'distance_m' => 1.1,
    'received_at' => '2026-11-12T09:53:00.000Z',
], 999, $now);
same('umbral máximo se acota a 5 minutos', $clampedMax['distance_m'], null);

$clampedMin = RadarLiveDistance::choose(0.0, null, null, [
    'distance_m' => 1.1,
    'received_at' => '2026-11-12T09:59:50.000Z',
], 0, $now);
same('umbral mínimo se acota a 5 segundos', $clampedMin['distance_m'], null);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
