<?php
declare(strict_types=1);

/**
 * F91/TSK-91.13 — wiring, flags and readiness for the Zigbee runtime.
 *
 * Run:
 *   php tests/Unit/ZigbeeRuntimeWiringTest.php
 */

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }
function check(string $m, bool $c): void { $c ? pass($m) : fail($m); }

$root = dirname(__DIR__, 3);
$start = file_get_contents($root . '/start-all.sh');
$stop = file_get_contents($root . '/stop-all.sh');
$health = file_get_contents(__DIR__ . '/../../src/Http/Controllers/HealthController.php');
$worker = file_get_contents(__DIR__ . '/../../bin/zigbee-spool-worker.php');
$env = file_get_contents(__DIR__ . '/../../.env.example');

echo "Zigbee runtime wiring (F91/TSK-91.13)\n";
echo str_repeat("=", 60) . "\n\n";

foreach ([
    'cerraduras-zigbee-tunnel.service',
    'cerraduras-zigbee-ingress.service',
    'cerraduras-zigbee-spool.service',
] as $unit) {
    check("unidad {$unit}", is_file($root . '/docs/systemd/' . $unit));
}

check('start-all arranca ingress condicionalmente',
    str_contains($start, 'ZIGBEE_INGRESS_ENABLED') && str_contains($start, 'cerraduras-zigbee-ingress'));
check('start-all arranca spool condicionalmente',
    str_contains($start, 'ZIGBEE_SPOOL_ENABLED') && str_contains($start, 'cerraduras-zigbee-spool'));
check('stop-all detiene ingress/spool/túnel',
    str_contains($stop, 'cerraduras-zigbee-ingress')
    && str_contains($stop, 'cerraduras-zigbee-spool')
    && str_contains($stop, 'cerraduras-zigbee-tunnel'));
check('flags apagados por defecto', str_contains($env, 'ZIGBEE_INGRESS_ENABLED=false')
    && str_contains($env, 'ZIGBEE_SPOOL_ENABLED=false'));
check('worker spool respeta el flag', str_contains($worker, "Config::getBool('ZIGBEE_SPOOL_ENABLED'"));
check('health/deep expone bloque zigbee', str_contains($health, "checks['zigbee']")
    && str_contains($health, 'ingress_connected')
    && str_contains($health, 'spool_pending'));
check('readiness disponible', is_file(__DIR__ . '/../../bin/zigbee-readiness.php'));
check('instalador de dependencias Node', is_file(__DIR__ . '/../../bin/zigbee-install-deps.sh'));
check('sin poller Tuya', !is_file(__DIR__ . '/../../bin/tuya-presence-poller.js')
    && !is_file(__DIR__ . '/../../bin/presence-poller-manager.sh'));

echo "\nTotal: {$passed} passed, {$failed} failed\n\n";
exit($failed === 0 ? 0 : 1);
