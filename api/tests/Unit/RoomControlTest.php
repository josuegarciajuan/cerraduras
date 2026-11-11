<?php
declare(strict_types=1);

/**
 * F126 — static contract checks for the remote room control API/UI.
 *
 * Run: php tests/Unit/RoomControlTest.php
 */

$passed = 0;
$failed = 0;
function checkRoomControl(bool $cond, string $message): void
{
    global $passed, $failed;
    $cond ? $passed++ : $failed++;
    echo ($cond ? 'PASS ' : 'FAIL ') . $message . "\n";
}

$root = dirname(__DIR__, 3);
$routes = (string) file_get_contents($root . '/api/public/index.php');
$status = (string) file_get_contents($root . '/api/src/Domain/Rooms/RoomControlStatusService.php');
$reset = (string) file_get_contents($root . '/api/src/Domain/Rooms/RoomResetService.php');
$unlock = (string) file_get_contents($root . '/api/src/Domain/Rooms/RoomRemoteUnlockService.php');
$controller = (string) file_get_contents($root . '/api/src/Http/Controllers/RoomControlController.php');
$panel = (string) file_get_contents($root . '/api/public/panel/assets/panel-v2.js');
$dashboard = (string) file_get_contents($root . '/api/public/dashboard.html');

checkRoomControl(str_contains($routes, "'/api/v1/rooms/controls'")
    && str_contains($routes, "'/api/v1/rooms/{id}/status'")
    && str_contains($routes, "'/api/v1/rooms/{id}/light'"), 'rutas canónicas de estado/luz');
checkRoomControl(str_contains($routes, "'/api/v1/rooms/{id}/light/on'")
    && str_contains($routes, "'/api/v1/rooms/{id}/light/off'")
    && str_contains($routes, "'/api/v1/rooms/{id}/unlock'")
    && str_contains($routes, "'/api/v1/rooms/{id}/reset'"), 'rutas canónicas de acciones');
checkRoomControl(str_contains($routes, "'/dashboard-api/rooms/controls'")
    && str_contains($routes, "'/dashboard-api/rooms/{id}/status'")
    && str_contains($routes, "'/dashboard-api/rooms/{id}/light'")
    && str_contains($routes, "'/dashboard-api/rooms/{id}/unlock'")
    && str_contains($routes, "'/dashboard-api/rooms/{id}/reset'"), 'fachada LAN del dashboard');
checkRoomControl(str_contains($routes, 'rooms.unlock') && str_contains($routes, "'locks:open'"), 'unlock exige scope e idempotencia');
checkRoomControl(str_contains($routes, "'rooms:state'"), 'reset protegido por rooms:state');

checkRoomControl(str_contains($status, "'guest_inside'")
    && str_contains($status, "'remaining_minutes'")
    && str_contains($status, "'switch_state'")
    && str_contains($status, "'cooldown_active'")
    && str_contains($status, "'warehouse'"), 'estado de sala completo');
checkRoomControl(str_contains($status, 'function controls')
    && str_contains($status, "'room_id'")
    && str_contains($status, 'workers_inside')
    && str_contains($controller, "'items' => \$this->status->controls()"), 'resumen masivo de controles');
checkRoomControl(str_contains($reset, 'qr_revoked') && str_contains($reset, 'stays_closed')
    && str_contains($reset, 'debts_deleted') && str_contains($reset, 'light_off'), 'reset devuelve contadores');
checkRoomControl(str_contains($reset, "status = 'FREE'") && str_contains($reset, 'cycleResetter->reset'), 'reset limpia estado y ciclo');
checkRoomControl(str_contains($unlock, 'WarehouseDoorOpenService')
    && str_contains($unlock, 'LockService')
    && str_contains($unlock, "'remote'")
    && str_contains($unlock, "'lock'"), 'apertura unificada lock/remota');
checkRoomControl(str_contains($unlock, "ALMACEN_BEBIDAS','TIMBRADO"), 'apertura remota incluye almacén y timbrado');

checkRoomControl(str_contains($controller, 'confirm_required')
    && str_contains($controller, 'override_cooldown')
    && str_contains($controller, 'lightOn')
    && str_contains($controller, 'dashboardUnlock'), 'controlador con validaciones y fachada');

checkRoomControl(str_contains($panel, '/api/v1/rooms/controls')
    && str_contains($panel, 'toggleRoomLight')
    && str_contains($panel, 'openRoomLock')
    && str_contains($panel, 'resetRoomCycle')
    && str_contains($panel, 'renderRoomRemoteCard'), 'panel con listado y acciones');
checkRoomControl(str_contains($panel, 'setRoomCamera')
    && str_contains($panel, 'renderRoomWarehouse')
    && str_contains($panel, 'updateRoomFirmware')
    && str_contains($panel, 'cancelRoomFirmware'), 'panel con almacén y OTA');

checkRoomControl(str_contains($dashboard, '/api/v1/rooms/')
    && str_contains($dashboard, 'setRoomLight')
    && str_contains($dashboard, 'openRoomLockRemote')
    && str_contains($dashboard, 'room-status-modal'), 'dashboard con barra de control y estado');

checkRoomControl(!preg_match('/tuya/i', $status . $reset . $unlock . $controller), 'sin referencias a Tuya');
checkRoomControl(!str_contains($status, 'curl_') && !str_contains($status, 'file_get_contents("http'), 'estado solo local');

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
