<?php
declare(strict_types=1);

/**
 * F133 — correlación DP1/DP9 por episodio.
 *
 * Run: php tests/Unit/RadarEvidenceCorrelationTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\RadarEvidenceCorrelation as C;
use App\Domain\Sensors\RadarDistanceReport;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✅ {$label}\n";
        return;
    }
    $fail++;
    echo "  ❌ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "RadarEvidenceCorrelation (F133)\n";

$settings = [
    'maximum_range' => 2.10,
    'minimum_range' => 0,
    'radar_sensitivity' => 7,
    'fading_time' => 5,
    'detection_delay' => 0.1,
];
$key = RadarDistanceReport::configKey($settings);

function report(float $m, string $at, string $session = 'sess', ?string $key = null, string $marker = ''): array
{
    return [
        'marker' => $marker !== '' ? $marker : 'v1|' . $session . '|10|' . number_format($m, 3, '.', '') . '|push',
        'session_id' => $session,
        'report_counter' => 10,
        'distance_m' => $m,
        'config_key' => $key,
        'received_at' => $at,
    ];
}

function ms(string $utc): int
{
    return (int) (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Uv');
}

$now = ms('2026-11-13T10:00:10Z');
$episodeStart = ms('2026-11-13T10:00:00Z');

// ABSENT nunca se bloquea.
$absent = C::evaluate('ABSENT', null, null, null, null, null, null, $settings, $now);
check('ABSENT → not_applicable', $absent['status'] === C::STATUS_NOT_APPLICABLE);
check('ABSENT permite el flujo', $absent['allow_present'] === true);

// Sin DP9.
$none = C::evaluate('PRESENT', 'p1', 'sess', $episodeStart, null, null, null, $settings, $now);
check('sin DP9 → no_distance', $none['status'] === C::STATUS_NO_DISTANCE);
check('sin DP9 no acredita', $none['allow_present'] === false);

// DP9 cero.
$zero = C::evaluate('PRESENT', 'p1', 'sess', $episodeStart, null, report(0.0, '2026-11-13T10:00:05.000', 'sess', $key), null, $settings, $now);
check('DP9 cero → zero_distance', $zero['status'] === C::STATUS_ZERO_DISTANCE);

// Configuración distinta.
$otherSettings = $settings;
$otherSettings['maximum_range'] = 4.20;
$oldKey = RadarDistanceReport::configKey($otherSettings);
$mismatch = C::evaluate('PRESENT', 'p1', 'sess', $episodeStart, null, report(1.20, '2026-11-13T10:00:05.000', 'sess', $oldKey), null, $settings, $now);
check('config original distinta → config_mismatch', $mismatch['status'] === C::STATUS_CONFIG_MISMATCH);

// Sesión distinta.
$sessionMismatch = C::evaluate('PRESENT', 'p1', 'sess', $episodeStart, null, report(1.20, '2026-11-13T10:00:05.000', 'otra', $key), null, $settings, $now);
check('otra sesión DP1 → session_mismatch', $sessionMismatch['status'] === C::STATUS_SESSION_MISMATCH);

// DP9 anterior al episodio.
$before = C::evaluate('PRESENT', 'p1', 'sess', $episodeStart, null, report(1.20, '2026-11-13T09:59:50.000', 'sess', $key), null, $settings, $now);
check('DP9 anterior al episodio → stale', $before['status'] === C::STATUS_STALE);

// DP9 demasiado vieja.
$aged = C::evaluate('PRESENT', 'p1', 'sess', $episodeStart, null, report(1.20, '2026-11-13T09:55:00.000', 'sess', $key), null, $settings, $now);
check('DP9 fuera de frescura → stale', $aged['status'] === C::STATUS_STALE);

// Marcador ya usado.
$cached = C::evaluate('PRESENT', 'p1', 'sess', $episodeStart, 'v1|sess|10|1.200|push', report(1.20, '2026-11-13T10:00:05.000', 'sess', $key, 'v1|sess|10|1.200|push'), null, $settings, $now);
check('marcador ya usado → cached', $cached['status'] === C::STATUS_CACHED);

// Comparación en centímetros: 2,10 admite; 2,11 no.
$limit = C::evaluate('PRESENT', 'p1', 'sess', $episodeStart, null, report(2.10, '2026-11-13T10:00:05.000', 'sess', $key), null, $settings, $now);
check('2,10 m con máximo 2,10 → usable', $limit['status'] === C::STATUS_USABLE);
$over = C::evaluate('PRESENT', 'p1', 'sess', $episodeStart, null, report(2.11, '2026-11-13T10:00:05.000', 'sess', $key), null, $settings, $now);
check('2,11 m con máximo 2,10 → out_of_range', $over['status'] === C::STATUS_OUT_OF_RANGE);
check('fuera de alcance convierte a ausencia', $over['convert_to_absent'] === true);

// Correlación entre tramas: la DP9 llega sin DP1 en esa trama.
$crossFrame = C::evaluate('PRESENT', 'p1', 'sess', $episodeStart, null, null, report(1.32, '2026-11-13T10:00:08.000', 'sess', $key), $settings, $now);
check('DP9 de otra trama del mismo episodio → usable', $crossFrame['status'] === C::STATUS_USABLE);
check('evidencia entre tramas conserva el marcador', $crossFrame['marker'] !== null);

// La fila persistida manda sobre la config de la trama actual.
$persisted = report(1.32, '2026-11-13T10:00:08.000', 'sess', $key, 'v1|sess|11|1.320|push');
$frameCached = report(1.32, '2026-11-13T10:00:09.000', 'sess', $oldKey, 'v1|sess|11|1.320|push');
$preferPersisted = C::evaluate('PRESENT', 'p1', 'sess', $episodeStart, null, $frameCached, $persisted, $settings, $now);
check('la configuración persistida prevalece', $preferPersisted['status'] === C::STATUS_USABLE);

// ── F133.1: respaldo DP1 sin distancia ───────────────────────────────────
$short = ['maximum_range' => 2.10];
$nowF = 100000;
$startF = $nowF - 3000;
check('DP1 sin DP9 tras la gracia y alcance corto → elegible',
    C::dp1OnlyEligible(true, false, $startF, $nowF, $short) === true);
check('sin política radar explícita → no elegible',
    C::dp1OnlyEligible(false, false, $startF, $nowF, $short) === false);
check('almacén/TIMBRADO → no elegible',
    C::dp1OnlyEligible(true, true, $startF, $nowF, $short) === false);
check('alcance largo (>3 m) → no elegible',
    C::dp1OnlyEligible(true, false, $startF, $nowF, ['maximum_range' => 4.20]) === false);
check('alcance exacto 3 m → elegible',
    C::dp1OnlyEligible(true, false, $startF, $nowF, ['maximum_range' => 3.00]) === true);
check('dentro de la gracia → no elegible',
    C::dp1OnlyEligible(true, false, $nowF - 1000, $nowF, $short) === false);
check('desactivado → no elegible',
    C::dp1OnlyEligible(true, false, $startF, $nowF, $short, false) === false);
check('sin inicio de episodio → no elegible',
    C::dp1OnlyEligible(true, false, null, $nowF, $short) === false);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
