<?php
declare(strict_types=1);

/**
 * Unit tests for camera device kind + subtype and warehouse room-type windows
 * (F60, RF-67.2 / RF-68.1/68.2).
 *
 * Pure logic only: no DB, no HTTP. The error-code paths of
 * DeviceService::validateSubtype are exercised end-to-end in runner BLOCK 44.
 *
 * Run:
 *   php tests/Unit/DeviceCameraSubtypeTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Domain\Rooms\RoomType;

$passed = 0;
$failed = 0;

function pass(string $msg): void
{
    global $passed;
    $passed++;
    echo "  ✅ {$msg}\n";
}

function fail(string $msg): void
{
    global $failed;
    $failed++;
    echo "  ❌ {$msg}\n";
}

echo "DeviceCameraSubtype / RoomType windows Unit Tests\n";
echo str_repeat("=", 60) . "\n\n";

// ── 1. CAMERA is part of allKinds ────────────────────────────────────────
if (in_array(Device::KIND_CAMERA, Device::allKinds(), true)) {
    pass('allKinds() incluye CAMERA');
} else {
    fail('allKinds() NO incluye CAMERA');
}
if (Device::KIND_CAMERA === 'CAMERA') {
    pass("KIND_CAMERA === 'CAMERA'");
} else {
    fail('KIND_CAMERA no vale CAMERA');
}

// ── 2. cameraPositions ───────────────────────────────────────────────────
$positions = Device::cameraPositions();
if ($positions === ['EXTERIOR', 'INTERIOR']) {
    pass('cameraPositions() = [EXTERIOR, INTERIOR]');
} else {
    fail('cameraPositions() inesperado: ' . json_encode($positions));
}

// ── 3. Subtype matrix (pure) ─────────────────────────────────────────────
$matrix = [
    // [kind, subtype, expected]
    ['CAMERA', 'EXTERIOR', true],
    ['CAMERA', 'INTERIOR', true],
    ['CAMERA', 'exterior', false],   // case-sensitive
    ['CAMERA', 'SALON', false],
    ['CAMERA', '', false],
    ['CAMERA', null, false],
    ['RPI', null, true],
    ['RPI', '', true],
    ['RPI', 'EXTERIOR', false],      // non-camera must not carry subtype
    ['PRESENCE', 'INTERIOR', false],
    ['LOCK', null, true],
];
foreach ($matrix as [$kind, $subtype, $expected]) {
    $got = Device::isValidSubtype($kind, $subtype);
    $label = $kind . '/' . var_export($subtype, true);
    if ($got === $expected) {
        pass("isValidSubtype({$label}) === " . var_export($expected, true));
    } else {
        fail("isValidSubtype({$label}) === " . var_export($got, true) . ", esperado " . var_export($expected, true));
    }
}

// ── 4. Device hydrates subtype and exposes it in toArray ─────────────────
$cam = new Device(7, 5, Device::KIND_CAMERA, 'CAM-ALM-EXT', 'Cámara pasillo', null, null, null, false, null, 'EXTERIOR');
$arr = $cam->toArray();
if (($arr['subtype'] ?? null) === 'EXTERIOR' && $arr['kind'] === 'CAMERA') {
    pass("toArray() incluye subtype=EXTERIOR");
} else {
    fail('toArray() no incluye subtype: ' . json_encode($arr));
}
$nonCam = new Device(8, 5, Device::KIND_RPI, '92f57630', null, null, null);
$nonCamArr = $nonCam->toArray();
if (array_key_exists('subtype', $nonCamArr) && $nonCamArr['subtype'] === null) {
    pass('Device no-cámara expone subtype=null');
} else {
    fail('Device no-cámara subtype inesperado');
}

// ── 5. RoomType warehouse windows (defaults + toArray + override) ────────
$rt = new RoomType(1, 'ALMACEN_BEBIDAS', 'Almacén de bebidas', 5, 15, 20, 30, 90, 40);
$rtArr = $rt->toArray();
if ($rtArr['warehouse_confirm_seconds'] === 40 && $rtArr['warehouse_exterior_margin_seconds'] === 5) {
    pass('RoomType defaults X=40, M=5 y presentes en toArray()');
} else {
    fail('RoomType defaults inesperados: ' . json_encode($rtArr));
}
$rt2 = new RoomType(2, 'X', 'X', 5, 15, 20, 30, 90, 40, 25, 8);
if ($rt2->warehouseConfirmSeconds === 25 && $rt2->warehouseExteriorMarginSeconds === 8) {
    pass('RoomType acepta X/M personalizados (25/8)');
} else {
    fail('RoomType no acepta X/M personalizados');
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
