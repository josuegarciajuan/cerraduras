<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Http\Controllers\SensorWizardController;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  OK {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  FAIL {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void
{
    if ($a === $e) { ok($m); return; }
    ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}
function truthy(string $m, bool $c): void { $c ? ok($m) : ko($m); }

$api = dirname(__DIR__, 2);
$controller = (string) file_get_contents($api . '/src/Http/Controllers/SensorWizardController.php');
$js = (string) file_get_contents($api . '/public/assets/sensor-wizard-v2.js');
$routes = (string) file_get_contents($api . '/public/index.php');
$catalog = (string) file_get_contents($api . '/public/assets/sensor-tests-v2.json');
$validator = (string) file_get_contents($api . '/src/Domain/Sensors/SensorCalibrationValidator.php');

echo "Light phase flow (F112)\n";

// ── contrato servidor ────────────────────────────────────────────────
truthy('accion light/phase', str_contains($controller, 'public function lightPhase'));
truthy('ruta light/phase', str_contains($routes, '/light/phase'));
truthy('fases persistidas', str_contains($controller, "'PREPARE_OFF'"));
truthy('fase READY_TO_START', str_contains($controller, "'READY_TO_START'"));
truthy('fase CONFIRM_ON', str_contains($controller, "'CONFIRM_ON'"));
truthy('fase CONFIRM_OFF', str_contains($controller, "'CONFIRM_OFF'"));
truthy('fase READY_TO_VALIDATE', str_contains($controller, "'READY_TO_VALIDATE'"));
truthy('fase INDETERMINATE', str_contains($controller, "'INDETERMINATE'"));
truthy('error de fase invalida', str_contains($controller, 'light_phase_invalid'));
truthy('error de intento no activo', str_contains($controller, 'attempt_not_active'));
truthy('reintento incrementa ciclo', str_contains($controller, '$cycle++'));

// startAttempt de luz ya no envia ordenes
truthy(
    'empezar intento no envia orden',
    !str_contains($controller, "if (\$stepId === 'light') {\n                await")
        && !str_contains($controller, "switchTest('OFF')")
);
truthy('empezar intento inicializa fase', str_contains($controller, "\$r['steps']['light']['phase'] = 'PREPARE_OFF';"));
truthy('empezar intento inicializa ciclo', str_contains($controller, "\$r['steps']['light']['cycle'] = 1;"));

// idempotencia de la orden
truthy('operacion de luz determinista', str_contains($controller, 'lightOperationId'));
truthy('clave incluye ciclo', str_contains($controller, "'f112|' . \$runId . '|' . \$attemptId . '|' . \$cycle"));
truthy('orden de luz reutiliza idempotencia', str_contains($controller, '$this->executeSwitchOrder($roomId, $action, $origin, $actor, $operationId)'));

// validacion usa la evidencia persistida
truthy('check une humano persistido', str_contains($controller, '$human = array_merge($persisted, $human);'));
truthy('check usa telemetria persistida', str_contains($controller, "\$human['telemetry_on'] = \$telemetry['on']"));

// ── panel ────────────────────────────────────────────────────────────
truthy('panel lee fase del servidor', str_contains($js, 'function lightPhaseOf()'));
truthy('panel lee paso de luz', str_contains($js, 'function lightStep()'));
truthy('panel usa light/phase', str_contains($js, "V2 + '/light/phase'"));
truthy('panel ya no usa switch/test en luz', !str_contains($js, "V2 + '/switch/test'"));
truthy('texto de apagado previo', str_contains($js, 'deja la lámpara <strong>apagada</strong>'));
truthy('aviso de encendido automatico', str_contains($js, 'se encenderá automáticamente'));
truthy('boton empezar prueba encender', str_contains($js, 'Empezar prueba (encender)'));
truthy('confirmacion de encendido', str_contains($js, '¿Se encendió la lámpara?'));
truthy('confirmacion de apagado', str_contains($js, '¿Se apagó la lámpara?'));
truthy('resultado indeterminado visible', str_contains($js, 'Resultado indeterminado'));
truthy('reintento visible', str_contains($js, 'Repetir prueba de luz'));
truthy('recarga no envia orden', !str_contains($js, "stepId === 'light') {\n        await switchTest"));
truthy('sin handler humano antiguo', !str_contains($js, "else if (a === 'human')"));
truthy('panel usa la frescura de la orden', str_contains($js, 'data.fresh'));
truthy('servidor devuelve frescura', str_contains($controller, "'fresh' => is_array(\$order)"));
truthy('orden incluye frescura', str_contains($controller, "'fresh' => \$payload['fresh'] ?? null"));

// ── catalogo y validador ─────────────────────────────────────────────
truthy('catalogo describe el nuevo flujo', str_contains($catalog, 'se encenderá automáticamente'));
truthy('validador exige preparacion apagada', str_contains($validator, 'require_prepare_off'));
truthy('validador sigue pidiendo humano on/off', str_contains($validator, "if ((\$human['on'] ?? null) !== true || (\$human['off'] ?? null) !== true)"));

// ── no regresion de otros pasos ──────────────────────────────────────
truthy('render de puerta intacto', str_contains($js, 'function renderDoor'));
truthy('render de radar intacto', str_contains($js, 'function renderRadar'));
$planner = (string) file_get_contents($api . '/src/Domain/Sensors/RadarCalibrationPlanner.php');
truthy('planner sin cambios de luz', !str_contains($planner, 'light_phase'));
$exterior = (string) file_get_contents($api . '/src/Domain/Sensors/RadarExteriorPresenceEvaluator.php');
truthy('evaluador exterior sin cambios de luz', !str_contains($exterior, 'light_phase'));

// ── idempotencia de la clave (reflexion) ─────────────────────────────
$m = new ReflectionMethod(SensorWizardController::class, 'lightOperationId');
$m->setAccessible(true);
$instance = (new ReflectionClass(SensorWizardController::class))->newInstanceWithoutConstructor();
$a1 = $m->invoke($instance, '14-20260101T000000Z-aaaaaa', 'a-1', 1, 'on');
$a2 = $m->invoke($instance, '14-20260101T000000Z-aaaaaa', 'a-1', 1, 'on');
$b = $m->invoke($instance, '14-20260101T000000Z-aaaaaa', 'a-1', 2, 'on');
$c = $m->invoke($instance, '14-20260101T000000Z-aaaaaa', 'a-2', 1, 'on');
$d = $m->invoke($instance, '14-20260101T000000Z-aaaaaa', 'a-1', 1, 'off');
same('misma entrada mismo id', $a1, $a2);
truthy('otro ciclo otro id', $a1 !== $b);
truthy('otro intento otro id', $a1 !== $c);
truthy('otra accion otro id', $a1 !== $d);
truthy('formato uuid valido', \App\Domain\Sensors\SwitchCommandEvidence::isOperationId($a1));

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
