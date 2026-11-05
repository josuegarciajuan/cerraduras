<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Warehouse\WarehouseLightDecision;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }

echo "Warehouse light decision\n";

same('PRESENT aplicado → ON', WarehouseLightDecision::decide('PRESENCE', 'PRESENT', true, 'PRESENT'), 'ON');
same('ABSENT aplicado → OFF', WarehouseLightDecision::decide('PRESENCE', 'ABSENT', true, 'ABSENT'), 'OFF');
same('PRESENT candidato → sin cambio', WarehouseLightDecision::decide('PRESENCE', 'PRESENT', false, 'ABSENT'), null);
same('OPEN aplicado → ON', WarehouseLightDecision::decide('PROXIMITY', 'OPEN', true, 'PRESENT'), 'ON');
same('CLOSED con ABSENT → OFF', WarehouseLightDecision::decide('PROXIMITY', 'CLOSED', true, 'ABSENT'), 'OFF');
same('CLOSED con PRESENT → sin cambio', WarehouseLightDecision::decide('PROXIMITY', 'CLOSED', true, 'PRESENT'), null);
same('ON ya encendida → sin comando', WarehouseLightDecision::decide('PRESENCE', 'PRESENT', true, 'PRESENT', 'ON'), null);
same('OFF ya apagada → sin comando', WarehouseLightDecision::decide('PRESENCE', 'ABSENT', true, 'ABSENT', 'OFF'), null);
same('estado UNKNOWN + ON → ON', WarehouseLightDecision::decide('PRESENCE', 'PRESENT', true, 'PRESENT', 'UNKNOWN'), 'ON');
same('sensor desconocido → sin cambio', WarehouseLightDecision::decide('OTHER', 'OPEN', true, 'PRESENT'), null);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
