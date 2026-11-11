<?php
declare(strict_types=1);

/**
 * F127 — unified API + real room code lookup.
 *
 * Run: php tests/Unit/F127ApiUnificationTest.php
 */

$passed = 0;
$failed = 0;
function f127check(bool $cond, string $msg): void
{
    global $passed, $failed;
    $cond ? $passed++ : $failed++;
    echo ($cond ? 'PASS ' : 'FAIL ') . $msg . "\n";
}

$root = dirname(__DIR__, 3);
$routes = (string) file_get_contents($root . '/api/public/index.php');
$dashboard = (string) file_get_contents($root . '/api/public/dashboard.html');
$auth = (string) file_get_contents($root . '/api/src/Http/Middlewares/AuthApiKeyMiddleware.php');
$qrController = (string) file_get_contents($root . '/api/src/Http/Controllers/QrController.php');
$qrService = (string) file_get_contents($root . '/api/src/Domain/Qr/QrIssueService.php');
$roomController = (string) file_get_contents($root . '/api/src/Http/Controllers/RoomController.php');
$roomService = (string) file_get_contents($root . '/api/src/Domain/Rooms/RoomService.php');
$tool = (string) file_get_contents($root . '/api/bin/make-reservation-qr/index.js');
$guide = (string) file_get_contents($root . '/api/public/docs/guia-integracion-vb6.md');

f127check(str_contains($routes, "'/api/v1/rooms/{id}/light'")
    && str_contains($routes, '[$roomControlController, \'lightAction\']'), 'ruta canónica POST /rooms/{id}/light');
f127check(str_contains($routes, "'/api/v1/rooms/by-code/{code}'")
    && str_contains($routes, '[$roomController, \'showByCode\']'), 'ruta GET /rooms/by-code/{code}');
f127check(str_contains($roomController, 'getByCodeOrFail($code)')
    && str_contains($roomController, 'showByCode')
    && str_contains($roomService, 'findByCode(trim($code))'), 'RoomController resuelve por código');

f127check(str_contains($routes, 'DashboardSessionService')
    && str_contains($routes, 'withCookie(')
    && str_contains($routes, "'/dashboard'"), 'dashboard emite cookie de sesión LAN');
$dashStart = strpos($auth, 'private function tryDashboardSession');
$dashEnd = strpos($auth, '/** @return list<string> */', $dashStart === false ? 0 : $dashStart);
$dashBlock = ($dashStart === false || $dashEnd === false) ? '' : substr($auth, $dashStart, $dashEnd - $dashStart);
f127check(str_contains($auth, 'DashboardSessionService')
    && str_contains($dashBlock, "'DASHBOARD-LAN'")
    && str_contains($dashBlock, "'rooms:read'")
    && str_contains($dashBlock, "'switches:write'")
    && str_contains($dashBlock, "'locks:open'")
    && str_contains($dashBlock, "'rooms:state'")
    && !str_contains($dashBlock, "'locks:override'"), 'middleware acepta cookie con scopes limitados');

f127check(!str_contains($dashboard, '/dashboard-api/rooms'), 'dashboard ya no usa /dashboard-api/rooms');
f127check(str_contains($dashboard, '/api/v1/rooms/')
    && str_contains($dashboard, 'credentials: \'same-origin\''), 'dashboard usa /api/v1 con cookie');

f127check(str_contains($qrController, "'room_code'")
    && str_contains($qrController, 'intOpt($body, \'room_id\'')
    && str_contains($qrService, 'room_selector_required')
    && str_contains($qrService, 'room_selector_ambiguous')
    && str_contains($qrService, 'room_code_mismatch'), 'QR por room_id o room_code con validaciones');
f127check(str_contains($qrService, 'findByCode($roomCode)')
    && str_contains($qrService, "'room_code' => \$room->code"), 'servicio resuelve por código y devuelve room_code');

f127check(str_contains($routes, 'Deprecation')
    || str_contains((string) file_get_contents($root . '/api/src/Http/Controllers/RoomControlController.php'), "'Deprecation'"), 'alias dashboard marcado como deprecado');

f127check(!str_contains($tool, '5a78442da74f9eba7bfb151678ad716567e47b41b3119155')
    && !str_contains($tool, 'P2R6dABhDnta')
    && str_contains($tool, 'CERRADURAS_API_KEY'), 'script sin secretos hardcodeados');
f127check(!str_contains($guide, '5a78442da74f9eba7bfb151678ad716567e47b41b3119155')
    && str_contains($guide, 'room_code'), 'guía sin clave hardcodeada y con room_code');

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
