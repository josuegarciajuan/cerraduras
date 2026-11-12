<?php
declare(strict_types=1);

/**
 * F130 — barrera operativa de alcance del radar.
 *
 * Un PRESENT solo acredita hasta `maximum_range`; el eco bruto se conserva.
 *
 * Run: php tests/Unit/RadarOperationalRangeDecisionTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\RadarOperationalRangeDecision as Gate;
use App\Domain\Sensors\RadarDistanceReport;

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

$occurred = '2026-11-12T15:12:26.124Z';
$settings = [
    'maximum_range' => 1.95,
    'minimum_range' => 0,
    'radar_sensitivity' => 7,
    'fading_time' => 5,
    'detection_delay' => 0.1,
];
$key = RadarDistanceReport::configKey($settings);

function frame(array $settings, float $distance, string $key, array $extra = []): array
{
    global $occurred;
    return array_replace_recursive([
        'settings' => $settings,
        'distance_report' => [
            'marker' => 'v1|sess|7|' . number_format($distance, 3, '.', '') . '|push',
            'distance_m' => $distance,
            'config_key' => $key,
            'received_at' => $occurred,
        ],
        'presence_report' => [
            'marker' => 'p1|sess|9|PRESENT|push',
            'value' => 'PRESENT',
            'config_key' => $key,
            'received_at' => $occurred,
        ],
    ], $extra);
}

// ─── Fuera de alcance ────────────────────────────────────────────────────
$out = Gate::evaluate('PRESENT', frame($settings, 2.64, $key));
same('2.64 > 1.95 → out_of_range', $out['status'], Gate::STATUS_OUT_OF_RANGE);
same('out_of_range no permite PRESENT', $out['allow_present'], false);
same('out_of_range convierte a ABSENT', $out['convert_to_absent'], true);
same('motivo visible', $out['reason'], Gate::REASON_OUT_OF_RANGE);
same('discard_reason', $out['discard_reason'], Gate::DISCARD_OUT_OF_RANGE);
near('conserva el eco bruto', $out['distance_m'], 2.64);
near('conserva el máximo', $out['maximum_range_m'], 1.95);
same('conserva el marcador DP9', $out['distance_marker'], 'v1|sess|7|2.640|push');
near('precisión declarada', $out['precision_m'], 0.01);

$justAbove = Gate::evaluate('PRESENT', frame($settings, 1.96, $key));
same('1.96 > 1.95 → fuera', $justAbove['status'], Gate::STATUS_OUT_OF_RANGE);

// ─── Dentro del alcance ──────────────────────────────────────────────────
$in = Gate::evaluate('PRESENT', frame($settings, 1.40, $key));
same('1.40 <= 1.95 → in_range', $in['status'], Gate::STATUS_IN_RANGE);
same('in_range permite PRESENT', $in['allow_present'], true);
same('in_range no descarta', $in['discard_reason'], null);
same('motivo dentro', $in['reason'], Gate::REASON_WITHIN_RANGE);

$equal = Gate::evaluate('PRESENT', frame($settings, 1.95, $key));
same('igual al máximo se admite', $equal['status'], Gate::STATUS_IN_RANGE);

// ─── Sin DP9 fiable: estricto, no acredita ───────────────────────────────
$noDp9 = frame($settings, 1.40, $key);
unset($noDp9['distance_report']);
$missing = Gate::evaluate('PRESENT', $noDp9);
same('sin DP9 → unverified', $missing['status'], Gate::STATUS_UNVERIFIED);
same('sin DP9 no permite PRESENT', $missing['allow_present'], false);
same('sin DP9 no convierte', $missing['convert_to_absent'], false);
same('motivo no_dp9', $missing['reason'], Gate::REASON_NO_DP9);
same('descarte range_unverified', $missing['discard_reason'], Gate::DISCARD_UNVERIFIED);

$zero = Gate::evaluate('PRESENT', frame($settings, 0.0, $key));
same('DP9 cero → unverified', $zero['status'], Gate::STATUS_UNVERIFIED);
same('motivo zero_dp9', $zero['reason'], Gate::REASON_ZERO_DP9);

$negative = frame($settings, 1.0, $key);
$negative['distance_report']['distance_m'] = -0.5;
$neg = Gate::evaluate('PRESENT', $negative);
same('DP9 negativa → unverified', $neg['status'], Gate::STATUS_UNVERIFIED);

$cached = $settings;
$cached['target_distance'] = 0.8;
$cachedFrame = ['settings' => $cached, 'presence_report' => ['value' => 'PRESENT']];
$cachedResult = Gate::evaluate('PRESENT', $cachedFrame);
same('target_distance cacheado no es autoridad', $cachedResult['status'], Gate::STATUS_UNVERIFIED);
same('cacheado: motivo no_dp9', $cachedResult['reason'], Gate::REASON_NO_DP9);

// ─── Correlación de configuración ────────────────────────────────────────
$otherSettings = $settings;
$otherSettings['maximum_range'] = 2.50;
$otherKey = RadarDistanceReport::configKey($otherSettings);
$mismatch = Gate::evaluate('PRESENT', frame($settings, 1.40, (string) $otherKey));
same('config_key distinta → unverified', $mismatch['status'], Gate::STATUS_UNVERIFIED);
same('motivo config_mismatch', $mismatch['reason'], Gate::REASON_CONFIG_MISMATCH);

$presenceMismatch = frame($settings, 1.40, $key, [
    'presence_report' => ['config_key' => (string) $otherKey],
]);
$pm = Gate::evaluate('PRESENT', $presenceMismatch);
same('DP1 con otra config → unverified', $pm['status'], Gate::STATUS_UNVERIFIED);

$noKey = frame($settings, 1.40, $key);
$noKey['distance_report']['config_key'] = '';
$nk = Gate::evaluate('PRESENT', $noKey);
same('DP9 sin config_key → unverified', $nk['status'], Gate::STATUS_UNVERIFIED);

// ─── Configuración inválida / dispositivo sin alcance ────────────────────
$noMaxSettings = $settings;
unset($noMaxSettings['maximum_range']);
$noMaxKey = RadarDistanceReport::configKey($noMaxSettings);
$noMax = Gate::evaluate('PRESENT', frame($noMaxSettings, 1.0, (string) $noMaxKey));
same('sin maximum_range → unverified', $noMax['status'], Gate::STATUS_UNVERIFIED);
same('motivo invalid_maximum_range', $noMax['reason'], Gate::REASON_INVALID_MAXIMUM);

$badMax = $settings;
$badMax['maximum_range'] = 20.0;
$bad = Gate::evaluate('PRESENT', frame($badMax, 1.0, (string) RadarDistanceReport::configKey($badMax)));
same('maximum_range fuera de rango → unverified', $bad['status'], Gate::STATUS_UNVERIFIED);

$notRadar = Gate::evaluate('PRESENT', null);
same('sin settings ni DP9 → no aplicable', $notRadar['status'], Gate::STATUS_NOT_APPLICABLE);
same('no aplicable conserva PRESENT', $notRadar['allow_present'], true);

// ─── ABSENT nunca se bloquea por distancia ───────────────────────────────
$absent = Gate::evaluate('ABSENT', frame($settings, 2.64, $key));
same('ABSENT → no aplicable', $absent['status'], Gate::STATUS_NOT_APPLICABLE);
same('ABSENT permite el flujo', $absent['allow_present'], true);
same('ABSENT no convierte', $absent['convert_to_absent'], false);

// ─── Resumen ─────────────────────────────────────────────────────────────
echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
