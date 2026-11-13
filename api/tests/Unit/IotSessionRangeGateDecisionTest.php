<?php
declare(strict_types=1);

/**
 * F134 — decisión diagnóstica cuando la DP9 ya está en rango.
 *
 * El radar puede detectar en una sala sin política radar explícita. Esa sala
 * no debe quedarse con un `presence_outcome` viejo de `range_unverified`
 * mientras la sesión ya está en PRESENT.
 *
 * Run: php tests/Unit/IotSessionRangeGateDecisionTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\IotSession;
use App\Domain\Presence\IotSessionService;
use App\Domain\Presence\PresenceTransitionPolicy;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function eq(string $m, mixed $a, mixed $e): void {
    $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }

$method = (new ReflectionClass(IotSessionService::class))->getMethod('rangeGateDecision');
$method->setAccessible(true);
$gate = [
    'status' => 'in_range',
    'reason' => 'episode_evidence_in_range',
    'distance_m' => 2.82,
    'maximum_range_m' => 4.05,
    'distance_marker' => 'v1|mw|835|2.820|push',
];
$episode = ['marker' => 'p1|mw|35|PRESENT|push', 'started_at' => '2026-11-13 11:04:59.870'];

echo "IotSession range-gate decision (F134)\n";
echo str_repeat('=', 60) . "\n";

$present = $method->invoke(null, IotSession::PRESENCE_PRESENT, $episode, $gate, '2026-11-13T11:08:00.000Z');
eq('sesión ya PRESENT → PRESENT_CONFIRMED', $present['state'], 'PRESENT_CONFIRMED');
eq('raw PRESENT', $present['raw'], 'PRESENT');
check('no aplica por sí sola', $present['apply'] === false);
eq('conserva el range_gate', $present['range_gate'], $gate);
eq('conserva el episodio', $present['episode'], $episode);

$absent = $method->invoke(null, IotSession::PRESENCE_ABSENT, $episode, $gate, '2026-11-13T11:08:00.000Z');
eq('sesión ABSENT con evidencia → CANDIDATE_PRESENT', $absent['state'], 'CANDIDATE_PRESENT');
check('candidato tampoco aplica solo', $absent['apply'] === false);

eq('NOOP con presencia en vigor → maintained',
    PresenceTransitionPolicy::outcomeFor('noop', false, true), PresenceTransitionPolicy::OUTCOME_MAINTAINED);
eq('no_context → blocked',
    PresenceTransitionPolicy::outcomeFor('no_context', false, false), PresenceTransitionPolicy::OUTCOME_BLOCKED);

$source = file_get_contents(__DIR__ . '/../../src/Domain/Presence/IotSessionService.php') ?: '';
check('el rango verificado entra sin radarMode',
    str_contains($source, '$radarMode !== null || $hasVerifiedRange'));
check('usa la decisión diagnóstica de rango',
    str_contains($source, 'self::rangeGateDecision'));
check('la decisión diagnóstica no corta el flujo normal',
    str_contains($source, '($radarDecision[\'diagnostic\'] ?? false) !== true'));
check('la decisión diagnóstica se marca como tal',
    str_contains($source, "'diagnostic' => true"));

echo "\nTotal: {$P} passed, {$F} failed\n\n";
exit($F === 0 ? 0 : 1);
