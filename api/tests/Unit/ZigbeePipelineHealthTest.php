<?php
declare(strict_types=1);

/**
 * F134 — salud del pipeline Zigbee.
 *
 * Pure logic: no systemd, no MQTT, no DB.
 *
 * Run: php tests/Unit/ZigbeePipelineHealthTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\ZigbeePipelineHealth;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function eq(string $m, mixed $a, mixed $e): void {
    $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }

echo "Zigbee pipeline health (F134)\n";
echo str_repeat('=', 60) . "\n";

$now = 1_800_000_000;
$ts = static fn (int $ago): string => gmdate('Y-m-d\TH:i:s\Z', $now - $ago);
$policy = new ZigbeePipelineHealth();

$base = [
    'profile' => 'prod',
    'enabled' => true,
    'active' => true,
    'connected' => true,
    'status_updated_at' => $ts(1),
    'last_event_at' => $ts(1),
    'last_processed_at' => $ts(1),
    'pending' => 0,
    'quarantine' => 0,
    'units' => ['ingress' => true, 'spool' => true],
];

$healthy = $policy->resolve($base, $now, 180);
eq('pipeline activo y conectado → ok', $healthy['state'], ZigbeePipelineHealth::STATE_OK);
check('pipeline sano → healthy', $healthy['healthy'] === true);
eq('sin motivo → reason null', $healthy['reason'], null);
eq('edad de estado calculada', $healthy['status_age_seconds'], 1);

$stopped = $policy->resolve(array_merge($base, ['active' => false]), $now, 180);
eq('systemd inactivo → stopped', $stopped['state'], ZigbeePipelineHealth::STATE_STOPPED);
check('pipeline parado → unhealthy', $stopped['healthy'] === false);
eq('motivo de parada', $stopped['reason'], 'service_inactive');
check('un connected:true viejo no oculta el parado', $stopped['connected'] === true);

$unknownActiveFresh = $policy->resolve(array_merge($base, [
    'active' => null,
    'status_updated_at' => $ts(5),
]), $now, 180);
eq('sin systemd pero estado fresco → ok', $unknownActiveFresh['state'], ZigbeePipelineHealth::STATE_OK);
check('fallback por estado fresco → healthy', $unknownActiveFresh['healthy'] === true);

$unknownActiveOld = $policy->resolve(array_merge($base, [
    'active' => null,
    'status_updated_at' => $ts(300),
]), $now, 180);
eq('sin systemd y estado viejo → stale', $unknownActiveOld['state'], ZigbeePipelineHealth::STATE_STALE);
eq('motivo de estado viejo', $unknownActiveOld['reason'], 'status_stale');
check('estado viejo → unhealthy', $unknownActiveOld['healthy'] === false);

$disconnected = $policy->resolve(array_merge($base, [
    'connected' => false,
]), $now, 180);
eq('proceso activo sin broker → disconnected', $disconnected['state'], ZigbeePipelineHealth::STATE_DISCONNECTED);
eq('motivo de desconexión', $disconnected['reason'], 'ingress_disconnected');
check('desconectado → unhealthy', $disconnected['healthy'] === false);

$noStatus = $policy->resolve(array_merge($base, [
    'active' => null,
    'connected' => false,
    'status_updated_at' => null,
    'last_event_at' => null,
]), $now, 180);
eq('sin estado y sin systemd → unknown', $noStatus['state'], ZigbeePipelineHealth::STATE_UNKNOWN);
eq('motivo sin estado', $noStatus['reason'], 'no_status');

$stalled = $policy->resolve(array_merge($base, [
    'pending' => 12,
    'status_updated_at' => $ts(400),
]), $now, 180);
eq('cola pendiente y estado viejo → stale', $stalled['state'], ZigbeePipelineHealth::STATE_STALE);
eq('motivo de cola parada', $stalled['reason'], 'queue_stalled');
check('cola parada → unhealthy', $stalled['healthy'] === false);

$quiet = $policy->resolve(array_merge($base, [
    'pending' => 0,
    'status_updated_at' => $ts(400),
    'last_event_at' => $ts(400),
]), $now, 180);
eq('pipeline activo y sin eventos sigue ok', $quiet['state'], ZigbeePipelineHealth::STATE_OK);
check('silencio sin cola no degrada el pipeline', $quiet['healthy'] === true);
eq('edad del último evento conservada', $quiet['last_event_age_seconds'], 400);

$disabled = $policy->resolve(array_merge($base, [
    'enabled' => false,
    'active' => false,
    'connected' => false,
]), $now, 180);
eq('flags apagados → disabled', $disabled['state'], ZigbeePipelineHealth::STATE_DISABLED);
check('disabled no degrada la salud', $disabled['healthy'] === true);

$quarantine = $policy->resolve(array_merge($base, ['quarantine' => 3]), $now, 180);
eq('cuarentena se expone', $quarantine['quarantine'], 3);
eq('cuarentena no cambia el estado del pipeline', $quarantine['state'], ZigbeePipelineHealth::STATE_OK);

$dev = $policy->resolve(array_merge($base, ['profile' => 'dev']), $now, 180);
eq('perfil dev conservado', $dev['profile'], 'dev');

echo "\nTotal: {$P} passed, {$F} failed\n\n";
exit($F === 0 ? 0 : 1);
