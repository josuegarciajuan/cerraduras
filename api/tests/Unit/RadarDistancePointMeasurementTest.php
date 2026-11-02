<?php
declare(strict_types=1);

/**
 * F107/RF-235 — medición por punto con reportes DP 9 independientes.
 *
 * Run:
 *   php tests/Unit/RadarDistancePointMeasurementTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarDistancePointMeasurement;

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void
{
    if ($a === $e) { pass($m); return; }
    fail($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}
function check(string $m, bool $c): void { $c ? pass($m) : fail($m); }

const T0 = 1700000000;

function report(int $sec, float $distance, string $marker, string $origin = 'push'): array
{
    return [
        'marker' => $marker,
        'distance_m' => $distance,
        'origin' => $origin,
        'received_at' => gmdate('Y-m-d\TH:i:s\Z', T0 + $sec),
    ];
}

function analyze(array $reports, array $opts = []): array
{
    return RadarDistancePointMeasurement::analyze(
        $reports,
        gmdate('Y-m-d\TH:i:s\Z', T0),
        gmdate('Y-m-d\TH:i:s\Z', T0 + 30),
        $opts + ['min_samples' => 2, 'min_span_seconds' => 5, 'tolerance' => 0.30]
    );
}

echo "Radar distance point measurement (F107)\n";

$a = analyze([
    report(0, 2.71, 'm1'),
    report(3, 2.71, 'm2'),
    report(7, 2.71, 'm3'),
]);
same('tres marcas distintas con la misma distancia → aceptado', $a['status'], 'accepted');
same('las tres son muestras', $a['positive_reports'], 3);
same('media de valores iguales', $a['mean'], 2.71);
same('dispersión cero', $a['spread'], 0.0);
same('cobertura completa', $a['coverage'], 1.0);

$dup = analyze([
    report(0, 2.71, 'same'),
    report(3, 2.71, 'same'),
    report(7, 2.71, 'same'),
]);
same('la misma marca no se cuenta dos veces', $dup['reports'], 1);
same('una sola muestra es insuficiente', $dup['status'], 'insufficient');
same('motivo pocas muestras', $dup['reason'], 'not_enough_reports');

$short = analyze([
    report(0, 2.71, 'a'),
    report(1, 2.71, 'b'),
]);
same('dos muestras en menos de 5 s → ventana corta', $short['status'], 'short_window');
same('motivo ventana corta', $short['reason'], 'window_too_short');

$zero = analyze([
    report(0, 0.0, 'z1'),
    report(4, 0.0, 'z2'),
]);
same('solo ceros → sin distancia', $zero['status'], 'no_distance');
same('motivo sin distancia', $zero['reason'], 'no_positive_distance');

$mixed = analyze([
    report(0, 0.0, 'z'),
    report(3, 2.71, 'a'),
    report(8, 2.71, 'b'),
]);
same('cero no es una distancia pero no invalida las positivas', $mixed['status'], 'accepted');
same('un cero contabilizado', $mixed['zero_reports'], 1);
same('dos positivas', $mixed['positive_reports'], 2);

$outlier = analyze([
    report(0, 2.78, 'a'),
    report(4, 2.84, 'b'),
    report(8, 2.89, 'c'),
    report(12, 3.70, 'd'),
]);
same('tres coherentes + una atípica → inestable por mayoría', $outlier['status'], 'unstable');
same('motivo mayoría', $outlier['reason'], 'inconsistent_majority');
same('tres en el conjunto', count($outlier['cluster']), 3);
same('una excluida', count($outlier['excluded']), 1);

$outlierOk = analyze([
    report(0, 2.78, 'a'),
    report(4, 2.84, 'b'),
    report(8, 2.89, 'c'),
    report(12, 3.70, 'd'),
], ['coverage' => 0.70]);
same('con cobertura 70 % la atípica no bloquea', $outlierOk['status'], 'accepted');

$incoherent = analyze([
    report(0, 2.40, 'a'),
    report(4, 3.36, 'b'),
    report(8, 2.40, 'c'),
    report(12, 3.36, 'd'),
]);
same('dos grupos relevantes → inestable', $incoherent['status'], 'unstable');

$outside = analyze([
    report(-20, 2.00, 'old'),
    report(0, 2.71, 'a'),
    report(5, 2.71, 'b'),
]);
same('reportes fuera de ventana se ignoran', $outside['reports'], 2);

$query = analyze([
    report(0, 2.71, 'q1', 'query'),
    report(5, 2.71, 'q2', 'query'),
]);
same('reportes de consulta cuentan', $query['status'], 'accepted');
same('dos reportes de consulta', $query['query_reports'], 2);

$empty = analyze([]);
same('sin reportes', $empty['status'], 'no_reports');
same('motivo sin reportes', $empty['reason'], 'no_reports');

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
