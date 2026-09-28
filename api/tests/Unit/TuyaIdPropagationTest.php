<?php
declare(strict_types=1);

/**
 * Unit tests for App\Domain\Devices\TuyaIdPropagation pure helpers.
 *
 * Pins the CLI contract used by bin/tuya-propagate-ids.php without touching the
 * DB, the filesystem or the network. Auto-discovered by run-tests.sh (BLOCK 1).
 *
 * Run:
 *   php tests/Unit/TuyaIdPropagationTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\TuyaIdPropagation;

$passed = 0;
$failed = 0;

function pass(string $label): void
{
    global $passed;
    $passed++;
    echo "  PASS  {$label}\n";
}

function fail(string $label, string $msg = ''): void
{
    global $failed;
    $failed++;
    echo "  FAIL  {$label}";
    if ($msg !== '') {
        echo " — {$msg}";
    }
    echo "\n";
}

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function assertSameValue(string $label, $expected, $actual): void
{
    if ($expected === $actual) {
        pass($label);
    } else {
        fail($label, 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function assertTrueValue(string $label, bool $condition, string $msg = ''): void
{
    if ($condition) {
        pass($label);
    } else {
        fail($label, $msg);
    }
}

/**
 * @param callable():void $fn
 */
function expectInvalidArgument(string $label, callable $fn): void
{
    try {
        $fn();
        fail($label, 'se esperaba InvalidArgumentException');
    } catch (InvalidArgumentException $e) {
        pass($label);
    } catch (Throwable $e) {
        fail($label, 'excepción inesperada: ' . get_class($e) . ' — ' . $e->getMessage());
    }
}

echo "TuyaIdPropagation (Fase 1 migración Tuya)\n";
echo str_repeat('=', 60) . "\n\n";

// ---------------------------------------------------------------------------
// parseIdArg
// ---------------------------------------------------------------------------
$parsed = TuyaIdPropagation::parseIdArg('PRESENCE:bf9a278e76e2c3f01ay0cs=FAKENEWID');
assertSameValue('parse válido kind/old/new', [
    'kind' => 'PRESENCE',
    'old' => 'bf9a278e76e2c3f01ay0cs',
    'new' => 'FAKENEWID',
], $parsed);

$parsedLower = TuyaIdPropagation::parseIdArg('switch:oldid=newid');
assertSameValue('parse normaliza kind a mayúsculas', 'SWITCH', $parsedLower['kind']);

expectInvalidArgument('parse sin ":" lanza', static function (): void {
    TuyaIdPropagation::parseIdArg('PRESENCE_old_new');
});

expectInvalidArgument('parse sin "=" lanza', static function (): void {
    TuyaIdPropagation::parseIdArg('PRESENCE:oldidnewid');
});

expectInvalidArgument('parse old vacío lanza', static function (): void {
    TuyaIdPropagation::parseIdArg('PRESENCE:=newid');
});

expectInvalidArgument('parse new vacío lanza', static function (): void {
    TuyaIdPropagation::parseIdArg('PRESENCE:oldid=');
});

expectInvalidArgument('parse old===new lanza', static function (): void {
    TuyaIdPropagation::parseIdArg('PRESENCE:same=same');
});

expectInvalidArgument('parse kind inválido lanza', static function (): void {
    TuyaIdPropagation::parseIdArg('1BAD:old=new');
});

// ---------------------------------------------------------------------------
// normalizeEntries
// ---------------------------------------------------------------------------
$map = [
    'devices' => [
        ['kind' => 'PRESENCE', 'old' => 'old-1', 'new' => 'new-1', 'meta' => ['product_id' => 'pid']],
        ['kind' => 'PROXIMITY', 'old' => 'old-2', 'new' => 'new-2'],
    ],
];
$entries = TuyaIdPropagation::normalizeEntries($map);
assertSameValue('normalize cuenta de entradas', 2, count($entries));
assertSameValue('normalize conserva meta', ['product_id' => 'pid'], $entries[0]['meta']);
assertSameValue('normalize meta vacío por defecto', [], $entries[1]['meta']);
assertSameValue('normalize kind opcional se conserva', 'PROXIMITY', $entries[1]['kind']);

$entrySinKind = TuyaIdPropagation::normalizeEntries([
    ['old' => 'only-old', 'new' => 'only-new'],
]);
assertSameValue('normalize kind vacío cuando no se aporta', '', $entrySinKind[0]['kind']);

$deduped = TuyaIdPropagation::normalizeEntries([
    ['kind' => 'PRESENCE', 'old' => 'dup', 'new' => 'first'],
    ['kind' => 'PRESENCE', 'old' => 'dup', 'new' => 'second'],
]);
assertSameValue('normalize deduplica por old', 1, count($deduped));
assertSameValue('normalize dedupe gana la primera', 'first', $deduped[0]['new']);

$plainList = TuyaIdPropagation::normalizeEntries([
    ['kind' => 'SWITCH', 'old' => 'a', 'new' => 'b'],
]);
assertSameValue('normalize acepta lista plana', 1, count($plainList));

expectInvalidArgument('normalize old vacío lanza', static function (): void {
    TuyaIdPropagation::normalizeEntries([['old' => '', 'new' => 'x']]);
});

expectInvalidArgument('normalize old===new lanza', static function (): void {
    TuyaIdPropagation::normalizeEntries([['old' => 'x', 'new' => 'x']]);
});

expectInvalidArgument('normalize meta no-array lanza', static function (): void {
    TuyaIdPropagation::normalizeEntries([['old' => 'x', 'new' => 'y', 'meta' => 'nope']]);
});

expectInvalidArgument('normalize kind inválido lanza', static function (): void {
    TuyaIdPropagation::normalizeEntries([['kind' => '1BAD', 'old' => 'x', 'new' => 'y']]);
});

// ---------------------------------------------------------------------------
// mergeMeta
// ---------------------------------------------------------------------------
$merged = TuyaIdPropagation::mergeMeta(
    ['product_id' => 'old-pid', 'dp_caps' => ['switch' => true, 'battery' => true]],
    ['product_id' => 'new-pid', 'dp_caps' => ['switch' => false]]
);
assertSameValue('mergeMeta override escalar', 'new-pid', $merged['product_id']);
assertSameValue('mergeMeta recursivo conserva claves', true, $merged['dp_caps']['battery']);
assertSameValue('mergeMeta recursivo sobreescribe', false, $merged['dp_caps']['switch']);

$calMerged = TuyaIdPropagation::mergeMeta(
    ['calibration' => ['far_detection' => 150, 'sensitivity' => 10]],
    ['calibration' => ['sensitivity' => 7]]
);
assertSameValue('mergeMeta anidado conserva far_detection', 150, $calMerged['calibration']['far_detection']);
assertSameValue('mergeMeta anidado aplica sensitivity', 7, $calMerged['calibration']['sensitivity']);

$listMerged = TuyaIdPropagation::mergeMeta(
    ['levels' => [1, 2, 3], 'keep' => 'yes'],
    ['levels' => [9]]
);
assertSameValue('mergeMeta lista se reemplaza, no se fusiona', [9], $listMerged['levels']);
assertSameValue('mergeMeta conserva claves no tocadas', 'yes', $listMerged['keep']);

// ---------------------------------------------------------------------------
// buildRollbackSql
// ---------------------------------------------------------------------------
$rollback = TuyaIdPropagation::buildRollbackSql([
    ['kind' => 'PRESENCE', 'old' => 'old-p', 'new' => 'new-p', 'meta' => []],
    ['kind' => '', 'old' => 'old-x', 'new' => 'new-x', 'meta' => []],
]);
assertTrueValue(
    'buildRollbackSql genera UPDATE inverso con kind',
    str_contains($rollback, "UPDATE devices SET external_id='old-p' WHERE external_id='new-p' AND kind='PRESENCE';"),
    $rollback
);
assertTrueValue(
    'buildRollbackSql sin kind omite el AND',
    str_contains($rollback, "UPDATE devices SET external_id='old-x' WHERE external_id='new-x';"),
    $rollback
);
assertTrueValue('buildRollbackSql termina en salto de línea', str_ends_with($rollback, "\n"));

$escaped = TuyaIdPropagation::buildRollbackSql([
    ['kind' => 'SWITCH', 'old' => "o'1", 'new' => "n'2", 'meta' => []],
]);
assertTrueValue(
    'buildRollbackSql escapa comillas simples',
    str_contains($escaped, "external_id='o''1' WHERE external_id='n''2'"),
    $escaped
);

assertSameValue('buildRollbackSql vacío devuelve cadena vacía', '', TuyaIdPropagation::buildRollbackSql([]));

// ---------------------------------------------------------------------------
// replaceInContent
// ---------------------------------------------------------------------------
$none = TuyaIdPropagation::replaceInContent('sin ids aquí', 'old', 'new');
assertSameValue('replaceInContent 0 coincidencias count', 0, $none['count']);
assertSameValue('replaceInContent 0 coincidencias contenido', 'sin ids aquí', $none['content']);

$one = TuyaIdPropagation::replaceInContent('id=old fin', 'old', 'new');
assertSameValue('replaceInContent 1 coincidencia count', 1, $one['count']);
assertSameValue('replaceInContent 1 coincidencia contenido', 'id=new fin', $one['content']);

$many = TuyaIdPropagation::replaceInContent('old old old', 'old', 'new');
assertSameValue('replaceInContent varias coincidencias count', 3, $many['count']);
assertSameValue('replaceInContent varias coincidencias contenido', 'new new new', $many['content']);

$idempotent = TuyaIdPropagation::replaceInContent($one['content'], 'old', 'new');
assertSameValue('replaceInContent es idempotente', 0, $idempotent['count']);

$emptyOld = TuyaIdPropagation::replaceInContent('abc', '', 'new');
assertSameValue('replaceInContent old vacío no-op count', 0, $emptyOld['count']);
assertSameValue('replaceInContent old vacío no-op contenido', 'abc', $emptyOld['content']);

echo "\n";
echo str_repeat('=', 60) . "\n";
echo sprintf("Total: %d passed, %d failed\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
