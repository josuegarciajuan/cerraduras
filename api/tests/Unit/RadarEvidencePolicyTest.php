<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarEvidencePolicy;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void
{
    if ($a === $e) { ok($m); return; }
    ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}

$raw = static fn (string $value, ?int $age, string $at = '2026-10-30 10:00:00.000'): array => [
    'value' => $value,
    'settings_at' => $at,
    'settings_age_seconds' => $age,
];

echo "Radar evidence policy (F103/RF-207)\n";

$freshPresent = RadarEvidencePolicy::seed($raw('PRESENT', 12), 180);
same('seed PRESENT → estado', $freshPresent['state'], 'PRESENT');
same('seed PRESENT → fresco', $freshPresent['fresh'], true);
same('seed PRESENT → edad', $freshPresent['age_seconds'], 12);
same('seed PRESENT → instante', $freshPresent['occurred_at'], '2026-10-30 10:00:00.000');

$freshAbsent = RadarEvidencePolicy::seed($raw('ABSENT', 179), 180);
same('seed ABSENT al límite → fresco', $freshAbsent['fresh'], true);

$stale = RadarEvidencePolicy::seed($raw('PRESENT', 181), 180);
same('seed stale → no fresco', $stale['fresh'], false);
same('seed stale conserva estado', $stale['state'], 'PRESENT');

$unknown = RadarEvidencePolicy::seed($raw('', null), 180);
same('seed sin valor → estado null', $unknown['state'], null);
same('seed sin valor → no fresco', $unknown['fresh'], false);

$low = RadarEvidencePolicy::seed($raw('present', 5), 180);
same('seed normaliza minúsculas', $low['state'], 'PRESENT');
same('seed normaliza fresco', $low['fresh'], true);

same('arranque PRESENT 37 s → permitido', RadarEvidencePolicy::startBlockReason($raw('PRESENT', 37), 180), null);
same('arranque PRESENT 181 s → stale', RadarEvidencePolicy::startBlockReason($raw('PRESENT', 181), 180), 'presence_stale');
same('arranque ABSENT stale → stale', RadarEvidencePolicy::startBlockReason($raw('ABSENT', 400), 180), 'presence_stale');
same('arranque desconocido → permitido', RadarEvidencePolicy::startBlockReason($raw('', null), 180), null);
same('arranque sin edad → permitido', RadarEvidencePolicy::startBlockReason($raw('PRESENT', null), 180), null);

same('reentrada con PRESENT → bloqueada', RadarEvidencePolicy::reentryBlockReason($raw('PRESENT', 5)), 'reentry_not_clear');
same('reentrada con ABSENT → permitida', RadarEvidencePolicy::reentryBlockReason($raw('ABSENT', 5)), null);
same('reentrada desconocida → permitida', RadarEvidencePolicy::reentryBlockReason($raw('', null)), null);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
