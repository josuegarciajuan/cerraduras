<?php
declare(strict_types=1);

/**
 * F114+ — seguridad de argumentos de `bin/radar-apply-profile.php`.
 *
 * El script aplicaba un perfil real por defecto y `getopt()` ignoraba los
 * argumentos desconocidos. Estas pruebas garantizan que un uso inválido
 * termina antes de cargar Config/PDO y sin tocar MQTT.
 *
 * Run: php tests/Unit/RadarApplyProfileArgsTest.php
 */

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

/**
 * @return array{code:int,out:string,err:string}
 */
function runScript(array $args): array
{
    $script = __DIR__ . '/../../bin/radar-apply-profile.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, __DIR__ . '/../..');
    if (!is_resource($proc)) {
        return ['code' => -1, 'out' => '', 'err' => 'proc_open falló'];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) ?: '';
    $err = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return ['code' => $code, 'out' => $out, 'err' => $err];
}

echo "radar-apply-profile argumentos\n";

$help = runScript(['--help']);
if ($help['code'] === 0 && str_contains($help['out'], 'Uso:')) {
    pass('--help exit 0 y muestra "Uso:"');
} else {
    fail('--help exit 0 y muestra "Uso:" — exit=' . $help['code'] . ' out=' . trim($help['out']) . ' err=' . trim($help['err']));
}

$nope = runScript(['--nope']);
if ($nope['code'] === 2 && str_contains($nope['err'], 'desconocido')) {
    pass('--nope exit 2 y "desconocido"');
} else {
    fail('--nope exit 2 y "desconocido" — exit=' . $nope['code'] . ' err=' . trim($nope['err']));
}

$badRoom = runScript(['--room=abc']);
if ($badRoom['code'] === 2) {
    pass('--room=abc exit 2');
} else {
    fail('--room=abc exit 2 — exit=' . $badRoom['code'] . ' out=' . trim($badRoom['out']));
}

$badFading = runScript(['--fading=abc']);
if ($badFading['code'] === 2) {
    pass('--fading=abc exit 2');
} else {
    fail('--fading=abc exit 2 — exit=' . $badFading['code'] . ' out=' . trim($badFading['out']));
}

$missing = runScript(['--room']);
if ($missing['code'] === 2 && str_contains($missing['err'], 'Falta valor')) {
    pass('--room sin valor exit 2');
} else {
    fail('--room sin valor exit 2 — exit=' . $missing['code'] . ' err=' . trim($missing['err']));
}

$range = runScript(['--fading=0.1']);
if ($range['code'] === 2 && str_contains($range['err'], 'fuera de rango')) {
    pass('--fading fuera de rango exit 2');
} else {
    fail('--fading fuera de rango exit 2 — exit=' . $range['code'] . ' err=' . trim($range['err']));
}

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
