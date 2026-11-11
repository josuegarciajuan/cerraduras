<?php
declare(strict_types=1);

/**
 * F128 — room-control actions addressed by real room code.
 *
 * Run: php tests/Unit/F128ByCodeRoutesTest.php
 */

$passed = 0;
$failed = 0;
function f128check(bool $cond, string $msg): void
{
    global $passed, $failed;
    $cond ? $passed++ : $failed++;
    echo ($cond ? 'PASS ' : 'FAIL ') . $msg . "\n";
}

$root = dirname(__DIR__, 3);
$routes = (string) file_get_contents($root . '/api/public/index.php');
$controller = (string) file_get_contents($root . '/api/src/Http/Controllers/RoomControlController.php');

foreach ([
    '/api/v1/rooms/by-code/{code}/status',
    '/api/v1/rooms/by-code/{code}/light',
    '/api/v1/rooms/by-code/{code}/light/on',
    '/api/v1/rooms/by-code/{code}/light/off',
    '/api/v1/rooms/by-code/{code}/unlock',
    '/api/v1/rooms/by-code/{code}/reset',
] as $path) {
    f128check(str_contains($routes, "'$path'"), "ruta $path");
}

f128check(str_contains($routes, "'/api/v1/rooms/by-code/{code}/unlock'")
    && str_contains($routes, "[\$idempotencyFactory('rooms.unlock')]"), 'unlock por código exige idempotencia');
f128check(str_contains($routes, "'/api/v1/rooms/by-code/{code}/reset'")
    && str_contains($routes, "\$authFactory(['rooms:state'])"), 'reset por código exige rooms:state');

f128check(str_contains($controller, 'private function resolveRoomId')
    && str_contains($controller, "routeParam('id')")
    && str_contains($controller, "routeParam('code')")
    && str_contains($controller, 'findByCode(trim($code))'), 'resolver único id/código');
f128check(str_contains($controller, '$roomId = $this->resolveRoomId($request)')
    && !str_contains($controller, '(int) $request->routeParam(\'id\')'), 'acciones usan el resolver compartido');

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
