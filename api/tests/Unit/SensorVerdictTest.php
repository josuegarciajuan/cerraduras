<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Sensors\SensorVerdict;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }

function run(array $statuses, string $soak = 'PENDING', array $plan = ['door', 'radar_inside', 'radar_outside', 'light']): array
{
    $steps = [];
    foreach ($statuses as $id => $st) $steps[$id] = ['status' => $st];
    return ['plan' => $plan, 'steps' => $steps, 'soak' => ['status' => $soak]];
}

echo "Sensor verdict (F95/RF-167)\n";
$all = run(['door' => 'PASS', 'radar_inside' => 'PASS', 'radar_outside' => 'PASS', 'light' => 'PASS']);
same('todo PASS + soak PASS → CORRECT', SensorVerdict::compute(run([
    'door' => 'PASS', 'radar_inside' => 'PASS', 'radar_outside' => 'PASS', 'light' => 'PASS'], 'PASS'), [])['verdict'], 'CORRECT');
same('todo PASS + soak pendiente → WITH_RESERVES', SensorVerdict::compute($all, [])['verdict'], 'WITH_RESERVES');
same('un FAIL → REVIEW', SensorVerdict::compute(run([
    'door' => 'PASS', 'radar_inside' => 'FAIL', 'radar_outside' => 'PASS', 'light' => 'PASS'], 'PASS'), [])['verdict'], 'REVIEW');
same('un UNVERIFIED → INCOMPLETE', SensorVerdict::compute(run([
    'door' => 'PASS', 'radar_inside' => 'UNVERIFIED', 'radar_outside' => 'PASS', 'light' => 'PASS'], 'PASS'), [])['verdict'], 'INCOMPLETE');
same('SKIP no cuenta como PASS', SensorVerdict::compute(run([
    'door' => 'SKIP', 'radar_inside' => 'PASS', 'radar_outside' => 'PASS', 'light' => 'PASS'], 'PASS'), [])['verdict'], 'INCOMPLETE');
same('sin luz instalada → WITH_RESERVES', SensorVerdict::compute(run([
    'door' => 'PASS', 'radar_inside' => 'PASS', 'radar_outside' => 'PASS'], 'PASS', ['door', 'radar_inside', 'radar_outside']), [])['verdict'], 'WITH_RESERVES');
same('soak FAIL → REVIEW', SensorVerdict::compute(run([
    'door' => 'PASS', 'radar_inside' => 'PASS', 'radar_outside' => 'PASS', 'light' => 'PASS'], 'FAIL'), [])['verdict'], 'REVIEW');
same('todo pendiente → NOT_EVALUABLE', SensorVerdict::compute(run([]), [])['verdict'], 'NOT_EVALUABLE');
$devices = SensorVerdict::compute(run([
    'door' => 'PASS', 'radar_inside' => 'PASS', 'radar_outside' => 'PASS', 'light' => 'NOT_INSTALLED'], 'PASS'), [])['devices'];
same('radar combina dentro/fuera', $devices['presence'], 'PASS');
same('luz no instalada se refleja', $devices['light'], 'NOT_INSTALLED');
echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
