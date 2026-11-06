<?php
declare(strict_types=1);

/**
 * Unit tests for F117/RF-303 WorkerSessionPolicy (no DB).
 *
 * Run: php tests/Unit/WorkerSessionPolicyTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\AccessKeys\WorkerSessionPolicy;

$pass = 0;
$fail = 0;

function ok(string $label): void
{
    global $pass;
    $pass++;
    echo "  PASS  {$label}\n";
}

function bad(string $label, string $detail = ''): void
{
    global $fail;
    $fail++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function check(string $label, bool $cond, string $detail = ''): void
{
    $cond ? ok($label) : bad($label, $detail);
}

check(
    'T1: TIMBRADO no crea ni bloquea sesiones de trabajador',
    WorkerSessionPolicy::tracksSessions('TIMBRADO') === false
);
check(
    'T2: TIMBRADO en minúsculas tampoco',
    WorkerSessionPolicy::tracksSessions('timbrado') === false
);
check(
    'T3: TIMBRADO con espacios se normaliza',
    WorkerSessionPolicy::tracksSessions('  TIMBRADO  ') === false
);
check(
    'T4: ALMACEN_BEBIDAS conserva sesiones',
    WorkerSessionPolicy::tracksSessions('ALMACEN_BEBIDAS') === true
);
check(
    'T5: salas de hotel conservan sesiones',
    WorkerSessionPolicy::tracksSessions('DOUBLE') === true
);
check(
    'T6: sin tipo de sala se conserva el comportamiento actual',
    WorkerSessionPolicy::tracksSessions(null) === true
);

echo "\n  Result: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
