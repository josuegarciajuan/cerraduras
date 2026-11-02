<?php
declare(strict_types=1);

/**
 * F107/RF-230 — identidad de reportes DP 9.
 *
 * Run:
 *   php tests/Unit/RadarDistanceReportTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarDistanceReport;

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

$at = '2026-11-02T10:20:00.123Z';

$ok = RadarDistanceReport::fromMarker('v1|sess1|7|2.710|push', $at);
same('F107: marca válida', $ok['marker'], 'v1|sess1|7|2.710|push');
same('F107: sesión', $ok['session_id'], 'sess1');
same('F107: contador', $ok['report_counter'], 7);
same('F107: distancia', $ok['distance_m'], 2.71);
same('F107: origen push', $ok['origin'], 'push');
same('F107: instante de recepción', $ok['received_at'], $at);

$legacyQuery = RadarDistanceReport::fromMarker('v1|sess1|8|0.000|query', $at);
same('F108: query antiguo queda sin atribuir', $legacyQuery['origin'], 'unattributed');
same('F108: cero es evidencia', $legacyQuery['distance_m'], 0.0);

$unattributed = RadarDistanceReport::fromMarker('v1|sess1|9|2.710|unattributed', $at);
same('F108: origen unattributed aceptado', $unattributed['origin'], 'unattributed');

same('F107: sin marca', RadarDistanceReport::fromMarker(null, $at), null);
same('F107: marca vacía', RadarDistanceReport::fromMarker('', $at), null);
same('F107: prefijo desconocido', RadarDistanceReport::fromMarker('v2|s|1|1.0|push', $at), null);
same('F107: formato incompleto', RadarDistanceReport::fromMarker('v1|s|1|1.0', $at), null);
same('F107: contador no numérico', RadarDistanceReport::fromMarker('v1|s|x|1.0|push', $at), null);
same('F107: valor no numérico', RadarDistanceReport::fromMarker('v1|s|1|x|push', $at), null);
same('F107: valor negativo', RadarDistanceReport::fromMarker('v1|s|1|-1|push', $at), null);
same('F107: origen inválido', RadarDistanceReport::fromMarker('v1|s|1|1.0|other', $at), null);
same('F107: sin instante', RadarDistanceReport::fromMarker('v1|s|1|1.0|push', ''), null);

$key1 = RadarDistanceReport::configKey(['maximum_range'=>3.9,'minimum_range'=>0.6,'radar_sensitivity'=>7,'fading_time'=>15.5,'detection_delay'=>0.1,'illuminance'=>50]);
$key2 = RadarDistanceReport::configKey(['maximum_range'=>3.9,'minimum_range'=>0.6,'radar_sensitivity'=>7,'fading_time'=>15.5,'detection_delay'=>0.1,'illuminance'=>90]);
$key3 = RadarDistanceReport::configKey(['maximum_range'=>4.2,'minimum_range'=>0.6,'radar_sensitivity'=>7,'fading_time'=>15.5,'detection_delay'=>0.1]);
check('F108: configKey ignora iluminancia', $key1 === $key2);
check('F108: configKey cambia con el rango', $key1 !== $key3);

$repeat = RadarDistanceReport::fromMarker('v1|sess1|7|2.710|push', $at);
check('F107: misma marca = misma identidad', $repeat['marker'] === $ok['marker']);
check('F107: distancia igual no cambia la identidad', $repeat['distance_m'] === $ok['distance_m']);

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
