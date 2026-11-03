<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\SwitchCommandEvidenceRepository;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  OK {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  FAIL {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void
{
    if ($a === $e) { ok($m); return; }
    ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}
function throws(string $m, callable $fn): void
{
    try { $fn(); ko($m . ' (sin excepción)'); }
    catch (InvalidArgumentException $e) { ok($m); }
    catch (Throwable $e) { ko($m . ' (' . get_class($e) . ')'); }
}

$api = dirname(__DIR__, 2);
$repo = (string) file_get_contents($api . '/src/Domain/Sensors/SwitchCommandEvidenceRepository.php');
$controller = (string) file_get_contents($api . '/src/Http/Controllers/SensorWizardController.php');

echo "Switch evidence datetime (F112)\n";

// ── normalización ────────────────────────────────────────────────────
same('ISO con Z', SwitchCommandEvidenceRepository::dbDate('2026-11-03T15:32:01.197Z'), '2026-11-03 15:32:01.197');
same('ISO sin milisegundos', SwitchCommandEvidenceRepository::dbDate('2026-11-03T15:32:01Z'), '2026-11-03 15:32:01.000');
same('ISO con desplazamiento +01:00', SwitchCommandEvidenceRepository::dbDate('2026-11-03T16:32:01.197+01:00'), '2026-11-03 15:32:01.197');
same('ISO con desplazamiento -05:00', SwitchCommandEvidenceRepository::dbDate('2026-11-03T10:32:01.197-05:00'), '2026-11-03 15:32:01.197');
same('formato SQL con ms', SwitchCommandEvidenceRepository::dbDate('2026-11-03 15:32:01.197'), '2026-11-03 15:32:01.197');
same('formato SQL sin ms', SwitchCommandEvidenceRepository::dbDate('2026-11-03 15:32:01'), '2026-11-03 15:32:01.000');
same('microsegundos se recortan a ms', SwitchCommandEvidenceRepository::dbDate('2026-11-03 15:32:01.123456'), '2026-11-03 15:32:01.123');
same('un dígito de fracción', SwitchCommandEvidenceRepository::dbDate('2026-11-03T15:32:01.5Z'), '2026-11-03 15:32:01.500');
same('null', SwitchCommandEvidenceRepository::dbDate(null), null);
same('cadena vacía', SwitchCommandEvidenceRepository::dbDate('   '), null);
throws('fecha inválida lanza excepción', fn () => SwitchCommandEvidenceRepository::dbDate('ayer'));
throws('fecha truncada lanza excepción', fn () => SwitchCommandEvidenceRepository::dbDate('2026-13-45T99:99:99Z'));

// ── cableado ─────────────────────────────────────────────────────────
truthy('normaliza requested_at', str_contains($repo, "self::dbDate(\$row['requested_at'])"));
truthy('normaliza prior_state_at', str_contains($repo, "self::dbDate(\$row['prior_state_at'])"));
truthy('normaliza published_at', str_contains($repo, 'self::dbDate($publishedAt)'));
truthy('normaliza reported_at', str_contains($repo, "self::dbDate(\$result['reported_at'])"));
truthy('normaliza human_at', str_contains($repo, "self::dbDate(\$result['human_at'])"));
truthy('sin INSERT IGNORE', !str_contains($repo, 'INSERT IGNORE'));
truthy('detecta duplicado por código', str_contains($repo, "(string) \$e->getCode() === '23000'"));
truthy('propaga otros errores', str_contains($repo, 'throw $e;'));
truthy('controlador no publica si no pudo registrar', str_contains($controller, "throw new \\RuntimeException('evidence_start_conflict')"));
truthy('controlador reconcilia la carrera', str_contains($controller, '$reconciled = $this->reconcilePendingEvidence($action, $evidence, $row)'));

function truthy(string $m, bool $c): void { $c ? ok($m) : ko($m); }

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
