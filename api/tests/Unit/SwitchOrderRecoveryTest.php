<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\SwitchCommandEvidence;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  OK {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  FAIL {$m}\n"; }
function truthy(string $m, bool $c): void { $c ? ok($m) : ko($m); }
function same(string $m, mixed $a, mixed $e): void
{
    if ($a === $e) { ok($m); return; }
    ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}

$api = dirname(__DIR__, 2);
$controller = (string) file_get_contents($api . '/src/Http/Controllers/SensorWizardController.php');
$repo = (string) file_get_contents($api . '/src/Domain/Sensors/SwitchCommandEvidenceRepository.php');
$js = (string) file_get_contents($api . '/public/assets/sensor-wizard-v2.js');

echo "Switch order recovery (F112)\n";

// ── needsReconcile ───────────────────────────────────────────────────
$pending = ['publish_ok' => 1, 'reported_at' => null, 'verdict' => 'indeterminate'];
$closed = ['publish_ok' => 1, 'reported_at' => '2026-11-03 12:48:13.083', 'verdict' => 'confirmed'];
$notPublished = ['publish_ok' => null, 'reported_at' => null, 'verdict' => 'indeterminate'];
$failed = ['publish_ok' => 0, 'reported_at' => null, 'verdict' => 'publish_failed'];
$stringPending = ['publish_ok' => '1', 'reported_at' => null, 'verdict' => 'indeterminate'];

truthy('pendiente necesita reconciliacion', SwitchCommandEvidence::needsReconcile($pending));
truthy('cerrada no necesita', !SwitchCommandEvidence::needsReconcile($closed));
truthy('sin publicar no necesita', !SwitchCommandEvidence::needsReconcile($notPublished));
truthy('fallo de publicacion no necesita', !SwitchCommandEvidence::needsReconcile($failed));
truthy('publish_ok como string', SwitchCommandEvidence::needsReconcile($stringPending));
truthy('fila vacia no necesita', !SwitchCommandEvidence::needsReconcile([]));

// ── reconciliacion no republica ──────────────────────────────────────
$start = strpos($controller, 'private function reconcilePendingEvidence');
$end = strpos($controller, 'private function runLightOrder');
$block = ($start !== false && $end !== false && $end > $start) ? substr($controller, $start, $end - $start) : '';
truthy('reconciliacion existe', $block !== '');
truthy('reconciliacion no publica', !str_contains($block, 'turnOnForDevice') && !str_contains($block, 'turnOffForDevice'));
truthy('reconciliacion usa needsReconcile', str_contains($block, 'needsReconcile'));
truthy('reconciliacion cierra evidencia', str_contains($block, '$evidence->close('));
truthy('reconciliacion marca fresh solo si es posterior', str_contains($block, '$stateTs >= $requestedTs - 1.0'));
truthy('reconciliacion sin datos → unconfirmed', str_contains($block, "'verdict' => \$verdict['verdict']"));

// ── cierre garantizado ante excepcion ────────────────────────────────
truthy('execute cierra en catch', str_contains($controller, 'closeEvidenceBestEffort($evidence, $operationId)'));
truthy('cierre de emergencia no confirma', str_contains($controller, "'diagnosis' => 'order_exception'"));
truthy('excepcion se registra', str_contains($controller, "error_log('[f112] switch_order_exception"));
truthy('error publico estable', str_contains($controller, "'error' => 'light_order_failed'"));
truthy('error publico reintentable', str_contains($controller, "'retryable' => true"));
$lpStart = strpos($controller, 'public function lightPhase');
$lpEnd = strpos($controller, 'private function recordHumanConfirmation');
$lightBlock = ($lpStart !== false && $lpEnd !== false && $lpEnd > $lpStart) ? substr($controller, $lpStart, $lpEnd - $lpStart) : '';
truthy('error interno no se expone al navegador', $lightBlock !== '' && !str_contains($lightBlock, "['error' => \$e->getMessage()]"));

// ── esperas acotadas ─────────────────────────────────────────────────
truthy('estado con maxMs', str_contains($controller, 'string $expected, int $maxMs = 0'));
truthy('alcance con maxMs', str_contains($controller, 'float $since, int $maxMs = 0'));
truthy('limite de estado 3 s', str_contains($controller, "SWITCH_STATE_WAIT_MS', 3000"));
truthy('limite de alcance 2,5 s', str_contains($controller, "SWITCH_REACHABLE_WAIT_MS', 2500"));
truthy('sonda con su propio limite', str_contains($controller, "SWITCH_PROBE_WAIT_MS', 4000"));
truthy('sin bucles infinitos', !str_contains($controller, 'while (true)'));

// ── confirmacion humana ──────────────────────────────────────────────
truthy('mapeo humano separado de la orden', str_contains($controller, 'private function lightHumanKey'));
$ctl = new ReflectionClass(App\Http\Controllers\SensorWizardController::class);
$instance = $ctl->newInstanceWithoutConstructor();
$hk = $ctl->getMethod('lightHumanKey');
$hk->setAccessible(true);
same('confirm_prep_off → prep_off', $hk->invoke($instance, 'confirm_prep_off'), 'prep_off');
same('confirm_on → on', $hk->invoke($instance, 'confirm_on'), 'on');
same('confirm_off → off', $hk->invoke($instance, 'confirm_off'), 'off');
same('start_on no es humano', $hk->invoke($instance, 'start_on'), null);
same('turn_off no es humano', $hk->invoke($instance, 'turn_off'), null);

// ── panel ────────────────────────────────────────────────────────────
truthy('panel bloquea durante envio', str_contains($js, 'if (S.lightBusy) return;'));
truthy('panel aplica enfriamiento', str_contains($js, 'S.lightCooldownUntil = Date.now() + 600'));
truthy('panel exige accion explicita de apagado', str_contains($js, 'data-op="turn_off"'));
truthy('panel avisa fallo sin duplicar', str_contains($js, 'la orden no se repetirá'));
truthy('panel pide confirmar tras APAGAR', str_contains($js, '¿Se apagó la lámpara?'));

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
