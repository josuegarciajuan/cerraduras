<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\RadarAbsenceEdgeDecision;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }

echo "Radar absence edge (DP1)\n";

$now = new DateTimeImmutable('2026-11-04T15:16:26Z', new DateTimeZone('UTC'));
$base = ['value' => 'ABSENT', 'marker' => 'p1|s|1|ABSENT|push', 'received_at' => '2026-11-04 15:16:21.000'];

check('sin reporte → no decide', RadarAbsenceEdgeDecision::evaluate(null, $now) === null);
check('PRESENT → no decide', RadarAbsenceEdgeDecision::evaluate(['value' => 'PRESENT'] + $base, $now) === null);

$pending = RadarAbsenceEdgeDecision::evaluate($base, new DateTimeImmutable('2026-11-04T15:16:25Z', new DateTimeZone('UTC')), 5);
check('ABSENT 4 s → pendiente', $pending !== null);
same('pendiente estado', $pending['state'] ?? null, RadarAbsenceEdgeDecision::STATE_PENDING);
same('pendiente apply=false', $pending['apply'] ?? null, false);
same('pendiente motivo', $pending['discard_reason'] ?? null, 'absent_edge_pending');
same('pendiente fuente', $pending['source'] ?? null, 'dp1_edge');
same('pendiente marcador', $pending['marker'] ?? null, $base['marker']);

$confirmed = RadarAbsenceEdgeDecision::evaluate(
    ['value' => 'ABSENT', 'marker' => 'p1|s|2|ABSENT|push', 'received_at' => '2026-11-04 15:16:21.000'],
    new DateTimeImmutable('2026-11-04T15:16:26Z', new DateTimeZone('UTC')),
    5
);
check('ABSENT 5 s → confirmado', $confirmed !== null);
same('confirmado estado', $confirmed['state'] ?? null, RadarAbsenceEdgeDecision::STATE_CONFIRMED);
same('confirmado apply=true', $confirmed['apply'] ?? null, true);
same('confirmado sin motivo', $confirmed['discard_reason'] ?? null, null);
same('confirmado edad', $confirmed['report_age_seconds'] ?? null, 5);

$iso = RadarAbsenceEdgeDecision::evaluate(
    ['value' => 'ABSENT', 'marker' => 'p1|s|3|ABSENT|push', 'received_at' => '2026-11-04T15:16:20.000Z'],
    new DateTimeImmutable('2026-11-04T15:16:21Z', new DateTimeZone('UTC')),
    5
);
check('ISO recibido → pendiente', $iso !== null && ($iso['apply'] ?? true) === false);

$noAge = RadarAbsenceEdgeDecision::evaluate(['value' => 'ABSENT', 'marker' => 'p1|s|4|ABSENT|push'], $now, 5);
check('sin fecha → pendiente (no aplica)', $noAge !== null && ($noAge['apply'] ?? true) === false);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
