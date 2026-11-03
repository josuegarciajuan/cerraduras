<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\SwitchCommandEvidence;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  OK {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  FAIL {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void
{
    if ($a === $e) { ok($m); return; }
    ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}
function truthy(string $m, bool $cond): void { $cond ? ok($m) : ko($m); }
function verdict(string $m, array $input, string $v, string $d): void
{
    $r = SwitchCommandEvidence::evaluate($input);
    same($m . ' · verdict', $r['verdict'], $v);
    same($m . ' · diagnosis', $r['diagnosis'], $d);
}

$api = dirname(__DIR__, 2);

echo "Switch command evidence (F112)\n";

// ── normalizacion ────────────────────────────────────────────────────
same('action ON', SwitchCommandEvidence::normalizeAction('on'), 'ON');
same('action OFF con espacios', SwitchCommandEvidence::normalizeAction('  off '), 'OFF');
same('action invalida', SwitchCommandEvidence::normalizeAction('toggle'), null);
same('action null', SwitchCommandEvidence::normalizeAction(null), null);
same('action numero', SwitchCommandEvidence::normalizeAction(1), null);
same('state reusa action', SwitchCommandEvidence::normalizeState('ON'), 'ON');

// ── operation_id ─────────────────────────────────────────────────────
$op = SwitchCommandEvidence::newOperationId();
truthy('uuid generado valido', SwitchCommandEvidence::isOperationId($op));
truthy('dos uuid distintos', $op !== SwitchCommandEvidence::newOperationId());
truthy('uuid en mayusculas valido', SwitchCommandEvidence::isOperationId(strtoupper($op)));
truthy('uuid corto rechazado', !SwitchCommandEvidence::isOperationId('1234'));
truthy('uuid con caracteres invalidos rechazado', !SwitchCommandEvidence::isOperationId('zzzzzzzz-zzzz-zzzz-zzzz-zzzzzzzzzzzz'));
truthy('uuid no string rechazado', !SwitchCommandEvidence::isOperationId(123));

// ── etiquetas ────────────────────────────────────────────────────────
same('label limpia', SwitchCommandEvidence::label(' sensores_light ', 32), 'sensores_light');
same('label recorta longitud', SwitchCommandEvidence::label(str_repeat('a', 40), 8), 'aaaaaaaa');
same('label sustituye espacios', SwitchCommandEvidence::label('prueba luz', 32), 'prueba_luz');
same('label vacia', SwitchCommandEvidence::label('   ', 32), null);
same('label no string', SwitchCommandEvidence::label(['x'], 32), null);

// ── veredictos ───────────────────────────────────────────────────────
verdict('accion invalida', ['action' => 'X', 'publish_ok' => true], 'indeterminate', 'invalid_action');
verdict('publicacion fallida', ['action' => 'ON', 'publish_ok' => false], 'publish_failed', 'publish_failed');
verdict('publicacion desconocida', ['action' => 'ON', 'publish_ok' => null], 'indeterminate', 'publish_unknown');

verdict(
    'telemetria fresco coincide',
    ['action' => 'ON', 'publish_ok' => true, 'reported_state' => 'ON', 'fresh' => true],
    'confirmed',
    'telemetry_match'
);
verdict(
    'telemetria fresco y humano en contra',
    ['action' => 'ON', 'publish_ok' => true, 'reported_state' => 'ON', 'fresh' => true, 'human_state' => 'OFF'],
    'discrepant',
    'human_disagrees'
);
verdict(
    'telemetria fresco no aplicado',
    ['action' => 'ON', 'publish_ok' => true, 'reported_state' => 'OFF', 'fresh' => true],
    'discrepant',
    'not_applied'
);
verdict(
    'telemetria fresco no aplicado pero humano confirma',
    ['action' => 'ON', 'publish_ok' => true, 'reported_state' => 'OFF', 'fresh' => true, 'human_state' => 'ON'],
    'discrepant',
    'telemetry_disagrees'
);
verdict(
    'humano confirma sin telemetria',
    ['action' => 'OFF', 'publish_ok' => true, 'reported_state' => 'ON', 'fresh' => false, 'human_state' => 'OFF'],
    'confirmed',
    'human_confirmed'
);
verdict(
    'humano dice que no cambio',
    ['action' => 'ON', 'publish_ok' => true, 'reported_state' => 'OFF', 'fresh' => false, 'human_state' => 'OFF'],
    'unconfirmed',
    'human_reports_no_change'
);
verdict(
    'sin telemetria ni humano',
    ['action' => 'ON', 'publish_ok' => true, 'reported_state' => 'ON', 'fresh' => false],
    'unconfirmed',
    'no_confirmation'
);
verdict(
    'estado anterior coincidente NO confirma',
    ['action' => 'OFF', 'publish_ok' => true, 'reported_state' => 'OFF', 'fresh' => false],
    'unconfirmed',
    'no_confirmation'
);
verdict(
    'fresco pero estado desconocido',
    ['action' => 'ON', 'publish_ok' => true, 'reported_state' => 'UNKNOWN', 'fresh' => true],
    'unconfirmed',
    'no_confirmation'
);
verdict(
    'fresco sin estado reportado',
    ['action' => 'ON', 'publish_ok' => true, 'fresh' => true],
    'unconfirmed',
    'no_confirmation'
);
verdict(
    'estado en minusculas',
    ['action' => 'ON', 'publish_ok' => true, 'reported_state' => 'on', 'fresh' => true],
    'confirmed',
    'telemetry_match'
);

// ── cableado y no regresion ──────────────────────────────────────────
$controller = (string) file_get_contents($api . '/src/Http/Controllers/SensorWizardController.php');
truthy('controlador usa el evaluador', str_contains($controller, 'SwitchCommandEvidence::evaluate'));
truthy('controlador persiste evidencia', str_contains($controller, 'SwitchCommandEvidenceRepository'));
truthy('controlador reutiliza operation_id', str_contains($controller, 'findByOperationId'));
truthy('controlador responde idempotente', str_contains($controller, "'idempotent'"));
truthy('controlador expone diagnostics', str_contains($controller, 'switchDiagnostics'));
truthy('controlador exporta markdown', str_contains($controller, 'diagnosticsMarkdown'));
truthy('controlador genera ms utc', str_contains($controller, 'nowUtcMs'));
truthy('controlador mantiene reachability_source', str_contains($controller, "'reachability_source'"));
truthy('controlador mantiene pending_confirmation', str_contains($controller, "'pending_confirmation'"));
truthy(
    'controlador no usa gateway Tuya en el diagnostico',
    !str_contains($controller, 'TuyaSwitchGateway') && !str_contains($controller, 'tuyaPresenceApi')
);

$routes = (string) file_get_contents($api . '/public/index.php');
truthy('ruta diagnostics', str_contains($routes, '/switch/diagnostics'));
truthy('ruta export', str_contains($routes, '/switch/diagnostics/export'));
truthy('ruta switch/test conservada', str_contains($routes, '/switch/test'));

$migration = (string) file_get_contents($api . '/migrations/0140_switch_command_evidence.sql');
truthy('migracion crea tabla', str_contains($migration, 'CREATE TABLE IF NOT EXISTS `switch_command_evidence`'));
truthy('migracion unique operation_id', str_contains($migration, 'UNIQUE KEY `uq_switch_evidence_operation`'));
truthy('migracion indices de consulta', str_contains($migration, 'idx_switch_evidence_room_time'));
truthy('migracion guarda veredicto', str_contains($migration, '`verdict`'));
truthy('migracion separa humano y telemetria', str_contains($migration, '`human_state`') && str_contains($migration, '`reported_state`'));

$repo = (string) file_get_contents($api . '/src/Domain/Sensors/SwitchCommandEvidenceRepository.php');
truthy('repositorio idempotente', str_contains($repo, 'INSERT IGNORE'));
truthy('repositorio actualiza veredicto', str_contains($repo, 'verdict = :verdict'));
truthy('repositorio purga por fecha', str_contains($repo, 'purgeBefore'));

$service = (string) file_get_contents($api . '/src/Infrastructure/Sensors/SwitchDiagnosticsService.php');
truthy('diagnostico declara fuentes', str_contains($service, 'zigbee_services') && str_contains($service, 'zigbee2mqtt_log'));
truthy('diagnostico declara ausencias', str_contains($service, 'unavailable'));
truthy('diagnostico no publica ordenes', !str_contains($service, 'turnOnForDevice') && !str_contains($service, 'turnOffForDevice'));
truthy('diagnostico no sondea el dispositivo', !str_contains($service, 'probeForDevice'));
truthy('diagnostico sin Tuya', stripos($service, 'tuya') === false);

$retention = (string) file_get_contents($api . '/bin/switch-evidence-retention.php');
truthy('retencion permite desactivar', str_contains($retention, 'days <= 0'));
truthy('retencion usa env', str_contains($retention, 'SWITCH_EVIDENCE_RETENTION_DAYS'));
truthy('retencion soporta dry-run', str_contains($retention, 'dry-run'));
truthy('retencion sin Tuya', stripos($retention, 'tuya') === false);

truthy('unit retention existe', is_file($api . '/../docs/systemd/cerraduras-switch-evidence-retention.service'));
truthy('timer retention existe', is_file($api . '/../docs/systemd/cerraduras-switch-evidence-retention.timer'));
$startAll = (string) file_get_contents($api . '/../start-all.sh');
$stopAll = (string) file_get_contents($api . '/../stop-all.sh');
truthy('start-all habilita el timer', str_contains($startAll, 'cerraduras-switch-evidence-retention.timer'));
truthy('stop-all para el timer', str_contains($stopAll, 'cerraduras-switch-evidence-retention.timer'));

$gateway = (string) file_get_contents($api . '/src/Infrastructure/Gateways/Switch/ZigbeeSwitchGateway.php');
truthy('gateway no persiste evidencia', !str_contains($gateway, 'switch_command_evidence'));
truthy('gateway conserva pending_confirmation', str_contains($gateway, 'pending_confirmation'));

$planner = (string) file_get_contents($api . '/src/Domain/Sensors/RadarCalibrationPlanner.php');
truthy('radar planner intacto (sin evidencia de luz)', !str_contains($planner, 'switch_command_evidence'));
$exterior = (string) file_get_contents($api . '/src/Domain/Sensors/RadarExteriorPresenceEvaluator.php');
truthy('evaluador exterior intacto', !str_contains($exterior, 'switch_command_evidence'));

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
