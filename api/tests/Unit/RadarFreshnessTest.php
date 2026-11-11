<?php
declare(strict_types=1);

/**
 * F125.1.4 — frescura específica del radar.
 *
 * Pure logic: no DB, no MQTT, no hardware.
 *
 * Run: php tests/Unit/RadarFreshnessTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarFreshness;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }

echo "Radar freshness (F125.1.4)\n";

$now = 1_800_000_000;
$ts = static fn (int $ago): string => gmdate('Y-m-d\TH:i:s\Z', $now - $ago);

same('umbral por defecto 30 s', RadarFreshness::staleSeconds(), 30);
same('edad nula', RadarFreshness::ageSeconds(null, $now), null);
same('edad inválida', RadarFreshness::ageSeconds('no-es-fecha', $now), null);
same('edad 0 s', RadarFreshness::ageSeconds($ts(0), $now), 0);
same('edad 29 s', RadarFreshness::ageSeconds($ts(29), $now), 29);

check('29 s no stale', RadarFreshness::stale($ts(29), $now) === false);
check('31 s stale', RadarFreshness::stale($ts(31), $now) === true);
check('sin timestamp stale', RadarFreshness::stale(null, $now) === true);

$freshDecision = ['state' => 'PRESENT_CONFIRMED', 'decided_at' => $ts(10), 'stale' => false];
$oldDecision = ['state' => 'PRESENT_CONFIRMED', 'decided_at' => $ts(120), 'stale' => false];
$flaggedDecision = ['state' => 'PRESENT_CONFIRMED', 'decided_at' => $ts(1), 'stale' => true];
check('decisión 10 s fresca', RadarFreshness::decisionStale($freshDecision, $now) === false);
check('decisión 120 s stale', RadarFreshness::decisionStale($oldDecision, $now) === true);
check('decisión flagged stale', RadarFreshness::decisionStale($flaggedDecision, $now) === true);
check('decisión sin decided_at → stale', RadarFreshness::decisionStale(['state' => 'PRESENT_CONFIRMED'], $now) === true);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
