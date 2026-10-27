<?php
declare(strict_types=1);

/**
 * F91/TSK-91.10 — local channel hardening invariants.
 *
 * Run:
 *   php tests/Unit/MotionEndpointHardeningTest.php
 */

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }
function check(string $m, bool $c): void { $c ? pass($m) : fail($m); }

$index = file_get_contents(__DIR__ . '/../../public/index.php');
$worker = file_get_contents(__DIR__ . '/../../bin/camera-motion-worker.js');
$queue = file_get_contents(__DIR__ . '/../../src/Infrastructure/Zigbee/ZigbeeSpoolQueue.php');
$ingress = file_get_contents(__DIR__ . '/../../bin/zigbee-ingress/index.js');
$publish = file_get_contents(__DIR__ . '/../../bin/zigbee-ingress/publish.js');
$env = file_get_contents(__DIR__ . '/../../.env.example');
$gitignore = file_get_contents(__DIR__ . '/../../../.gitignore');
$map = file_get_contents(__DIR__ . '/../../config/zigbee2mqtt-devices.json');

echo "Local channel hardening (F91/TSK-91.10)\n";
echo str_repeat("=", 60) . "\n\n";

check('motion exige token o loopback directo', str_contains($index, 'ALMACEN_MOTION_TOKEN')
    && str_contains($index, 'hash_equals') && str_contains($index, 'motion_auth_required'));
check('motion rechaza proxy sin token', str_contains($index, 'HTTP_X_FORWARDED_FOR') && str_contains($index, 'HTTP_X_REAL_IP'));
check('motion responde 403', str_contains($index, "json(403, ['ok' => false, 'error' => 'motion_forbidden']"));
check('worker envía el token por header', str_contains($worker, 'X-Almacen-Motion-Token') && str_contains($worker, 'MOTION_TOKEN'));
check('token declarado en .env.example', str_contains($env, 'ALMACEN_MOTION_TOKEN='));

check('spool dirs 0700', str_contains($queue, '@chmod($path, 0700)') || str_contains($queue, 'mkdir($path, 0700, true)'));
check('spool ficheros 0600', str_contains($queue, '@chmod($tmp, 0600)') && str_contains($ingress, 'mode: 0o600'));
check('replay auditado', str_contains($queue, 'replay.log') && str_contains($queue, 'replayed_by'));
check('replay exige confirmación', str_contains(file_get_contents(__DIR__ . '/../../bin/zigbee-spool-worker.php'), '--requeue requiere --yes'));
check('api/run ignorado por Git', str_contains($gitignore, '/api/run/'));

check('ningún secreto en el mapa MQTT', !preg_match('/password|secret|token|api_key|private_key/i', $map));
check('publish no imprime contraseña', !preg_match('/console\.(log|error)\([^)]*password/i', $publish));
check('ingress no imprime contraseña', !preg_match('/console\.(log|error)\([^)]*password/i', $ingress));
check('credenciales no viajan en argv del publicador', !preg_match('/process\.argv\[[0-9]+\][^;]*(PASSWORD|password)/i', $publish));

echo "\nTotal: {$passed} passed, {$failed} failed\n\n";
exit($failed === 0 ? 0 : 1);
