<?php
declare(strict_types=1);

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  OK {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  FAIL {$m}\n"; }
function truthy(string $m, bool $c): void { $c ? ok($m) : ko($m); }

$root = dirname(__DIR__, 3);
$runbookPath = $root . '/docs/ops/diagnostico-luz-zigbee.md';

echo "F112 documentation\n";

truthy('runbook existe', is_file($runbookPath));
$runbook = is_file($runbookPath) ? (string) file_get_contents($runbookPath) : '';

truthy('explica la tabla', str_contains($runbook, 'switch_command_evidence'));
truthy('explica veredictos', str_contains($runbook, 'unconfirmed') && str_contains($runbook, 'discrepant'));
truthy('separa publicado de confirmado', str_contains($runbook, 'no** significa que la lámpara cambió'));
truthy('documenta zona horaria', str_contains($runbook, 'CET') && str_contains($runbook, 'UTC'));
truthy('documenta consulta', str_contains($runbook, '/switch/diagnostics?room_id=14'));
truthy('documenta exportacion', str_contains($runbook, '/switch/diagnostics/export'));
truthy('documenta retencion', str_contains($runbook, 'SWITCH_EVIDENCE_RETENTION_DAYS'));
truthy('documenta fases de luz', str_contains($runbook, 'PREPARE_OFF') && str_contains($runbook, 'CONFIRM_OFF'));
truthy('prohibe reemparejar', str_contains($runbook, 'reemparejar'));
truthy('prohibe polling Tuya', str_contains($runbook, 'Tuya'));
truthy('declara causa no determinada', str_contains($runbook, 'Causa raíz: no determinada'));
truthy('recoge logs de oficina', str_contains($runbook, 'docker logs'));
truthy('no expone secretos', !preg_match('/\b(password|passwd|api_key|bearer|client_secret|private_key)\b/i', $runbook));

$agents = (string) file_get_contents($root . '/AGENTS.md');
truthy('AGENTS referencia el runbook', str_contains($agents, 'docs/ops/diagnostico-luz-zigbee.md'));
truthy('AGENTS advierte de no cortar antes', str_contains($agents, 'No cortar la alimentación'));
truthy('AGENTS menciona la tabla', str_contains($agents, 'switch_command_evidence'));

$sensores = (string) file_get_contents($root . '/docs/ops-sensores.md');
truthy('ops-sensores enlaza el runbook', str_contains($sensores, 'diagnostico-luz-zigbee.md'));
truthy('ops-sensores describe encendido automatico', str_contains($sensores, 'se enciende automáticamente'));

$zigbee = (string) file_get_contents($root . '/docs/ops-zigbee.md');
truthy('ops-zigbee enlaza el runbook', str_contains($zigbee, 'diagnostico-luz-zigbee.md'));
truthy('ops-zigbee advierte antes de cortar', str_contains($zigbee, 'Antes de cortar la alimentación'));

$requirements = (string) file_get_contents($root . '/.specs/specs/requirements.md');
truthy('requisitos marcan bloques 1-4', str_contains($requirements, 'bloques 1–4 completados'));
$tasks = (string) file_get_contents($root . '/.specs/specs/tasks.md');
truthy('tarea de documentacion cerrada', str_contains($tasks, '- [x] TSK-F112-11'));

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
