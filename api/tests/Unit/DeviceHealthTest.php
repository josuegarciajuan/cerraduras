<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Sensors\DeviceHealth;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }

echo "Device health (F95/RF-163.4)\n";
check('radar espera push periódico', DeviceHealth::expectsPeriodicPush('PRESENCE'));
check('puerta no espera push periódico', !DeviceHealth::expectsPeriodicPush('PROXIMITY'));
check('luz no espera push periódico', !DeviceHealth::expectsPeriodicPush('SWITCH'));

$missing = DeviceHealth::classify(false, false, 'PROXIMITY', null);
same('ausente → missing', $missing['state'], 'missing');
same('ausente → rojo', $missing['tone'], 'bad');

$online = DeviceHealth::classify(true, true, 'PRESENCE', 2);
same('radar reciente → online', $online['state'], 'online');
same('radar online tono ok', $online['tone'], 'ok');
check('radar online espera periódico', $online['expects_periodic_push']);

$stale = DeviceHealth::classify(true, false, 'PRESENCE', 900);
same('radar sin push → stale', $stale['state'], 'stale');
same('radar stale → ámbar', $stale['tone'], 'warn');

$doorIdle = DeviceHealth::classify(true, false, 'PROXIMITY', 1800);
same('puerta sin actividad → idle', $doorIdle['state'], 'idle');
same('puerta idle → verde', $doorIdle['tone'], 'ok');
check('puerta idle NO es sin datos', $doorIdle['label'] !== 'SIN DATOS');
same('puerta idle detalle', $doorIdle['detail'], 'sin actividad hace 1800 s');
check('puerta idle no espera periódico', !$doorIdle['expects_periodic_push']);

$switchIdle = DeviceHealth::classify(true, false, 'SWITCH', 8000);
same('luz sin actividad → idle', $switchIdle['state'], 'idle');
same('luz idle → verde', $switchIdle['tone'], 'ok');

$doorOnline = DeviceHealth::classify(true, true, 'PROXIMITY', 0);
same('puerta reciente → online', $doorOnline['state'], 'online');

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
