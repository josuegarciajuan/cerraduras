<?php
declare(strict_types=1);

/**
 * F126/F128 — VB6-MAIN puede operar el control de sala completo.
 *
 * Run: php tests/Unit/Vb6MainScopesTest.php
 *
 * Comprueba que el cliente del TPV/VB6 tiene los scopes de luz y apertura:
 *  - `switches:write`  → encender/apagar luz
 *  - `locks:open`      → abrir cerradura
 *  - `rooms:read`      → estado
 *  - `rooms:state`     → reset
 * y que NO recibe scopes peligrosos (`locks:override`, `sim:*`).
 */

$passed = 0;
$failed = 0;
function vb6Check(bool $cond, string $msg): void
{
    global $passed, $failed;
    $cond ? $passed++ : $failed++;
    echo ($cond ? 'PASS ' : 'FAIL ') . $msg . "\n";
}

$root = dirname(__DIR__, 3);
$seed = (string) file_get_contents($root . '/api/bin/seed.php');
$migration = (string) file_get_contents($root . '/api/migrations/0149_vb6_main_room_control_scopes.sql');
$docs = (string) file_get_contents($root . '/docs/ops.md');

preg_match("/\['VB6-MAIN'[^\n]*\]/", $seed, $m);
$seedLine = $m[0] ?? '';
$scopes = [];
if (preg_match("/'([^']*qr:issue[^']*)'/", $seedLine, $sm)) {
    $scopes = explode(',', $sm[1]);
}

foreach (['qr:issue', 'rooms:read', 'rooms:state', 'switches:write', 'locks:open'] as $scope) {
    vb6Check(in_array($scope, $scopes, true), "seed VB6-MAIN incluye {$scope}");
}
vb6Check(!in_array('locks:override', $scopes, true), 'seed VB6-MAIN no incluye locks:override');
vb6Check(!in_array('sim:*', $scopes, true), 'seed VB6-MAIN no incluye sim:*');

vb6Check(str_contains($migration, "code` = 'VB6-MAIN'")
    && substr_count($migration, "code` = 'VB6-MAIN'") === 2, 'migración limitada a las dos actualizaciones de VB6-MAIN');
vb6Check(str_contains($migration, 'FIND_IN_SET(\'locks:open\', `scopes_csv`) = 0')
    && str_contains($migration, 'FIND_IN_SET(\'switches:write\', `scopes_csv`) = 0'), 'migración idempotente por scope');
vb6Check(str_contains($migration, "'locks:open'") && str_contains($migration, "'switches:write'"), 'migración añade luz y apertura');
vb6Check(!str_contains($migration, "'locks:override'") && !str_contains($migration, "'sim:*'"), 'migración no concede scopes peligrosos');

vb6Check(str_contains($docs, 'VB6-MAIN | Emitir QR (recepción) + control de sala'), 'docs/ops.md documenta el control de sala');

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
