<?php
declare(strict_types=1);

/**
 * F92/TSK-92.01: DeviceLivenessService (pure policy, no network).
 *
 * Run:
 *   php tests/Unit/DeviceLivenessServiceTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceLivenessService;

$passed = 0;
$failed = 0;

function pass(string $msg): void
{
    global $passed;
    $passed++;
    echo "  ✅ {$msg}\n";
}

function fail(string $msg, string $detail = ''): void
{
    global $failed;
    $failed++;
    echo "  ❌ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$svc = new DeviceLivenessService();
$now = 1_700_000_000;

function ago(int $now, int $seconds): string
{
    return gmdate('Y-m-d H:i:s', $now - $seconds);
}

function check(string $name, array $expected, array $got): void
{
    if ($got === $expected) {
        pass($name);
        return;
    }
    fail($name, 'expected=' . json_encode($expected) . ' got=' . json_encode($got));
}

// ── Categorías (F122: Tuya retirado) ────────────────────────────────────────
check(
    'Zigbee PROXIMITY es zigbee',
    ['zigbee'],
    [$svc->category(['kind' => 'PROXIMITY', 'transport' => 'ZIGBEE2MQTT'])]
);
check(
    'TUYA PROXIMITY queda retirado',
    ['retired'],
    [$svc->category(['kind' => 'PROXIMITY', 'transport' => 'TUYA'])]
);
check(
    'Cámara tiene categoría propia',
    ['camera'],
    [$svc->category(['kind' => 'CAMERA', 'transport' => null])]
);
check(
    'Legacy NULL PROXIMITY sin marca queda other (no se sondea)',
    ['other'],
    [$svc->category(['kind' => 'PROXIMITY', 'transport' => null])]
);
check(
    'Legacy NULL RPI es local',
    ['local'],
    [$svc->category(['kind' => 'RPI', 'transport' => null])]
);
check(
    'retired_at fuerza categoría retirada',
    ['retired'],
    [$svc->category(['kind' => 'PRESENCE', 'transport' => null, 'meta' => ['retired_at' => '2026-11-09T00:00:00Z']])]
);

// ── Cámara ────────────────────────────────────────────────────────────────
$camBase = [
    'kind' => 'CAMERA',
    'transport' => null,
    'meta' => ['enabled' => true, 'rtsp_url' => 'rtsp://u:p@10.0.0.5:554/stream'],
];
check(
    'Cámara sin sonda → unknown/not_probed',
    ['state' => 'unknown', 'online' => null, 'source' => 'camera_probe', 'reason' => 'not_probed'],
    $svc->resolve($camBase, $now)
);
check(
    'Cámara con sonda fresca online',
    ['state' => 'online', 'online' => true, 'source' => 'camera_probe', 'reason' => null],
    $svc->resolve(array_merge($camBase, [
        'online_state' => 1,
        'online_probed_at' => ago($now, 30),
    ]), $now)
);
check(
    'Cámara con sonda fresca offline',
    ['state' => 'offline', 'online' => false, 'source' => 'camera_probe', 'reason' => null],
    $svc->resolve(array_merge($camBase, [
        'online_state' => 0,
        'online_probed_at' => ago($now, 30),
    ]), $now)
);
check(
    'Cámara con sonda caducada → unknown',
    ['state' => 'unknown', 'online' => null, 'source' => 'camera_probe', 'reason' => 'not_probed'],
    $svc->resolve(array_merge($camBase, [
        'online_state' => 1,
        'online_probed_at' => ago($now, 601),
    ]), $now)
);
check(
    'Cámara deshabilitada → unknown/disabled',
    ['state' => 'unknown', 'online' => null, 'source' => 'camera_probe', 'reason' => 'disabled'],
    $svc->resolve(array_merge($camBase, ['meta' => ['enabled' => false, 'rtsp_url' => 'rtsp://x']]), $now)
);
check(
    'Cámara sin rtsp_url → unknown/no_rtsp_url',
    ['state' => 'unknown', 'online' => null, 'source' => 'camera_probe', 'reason' => 'no_rtsp_url'],
    $svc->resolve(array_merge($camBase, ['meta' => ['enabled' => true]]), $now)
);

// ── Zigbee ────────────────────────────────────────────────────────────────
$zigBase = [
    'kind' => 'PROXIMITY',
    'transport' => 'ZIGBEE2MQTT',
    'last_seen_at' => ago($now, 3600),
    'integration_status' => ['status' => 'operational', 'reason' => null],
];
check(
    'Zigbee operational con last_seen antiguo → online (edge-triggered)',
    ['state' => 'online', 'online' => true, 'source' => 'zigbee_push', 'reason' => null],
    $svc->resolve($zigBase, $now)
);
check(
    'Zigbee presence degraded → unknown/stale',
    ['state' => 'unknown', 'online' => null, 'source' => 'zigbee_push', 'reason' => 'stale'],
    $svc->resolve([
        'kind' => 'PRESENCE',
        'transport' => 'ZIGBEE2MQTT',
        'last_seen_at' => ago($now, 400),
        'integration_status' => ['status' => 'degraded', 'reason' => 'stale'],
    ], $now)
);
check(
    'Zigbee waiting_signal → unknown/no_signal',
    ['state' => 'unknown', 'online' => null, 'source' => 'zigbee_push', 'reason' => 'no_signal'],
    $svc->resolve([
        'kind' => 'PROXIMITY',
        'transport' => 'ZIGBEE2MQTT',
        'last_seen_at' => null,
        'integration_status' => ['status' => 'waiting_signal', 'reason' => 'no_signal'],
    ], $now)
);
check(
    'Zigbee availability offline prevalece',
    ['state' => 'offline', 'online' => false, 'source' => 'zigbee_availability', 'reason' => null],
    $svc->resolve([
        'kind' => 'PROXIMITY',
        'transport' => 'ZIGBEE2MQTT',
        'last_seen_at' => ago($now, 10),
        'online_state' => 0,
        'online_probed_at' => ago($now, 20),
        'integration_status' => ['status' => 'operational', 'reason' => null],
    ], $now)
);
check(
    'Zigbee availability online prevalece',
    ['state' => 'online', 'online' => true, 'source' => 'zigbee_availability', 'reason' => null],
    $svc->resolve([
        'kind' => 'PRESENCE',
        'transport' => 'ZIGBEE2MQTT',
        'last_seen_at' => ago($now, 400),
        'online_state' => 1,
        'online_probed_at' => ago($now, 20),
        'integration_status' => ['status' => 'degraded', 'reason' => 'stale'],
    ], $now)
);
check(
    'Zigbee sin integration_status y presence fresca → online',
    ['state' => 'online', 'online' => true, 'source' => 'zigbee_push', 'reason' => null],
    $svc->resolve([
        'kind' => 'PRESENCE',
        'transport' => 'ZIGBEE2MQTT',
        'last_seen_at' => ago($now, 5),
    ], $now)
);

// ── Tuya retirado ──────────────────────────────────────────────────────────
check(
    'Tuya legacy PROXIMITY con push fresco → retired',
    ['state' => 'retired', 'online' => null, 'source' => 'retired', 'reason' => 'tuya_retired'],
    $svc->resolve([
        'kind' => 'PROXIMITY',
        'transport' => 'TUYA',
        'last_seen_at' => ago($now, 30),
    ], $now)
);
check(
    'Tuya retirado con retired_at → retired',
    ['state' => 'retired', 'online' => null, 'source' => 'retired', 'reason' => 'tuya_retired'],
    $svc->resolve([
        'kind' => 'PRESENCE',
        'transport' => null,
        'meta' => ['retired_at' => '2026-11-09T00:00:00Z'],
    ], $now)
);

// ── Local / ESP32 ─────────────────────────────────────────────────────────
check(
    'Local con heartbeat fresco → online',
    ['state' => 'online', 'online' => true, 'source' => 'esp32_heartbeat', 'reason' => null],
    $svc->resolve(['kind' => 'RPI', 'transport' => null, 'last_seen_at' => ago($now, 30)], $now)
);
check(
    'Local con heartbeat antiguo → offline',
    ['state' => 'offline', 'online' => false, 'source' => 'esp32_heartbeat', 'reason' => null],
    $svc->resolve(['kind' => 'RPI', 'transport' => null, 'last_seen_at' => ago($now, 601)], $now)
);
check(
    'Local sin last_seen → unknown',
    ['state' => 'unknown', 'online' => null, 'source' => 'none', 'reason' => 'no_signal'],
    $svc->resolve(['kind' => 'RPI', 'transport' => null, 'last_seen_at' => null], $now)
);

echo "\n" . str_repeat('=', 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
