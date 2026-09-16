<?php
declare(strict_types=1);

/**
 * Unit tests for Migrator::splitStatements() — robustez de migraciones.
 *
 * Regresión del incidente 0101: `PDO::exec()` sobre un script multi-sentencia
 * dejaba result sets pendientes y rompía el registro en `_migrations`. El
 * splitter aísla cada sentencia para ejecutarla de una en una.
 *
 * No necesita base de datos.
 *
 * Run:
 *   php tests/Unit/MigrationSplitterTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Db\Migrator;

$passed = 0;
$failed = 0;

function pass(string $msg): void
{
    global $passed;
    $passed++;
    echo "  PASS  {$msg}\n";
}

function fail(string $msg): void
{
    global $failed;
    $failed++;
    echo "  FAIL  {$msg}\n";
}

/**
 * @param list<string> $expected
 */
function assertStatements(string $label, string $sql, array $expected): void
{
    $actual = Migrator::splitStatements($sql);
    if ($actual === $expected) {
        pass($label);
        return;
    }
    fail(
        $label . ' — expected ' . json_encode($expected)
        . ', got ' . json_encode($actual)
    );
}

echo "MigrationSplitter\n";
echo str_repeat("-", 60) . "\n";

// 1. Dos sentencias simples.
assertStatements(
    'dos sentencias simples separadas por ;',
    "SELECT 1;\nSELECT 2;",
    ['SELECT 1', 'SELECT 2']
);

// 2. `;` dentro de comilla simple no separa.
assertStatements(
    '; dentro de comilla simple no separa',
    "INSERT INTO t (v) VALUES ('a;b'); SELECT 1;",
    ["INSERT INTO t (v) VALUES ('a;b')", 'SELECT 1']
);

// 3a. `;` dentro de comentario de línea no separa.
assertStatements(
    '; dentro de comentario de línea (--) no separa',
    "-- nota; no separa\nSELECT 1;",
    ['SELECT 1']
);

// 3b. `;` dentro de comentario de bloque no separa.
assertStatements(
    '; dentro de comentario de bloque no separa',
    "/* nota; no separa */\nSELECT 1;",
    ['SELECT 1']
);

// 4. Backticks (identificadores) conservan sus `;`.
assertStatements(
    '; dentro de backticks no separa',
    'CREATE TABLE `a;b` (id INT);',
    ['CREATE TABLE `a;b` (id INT)']
);

// 5. Sentencia sin `;` final se emite igualmente.
assertStatements(
    'sentencia sin ; final se emite',
    "SELECT 1;\nUPDATE t SET x = 1",
    ['SELECT 1', 'UPDATE t SET x = 1']
);

// 6. Solo comentarios -> 0 sentencias (caso de 0101 neutralizada).
assertStatements(
    'script de solo comentarios produce 0 sentencias',
    "-- solo comentario\n/* bloque */\n# hash\n",
    []
);

// Extra: comillas escapadas (\\' y '') no cierran el literal antes de tiempo.
assertStatements(
    "comilla escapada \\' y '' mantienen el literal",
    "SELECT 'it\\'s; ok'; SELECT 'two''s; ok';",
    ["SELECT 'it\\'s; ok'", "SELECT 'two''s; ok'"]
);

// Extra: sentencias vacías entre `;;` se ignoran.
assertStatements(
    'sentencias vacías entre ;; se ignoran',
    ";;\nSELECT 1;;\n;",
    ['SELECT 1']
);

// Extra: el script no-op real de la migración 0101 produce 0 sentencias.
$noop0101 = file_get_contents(__DIR__ . '/../../migrations/0101_fix_exit_detected_at_and_events.sql');
if ($noop0101 !== false && Migrator::splitStatements($noop0101) === []) {
    pass('0101 neutralizada no ejecuta ninguna sentencia');
} else {
    fail('0101 neutralizada no ejecuta ninguna sentencia');
}

echo str_repeat("-", 60) . "\n";
echo "Total: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
