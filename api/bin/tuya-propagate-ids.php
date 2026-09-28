#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/tuya-propagate-ids.php — propagación idempotente de nuevos `device_id`
 * de Tuya tras re-emparejar la cuenta (Fase 1 de la migración, runbook §6).
 *
 * Hace dos cosas, siempre por defecto en dry-run:
 *   (a) BD: actualiza `devices.external_id` y fusiona `devices.meta_json`.
 *   (b) Código (solo con `--code`): sustituye los ids viejos por los nuevos en
 *       los ficheros de texto del repo (debe ejecutarse dentro de un worktree).
 *
 * Uso:
 *   php api/bin/tuya-propagate-ids.php --map=map.json           # dry-run
 *   php api/bin/tuya-propagate-ids.php --map=map.json --apply   # aplica BD
 *   php api/bin/tuya-propagate-ids.php --id=KIND:OLD=NEW        # dry-run
 *   php api/bin/tuya-propagate-ids.php --map=map.json --code    # dry-run + listar código
 *   php api/bin/tuya-propagate-ids.php --map=map.json --code --apply
 *
 * El modo código NUNCA se aplica implícitamente: exige `--code` o
 * `"code_refs": true` en el JSON.
 *
 * Requisitos:
 *   - `api/.env` con la conexión MySQL (o variables DB_* en el entorno).
 *   - Sin dependencias nuevas.
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Domain\Devices\TuyaIdPropagation;
use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

/**
 * @param list<string> $argv
 */
function tuya_print_usage(array $argv): void
{
    $self = $argv[0] ?? 'tuya-propagate-ids.php';
    $help = <<<TXT
    Propagación de nuevos device_id de Tuya (idempotente; dry-run por defecto).

    Uso:
      php {$self} --map=<ruta.json> [--code] [--apply]
      php {$self} --id=KIND:OLD=NEW [--id=KIND:OLD=NEW ...] [--code] [--apply]

    Argumentos:
      --map=<ruta.json>   Mapa de dispositivos (ver formato abajo).
      --id=KIND:OLD=NEW   Alternativa al fichero; repetible.
      --code              Además de BD, sustituir los ids viejos por los nuevos
                          en ficheros de texto del repo (úsalo dentro de un
                          worktree, nunca en el árbol de producción).
      --apply             Ejecuta de verdad. Sin este flag es dry-run: lista el
                          plan y no escribe ni BD ni ficheros.
      --help, -h          Muestra esta ayuda.

    Formato del mapa JSON:
      {
        "devices": [
          {"kind":"PRESENCE","old":"<viejo>","new":"<nuevo>",
           "meta":{"product_id":"<pid>","dp_caps":{...},
                   "presence_source":"push","calibration":{...}}},
          {"kind":"PROXIMITY","old":"<viejo>","new":"<nuevo>"},
          {"kind":"SWITCH","old":"<viejo>","new":"<nuevo>"}
        ],
        "code_refs": true
      }

    Con --apply se escribe un SQL de rollback en
    api/run/tuya-id-rollback-<YmdHis>.sql antes de tocar la BD.

    TXT;
    fwrite(STDOUT, $help . "\n");
}

/**
 * Recursively collect candidate text files under $root, skipping protected /
 * noisy directories and secret files.
 *
 * @return list<string>
 */
function tuya_collect_text_files(string $root): array
{
    if (!is_dir($root)) {
        return [];
    }

    $skipDirs = ['.git', 'node_modules', 'data', 'vendor'];
    $skipFileNames = ['.env', 'client_secret', 'config.local.json'];
    $skipExtensions = ['.pickle', '.bak'];

    $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);

    $filter = new RecursiveCallbackFilterIterator(
        $directory,
        static function (SplFileInfo $current) use ($root, $skipDirs, $skipFileNames, $skipExtensions): bool {
            $pathname = $current->getPathname();
            $relative = ltrim(substr($pathname, strlen($root)), DIRECTORY_SEPARATOR);

            if ($current->isDir()) {
                if (in_array($current->getFilename(), $skipDirs, true)) {
                    return false;
                }
                // `api/run/` guarda estado vivo generado: nunca se reescribe.
                $apiRun = 'api' . DIRECTORY_SEPARATOR . 'run';
                if ($relative === $apiRun || str_starts_with($relative, $apiRun . DIRECTORY_SEPARATOR)) {
                    return false;
                }
                return true;
            }

            $name = $current->getFilename();
            foreach ($skipFileNames as $needle) {
                if ($name === $needle || str_starts_with($name, $needle)) {
                    return false;
                }
            }
            foreach ($skipExtensions as $extension) {
                if (str_ends_with($name, $extension)) {
                    return false;
                }
            }

            return true;
        }
    );

    $files = [];
    $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

$argv = $_SERVER['argv'] ?? [];
$scriptName = $argv[0] ?? 'tuya-propagate-ids.php';
$args = array_slice($argv, 1);

$mapPath = null;
$idArgs = [];
$codeMode = false;
$apply = false;

for ($i = 0, $count = count($args); $i < $count; $i++) {
    $arg = $args[$i];

    if ($arg === '--help' || $arg === '-h') {
        tuya_print_usage([$scriptName]);
        exit(0);
    }
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if ($arg === '--code') {
        $codeMode = true;
        continue;
    }
    if (str_starts_with($arg, '--map=')) {
        $mapPath = substr($arg, strlen('--map='));
        continue;
    }
    if ($arg === '--map') {
        $mapPath = $args[++$i] ?? '';
        continue;
    }
    if (str_starts_with($arg, '--id=')) {
        $idArgs[] = substr($arg, strlen('--id='));
        continue;
    }
    if ($arg === '--id') {
        $idArgs[] = $args[++$i] ?? '';
        continue;
    }

    fwrite(STDERR, "[tuya-propagate-ids] Argumento no reconocido: {$arg}\n");
    tuya_print_usage([$scriptName]);
    exit(2);
}

$mapData = [];
if ($mapPath !== null) {
    if ($mapPath === '' || !is_file($mapPath) || !is_readable($mapPath)) {
        fwrite(STDERR, "[tuya-propagate-ids] ERROR: no se puede leer el mapa '{$mapPath}'\n");
        exit(1);
    }
    $rawJson = file_get_contents($mapPath);
    if ($rawJson === false) {
        fwrite(STDERR, "[tuya-propagate-ids] ERROR: fallo leyendo '{$mapPath}'\n");
        exit(1);
    }
    try {
        $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, "[tuya-propagate-ids] ERROR: JSON inválido en '{$mapPath}': {$e->getMessage()}\n");
        exit(1);
    }
    if (!is_array($decoded)) {
        fwrite(STDERR, "[tuya-propagate-ids] ERROR: el mapa debe ser un objeto o una lista JSON\n");
        exit(1);
    }
    /** @var array<string,mixed> $mapData */
    $mapData = $decoded;
}

if (($mapData['code_refs'] ?? false) === true) {
    $codeMode = true;
}

$rawEntries = [];
if (array_key_exists('devices', $mapData)) {
    if (!is_array($mapData['devices'])) {
        fwrite(STDERR, "[tuya-propagate-ids] ERROR: 'devices' del mapa debe ser una lista\n");
        exit(1);
    }
    $rawEntries = $mapData['devices'];
} elseif ($mapData !== [] && array_is_list($mapData)) {
    $rawEntries = $mapData;
}

$parsedIdArgs = [];
try {
    foreach ($idArgs as $idArg) {
        $parsedIdArgs[] = TuyaIdPropagation::parseIdArg($idArg);
    }
    if ($parsedIdArgs !== []) {
        $rawEntries = array_merge($rawEntries, $parsedIdArgs);
    }
    $entries = TuyaIdPropagation::normalizeEntries($rawEntries);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, "[tuya-propagate-ids] ERROR: {$e->getMessage()}\n");
    exit(2);
}

if ($entries === []) {
    fwrite(STDERR, "[tuya-propagate-ids] ERROR: sin entradas. Usa --map=<ruta.json> o --id=KIND:OLD=NEW\n");
    exit(2);
}

$mode = $apply ? 'APPLY' : 'DRY-RUN';
echo "========================================================\n";
echo "  Tuya device_id propagation — {$mode}\n";
echo "========================================================\n";
echo 'Entradas: ' . count($entries) . "\n";

$repoRoot = dirname(__DIR__, 2);
echo "Raíz del repo: {$repoRoot}\n";
if ($codeMode) {
    echo "Modo código: ON (sustitución en ficheros de texto)\n";
    if ($apply) {
        echo "  ⚠ Asegúrate de estar dentro de un worktree, nunca en producción.\n";
    }
}
echo "\n";

// ---------------------------------------------------------------------------
// 1) Plan de BD
// ---------------------------------------------------------------------------
Config::load(__DIR__ . '/../.env');

try {
    $pdo = PdoFactory::make();
} catch (Throwable $e) {
    fwrite(STDERR, "[tuya-propagate-ids] ERROR: no se pudo conectar a la BD: {$e->getMessage()}\n");
    exit(1);
}

/** @var list<array{entry:array{kind:string,old:string,new:string,meta:array<string,mixed>},id:int,db_kind:string,merged_meta:array<string,mixed>,meta_changed:bool}> $plan */
$plan = [];
/** @var list<string> $skips */
$skips = [];
/** @var list<string> $warns */
$warns = [];

foreach ($entries as $entry) {
    $select = 'SELECT id, kind, external_id, meta_json FROM devices WHERE external_id = :old';
    $params = [':old' => $entry['old']];
    if ($entry['kind'] !== '') {
        $select .= ' AND kind = :kind';
        $params[':kind'] = $entry['kind'];
    }
    $select .= ' LIMIT 1';

    $stmt = $pdo->prepare($select);
    $stmt->execute($params);
    $row = $stmt->fetch();

    // ¿Ya existe el id nuevo? Sirve tanto para el caso "ya migrado" (old no
    // encontrado) como para detectar un conflicto (old y new en filas distintas,
    // que rompería la clave única (kind, external_id) al aplicar).
    $selectNew = 'SELECT id FROM devices WHERE external_id = :new';
    $paramsNew = [':new' => $entry['new']];
    if ($entry['kind'] !== '') {
        $selectNew .= ' AND kind = :kind';
        $paramsNew[':kind'] = $entry['kind'];
    }
    $selectNew .= ' LIMIT 1';

    $stmtNew = $pdo->prepare($selectNew);
    $stmtNew->execute($paramsNew);
    $existingNew = $stmtNew->fetch();

    if ($row === false) {
        if ($existingNew !== false) {
            $skips[] = sprintf(
                "%s %s → %s: ya migrado (device id=%d)",
                $entry['kind'] !== '' ? $entry['kind'] : '?',
                $entry['old'],
                $entry['new'],
                (int) $existingNew['id']
            );
        } else {
            $warns[] = sprintf(
                "%s %s: no encontrado (ni old ni new en devices)",
                $entry['kind'] !== '' ? $entry['kind'] : '?',
                $entry['old']
            );
        }
        continue;
    }

    if ($existingNew !== false && (int) $existingNew['id'] !== (int) $row['id']) {
        $warns[] = sprintf(
            "%s %s → %s: conflicto, el id nuevo ya pertenece al device id=%d",
            $entry['kind'] !== '' ? $entry['kind'] : '?',
            $entry['old'],
            $entry['new'],
            (int) $existingNew['id']
        );
        continue;
    }

    $existingMeta = [];
    if (isset($row['meta_json']) && is_string($row['meta_json']) && $row['meta_json'] !== '') {
        $decodedMeta = json_decode($row['meta_json'], true);
        if (is_array($decodedMeta)) {
            /** @var array<string,mixed> $existingMeta */
            $existingMeta = $decodedMeta;
        }
    }

    $metaChanged = $entry['meta'] !== [];
    $mergedMeta = $metaChanged
        ? TuyaIdPropagation::mergeMeta($existingMeta, $entry['meta'])
        : $existingMeta;

    $plan[] = [
        'entry' => $entry,
        'id' => (int) $row['id'],
        'db_kind' => (string) $row['kind'],
        'merged_meta' => $mergedMeta,
        'meta_changed' => $metaChanged,
    ];
}

echo "Plan BD:\n";
if ($plan === []) {
    echo "  (sin cambios)\n";
}
foreach ($plan as $item) {
    $kind = $item['entry']['kind'] !== '' ? $item['entry']['kind'] : $item['db_kind'];
    $metaNote = $item['meta_changed']
        ? ' [meta: ' . count($item['entry']['meta']) . ' claves]'
        : '';
    printf(
        "  UPDATE %-10s %s → %s (device id=%d)%s\n",
        $kind,
        $item['entry']['old'],
        $item['entry']['new'],
        $item['id'],
        $metaNote
    );
}

echo "\n";
foreach ($skips as $skipLine) {
    echo "  SKIP  {$skipLine}\n";
}
foreach ($warns as $warnLine) {
    echo "  WARN  {$warnLine}\n";
}
if ($skips !== [] || $warns !== []) {
    echo "\n";
}

$rollbackPath = null;
$updated = 0;

if ($apply && $plan !== []) {
    $runDir = __DIR__ . '/../run';
    if (!is_dir($runDir) && !mkdir($runDir, 0o775, true) && !is_dir($runDir)) {
        fwrite(STDERR, "[tuya-propagate-ids] ERROR: no se pudo crear {$runDir}\n");
        exit(1);
    }

    $rollbackPath = $runDir . '/tuya-id-rollback-' . date('YmdHis') . '.sql';
    $rollbackEntries = array_map(
        static fn (array $item): array => $item['entry'],
        $plan
    );
    $header = "-- Rollback generado por tuya-propagate-ids.php el " . date('c') . "\n"
        . "-- Revierte devices.external_id a los ids anteriores.\n\n";
    $written = file_put_contents(
        $rollbackPath,
        $header . TuyaIdPropagation::buildRollbackSql($rollbackEntries)
    );
    if ($written === false) {
        fwrite(STDERR, "[tuya-propagate-ids] ERROR: no se pudo escribir el rollback {$rollbackPath}\n");
        exit(1);
    }
}

if ($apply) {
    $pdo->beginTransaction();
    try {
        $updateWithMeta = $pdo->prepare(
            'UPDATE devices SET external_id = :new, meta_json = :meta WHERE id = :id'
        );
        $updatePlain = $pdo->prepare(
            'UPDATE devices SET external_id = :new WHERE id = :id'
        );

        foreach ($plan as $item) {
            if ($item['meta_changed']) {
                $updateWithMeta->execute([
                    ':new' => $item['entry']['new'],
                    ':meta' => json_encode(
                        $item['merged_meta'],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                    ':id' => $item['id'],
                ]);
            } else {
                $updatePlain->execute([
                    ':new' => $item['entry']['new'],
                    ':id' => $item['id'],
                ]);
            }
            $updated++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "[tuya-propagate-ids] ERROR aplicando BD: {$e->getMessage()}\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// 2) Sustitución en código (solo con --code)
// ---------------------------------------------------------------------------
$codeFiles = [];
if ($codeMode) {
    $candidateFiles = tuya_collect_text_files($repoRoot);
    foreach ($candidateFiles as $file) {
        $content = @file_get_contents($file);
        if ($content === false || strpos($content, "\0") !== false) {
            continue; // ilegible o binario
        }

        $total = 0;
        foreach ($entries as $entry) {
            $result = TuyaIdPropagation::replaceInContent($content, $entry['old'], $entry['new']);
            $content = $result['content'];
            $total += $result['count'];
        }

        if ($total === 0) {
            continue;
        }

        $relative = ltrim(substr($file, strlen($repoRoot)), DIRECTORY_SEPARATOR);
        $codeFiles[$relative] = $total;

        if ($apply) {
            $written = file_put_contents($file, $content);
            if ($written === false) {
                fwrite(STDERR, "[tuya-propagate-ids] ERROR: no se pudo escribir {$relative}\n");
                exit(1);
            }
        }
    }

    echo "Código (--code" . ($apply ? ', aplicado' : ', dry-run; no se escribe') . "):\n";
    if ($codeFiles === []) {
        echo "  (sin coincidencias)\n";
    }
    foreach ($codeFiles as $relative => $count) {
        printf("  %-50s %d sustitución(es)%s\n", $relative, $count, $apply ? '' : ' [pendiente]');
    }
    echo "\n";
}

// ---------------------------------------------------------------------------
// 3) Resumen
// ---------------------------------------------------------------------------
echo "Resumen:\n";
printf("  BD:      %d a actualizar, %d skip (ya migrado), %d warn\n", count($plan), count($skips), count($warns));
if ($apply) {
    printf("  BD:      %d fila(s) actualizada(s)\n", $updated);
}
if ($codeMode) {
    echo '  Código:  ' . count($codeFiles) . ' fichero(s) ' . ($apply ? 'modificado(s)' : 'a modificar') . "\n";
}
if ($rollbackPath !== null) {
    echo "  Rollback: {$rollbackPath}\n";
} elseif ($plan !== []) {
    echo "  Rollback: se generará al usar --apply\n";
}
if (!$apply) {
    echo "\n  [DRY-RUN] Nada se ha escrito. Añade --apply para ejecutar.\n";
}
echo "\n";
exit(0);
