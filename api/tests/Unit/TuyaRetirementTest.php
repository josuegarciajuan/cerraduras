<?php
declare(strict_types=1);

/**
 * F122 — Tuya retirement guards.
 *
 * Run:
 *   php tests/Unit/TuyaRetirementTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceLivenessService;
use App\Infrastructure\Gateways\Switch\UnsupportedSwitchGateway;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  OK {$m}\n"; }
function ko(string $m, string $d = ''): void { global $F; $F++; echo "  FAIL {$m}" . ($d ? " — {$d}" : '') . "\n"; }

$root = dirname(__DIR__, 2);

// 1. No Tuya source/script files remain.
$hits = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
foreach ($it as $file) {
    if ($file->isFile() && stripos($file->getFilename(), 'tuya') !== false) {
        $hits[] = $file->getPathname();
    }
}
$hits === [] ? ok('sin ficheros Tuya en src') : ko('ficheros Tuya en src', implode(', ', $hits));

$binHits = array_values(array_filter(glob($root . '/bin/tuya-*') ?: [], 'is_file'));
$binHits === [] ? ok('sin scripts bin/tuya-*') : ko('scripts Tuya en bin', implode(', ', $binHits));

// 2. No cloud markers in executable source.
$markers = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $src = (string) file_get_contents($file->getPathname());
    foreach (['TUYA_ACCESS', 'openapi.tuya', 'tuyaPresenceApi', 'TuyaSensorIngress', 'TuyaSwitchGateway', 'TuyaLockGateway'] as $m) {
        if (str_contains($src, $m)) $markers[] = basename($file->getPathname()) . ':' . $m;
    }
}
$markers === [] ? ok('sin marcadores de nube Tuya en src') : ko('marcadores Tuya en src', implode(', ', $markers));

// 3. Device retirement helpers.
$retired = new Device(1, 1, Device::KIND_PRESENCE, 'x', null, null, ['retired_at' => '2026-11-09T00:00:00Z'], null, false, null, null, 'TUYA');
$active = new Device(2, 1, Device::KIND_PROXIMITY, 'y', null, null, null, null, false, null, null, 'ZIGBEE2MQTT');
$retired->isRetired() === true ? ok('isRetired() detecta retired_at') : ko('isRetired() falla');
$active->isRetired() === false ? ok('isRetired() false en dispositivo activo') : ko('isRetired() falso positivo');
$retired->isTuyaTransport() === true ? ok('isTuyaTransport() detecta TUYA') : ko('isTuyaTransport() falla');

// 4. Liveness classifies retired and never probes.
$svc = new DeviceLivenessService();
$st = $svc->resolve(['kind' => 'PRESENCE', 'transport' => 'TUYA'], time());
$st['state'] === 'retired' && $st['online'] === null && $st['source'] === 'retired'
    ? ok('liveness retirada sin sonda')
    : ko('liveness retirada', json_encode($st));

// 5. Unsupported switch gateway fails closed.
$gw = new UnsupportedSwitchGateway('device_retired');
$r = $gw->turnOn(1);
$r['ok'] === false && $r['provider'] === 'RETIRED' && $r['error'] === 'device_retired'
    ? ok('switch retirado falla cerrado')
    : ko('switch retirado', json_encode($r));

// 6. Retired row survives with its pack (logical deactivation, no delete).
$sql = (string) file_get_contents($root . '/migrations/0147_retire_tuya_devices.sql');
str_contains($sql, 'retired_at') && !str_contains($sql, 'DELETE') && !str_contains($sql, 'pack_id =')
    ? ok('migración 0147 es baja lógica y no toca packs')
    : ko('migración 0147', 'revisa retired_at/DELETE/pack_id');

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
