<?php
declare(strict_types=1);

/**
 * F92/TSK-92.02: CameraReachabilityProbe (pure parsing, no network).
 *
 * Run:
 *   php tests/Unit/CameraReachabilityProbeTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\CameraReachabilityProbe;

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

function same(string $name, mixed $expected, mixed $got): void
{
    if ($expected === $got) {
        pass($name);
        return;
    }
    fail($name, 'expected=' . json_encode($expected) . ' got=' . json_encode($got));
}

same(
    'RTSP con credenciales → host/puerto, sin credenciales',
    ['host' => '172.16.51.219', 'port' => 554],
    CameraReachabilityProbe::parseRtsp('rtsp://admin:secret@172.16.51.219:554/cam/realmonitor?channel=1')
);
same(
    'RTSP sin puerto usa 554',
    ['host' => '10.0.0.7', 'port' => 554],
    CameraReachabilityProbe::parseRtsp('rtsp://10.0.0.7/stream')
);
same(
    'RTSPS sin puerto usa 322',
    ['host' => 'cam.local', 'port' => 322],
    CameraReachabilityProbe::parseRtsp('rtsps://cam.local/stream')
);
same(
    'RTSP mayúsculas y espacios',
    ['host' => 'cam.local', 'port' => 8554],
    CameraReachabilityProbe::parseRtsp('  RTSP://cam.local:8554/x  ')
);
same('URL vacía → null', null, CameraReachabilityProbe::parseRtsp(''));
same('Esquema HTTP → null', null, CameraReachabilityProbe::parseRtsp('http://10.0.0.1/stream'));
same('Sin host → null', null, CameraReachabilityProbe::parseRtsp('rtsp:///stream'));
same('Puerto inválido → null', null, CameraReachabilityProbe::parseRtsp('rtsp://host:70000/x'));

$probe = new CameraReachabilityProbe();
same('probe() con URL no RTSP no toca la red', null, $probe->probe('http://10.0.0.1/x'));
same('probe() con URL vacía no toca la red', null, $probe->probe(''));

echo "\n" . str_repeat('=', 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
