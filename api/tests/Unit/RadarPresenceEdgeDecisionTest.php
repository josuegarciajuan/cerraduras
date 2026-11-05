<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\RadarPresenceEdgeDecision;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }

$utc = new DateTimeZone('UTC');
echo "Radar presence edge (DP1)\n";

check('sin reporte → no decide', RadarPresenceEdgeDecision::evaluate(null, new DateTimeImmutable('2026-11-04T15:16:26Z', $utc)) === null);
check('valor inválido → no decide', RadarPresenceEdgeDecision::evaluate(['value' => 'MOVE'], new DateTimeImmutable('2026-11-04T15:16:26Z', $utc)) === null);

$present = RadarPresenceEdgeDecision::evaluate(
    ['value' => 'PRESENT', 'marker' => 'p1|s|1|PRESENT|push', 'received_at' => '2026-11-04 15:16:25.000'],
    new DateTimeImmutable('2026-11-04T15:16:25.200Z', $utc)
);
check('PRESENT fresco → confirma', $present !== null);
same('PRESENT estado', $present['state'] ?? null, RadarPresenceEdgeDecision::STATE_PRESENT);
same('PRESENT apply', $present['apply'] ?? null, true);
same('PRESENT fuente', $present['source'] ?? null, 'dp1_edge');
same('PRESENT marcador', $present['marker'] ?? null, 'p1|s|1|PRESENT|push');

$oldPresent = RadarPresenceEdgeDecision::evaluate(
    ['value' => 'PRESENT', 'marker' => 'p1|s|2|PRESENT|push', 'received_at' => '2026-11-04 15:10:00.000'],
    new DateTimeImmutable('2026-11-04T15:16:26Z', $utc)
);
check('PRESENT demasiado viejo → fallback', $oldPresent === null);

$pending = RadarPresenceEdgeDecision::evaluate(
    ['value' => 'ABSENT', 'marker' => 'p1|s|3|ABSENT|push', 'received_at' => '2026-11-04 15:16:25.000'],
    new DateTimeImmutable('2026-11-04T15:16:26.000Z', $utc),
    1500
);
check('ABSENT 1 s → pendiente', $pending !== null);
same('ABSENT pendiente estado', $pending['state'] ?? null, RadarPresenceEdgeDecision::STATE_CANDIDATE_ABSENT);
same('ABSENT pendiente apply', $pending['apply'] ?? null, false);
same('ABSENT pendiente motivo', $pending['discard_reason'] ?? null, 'absent_pending');
check('motivo corto cabe en VARCHAR(16)', strlen((string) ($pending['discard_reason'] ?? '')) <= 16);

$confirmed = RadarPresenceEdgeDecision::evaluate(
    ['value' => 'ABSENT', 'marker' => 'p1|s|4|ABSENT|push', 'received_at' => '2026-11-04 15:16:25.000'],
    new DateTimeImmutable('2026-11-04T15:16:26.600Z', $utc),
    1500
);
check('ABSENT 1,6 s → confirma', $confirmed !== null);
same('ABSENT confirmado estado', $confirmed['state'] ?? null, RadarPresenceEdgeDecision::STATE_ABSENT);
same('ABSENT confirmado apply', $confirmed['apply'] ?? null, true);
same('ABSENT confirmado sin motivo', $confirmed['discard_reason'] ?? null, null);
same('ABSENT confirmado edad', $confirmed['report_age_seconds'] ?? null, 1.6);

$iso = RadarPresenceEdgeDecision::evaluate(
    ['value' => 'ABSENT', 'marker' => 'p1|s|5|ABSENT|push', 'received_at' => '2026-11-04T15:16:25.000Z'],
    new DateTimeImmutable('2026-11-04T15:16:26.600Z', $utc),
    1500
);
check('ABSENT ISO con ms → confirma', $iso !== null && ($iso['apply'] ?? false) === true);

$noDate = RadarPresenceEdgeDecision::evaluate(
    ['value' => 'ABSENT', 'marker' => 'p1|s|6|ABSENT|push'],
    new DateTimeImmutable('2026-11-04T15:16:26Z', $utc),
    1500
);
check('sin fecha → fallback (no aplica)', $noDate === null);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
