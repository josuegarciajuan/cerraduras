<?php
declare(strict_types=1);

/**
 * F91/TSK-91.09 — static contract for the Zigbee warehouse panel.
 *
 * Run:
 *   php tests/Unit/PanelAlmacenZigbeeUiTest.php
 */

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }
function check(string $m, bool $c): void { $c ? pass($m) : fail($m); }

$html = file_get_contents(__DIR__ . '/../../public/almacen.html');
$js = file_get_contents(__DIR__ . '/../../public/assets/almacen.js');
$logic = file_get_contents(__DIR__ . '/../../public/assets/croquis-logic.js');
$api = file_get_contents(__DIR__ . '/../../src/Http/Controllers/WarehouseStateController.php');
$rooms = file_get_contents(__DIR__ . '/../../public/index.php');

echo "Panel /almacen Zigbee (F91/TSK-91.09)\n";
echo str_repeat("=", 60) . "\n\n";

check('selector de sala', str_contains($html, 'id="wh-room-select"'));
check('badge piloto Zigbee', str_contains($html, 'id="wh-pilot"') && str_contains($html, 'ZIGBEE · PILOTO'));
check('control manual de luz', str_contains($html, 'id="croquis-light-controls"') && str_contains($html, "Almacen.setLight('ON')"));
check('resultado de luz accesible', str_contains($html, 'id="croquis-light-result" aria-live="polite"'));
check('evidencia de presencia', str_contains($html, 'id="croquis-evidence"'));
check('salud del buffer', str_contains($html, 'id="croquis-buffer"'));

check('cambio de sala JS', str_contains($js, 'function switchRoom('));
check('carga de salas JS', str_contains($js, 'function loadRooms('));
check('setLight JS', str_contains($js, 'function setLight('));
check('detección Zigbee JS', str_contains($js, 'function isZigbeeRoom('));
check('refresco Tuya bloqueado en Zigbee', str_contains($js, "toast('Zigbee: no se consulta Tuya')"));
check('recuperación de puerta no aplica a Zigbee', str_contains($js, 'if (isZigbeeRoom()) { return; }'));
check('evidencia renderizada', str_contains($js, 'function renderEvidence('));
check('buffer renderizado', str_contains($js, 'function renderBuffer('));
check('control de luz renderizado', str_contains($js, 'function renderLightControls('));
check('sin polling nuevo de sensores', !str_contains($js, 'setInterval(refreshSensors'));

check('candidato visible en croquis', str_contains($logic, 'PRESENCIA NO CONFIRMADA'));
check('confirmado visible en croquis', str_contains($logic, 'PRESENCIA CONFIRMADA'));

check('estado expone transports/is_zigbee', str_contains($api, "'is_zigbee'") && str_contains($api, "'transports'"));
check('estado expone presencia_mode/decision', str_contains($api, "'presence_mode'") && str_contains($api, "'presence_decision'"));
check('estado expone buffer', str_contains($api, 'bufferStatus'));
check('rooms expone tipo de sala', str_contains($rooms, 'room_type_code'));

echo "\nTotal: {$passed} passed, {$failed} failed\n\n";
exit($failed === 0 ? 0 : 1);
