<?php
declare(strict_types=1);

/**
 * bin/seed-warehouse-test.php — Montaje de pruebas del almacén en PROTO2.
 *
 * Convierte la habitación PROTO2 en tipo ALMACEN_BEBIDAS y añade dos cámaras
 * CAMERA a su pack (exterior "Puerta", interior "tests"), resolviendo sus URLs
 * RTSP desde la base de datos del proyecto de reconocimiento facial.
 *
 * Las URLs contienen credenciales y NUNCA se imprimen completas (se enmascaran).
 * Las cámaras se crean DESACTIVADAS (enabled/record_enabled=false) para no
 * disparar grabaciones durante la regresión E2E (PROTO2 es la sala del runner);
 * se activan a mano desde /almacen antes de probar.
 *
 * Uso:
 *   php bin/seed-warehouse-test.php --rf-prod            # lee la BD RF de producción por SSH
 *   php bin/seed-warehouse-test.php --rf-env=/ruta/.env  # lee una BD RF local
 *   php bin/seed-warehouse-test.php --exterior-url=rtsp://... --interior-url=rtsp://...
 *   php bin/seed-warehouse-test.php ... --dry-run        # no escribe nada
 *
 * Idempotente: se puede ejecutar varias veces.
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

const ROOM_CODE   = 'PROTO2';
const WAREHOUSE_TYPE_CODE = 'ALMACEN_BEBIDAS';
const EXT_CAMERA_NAME = 'Puerta';
const INT_CAMERA_NAME = 'tests';

$opts = parseArgs($argv);
$dryRun = isset($opts['dry-run']);
$rfEnvPath = $opts['rf-env'] ?? '/root/reconocimientoFacial/.env';

$exteriorUrl = $opts['exterior-url'] ?? null;
$interiorUrl = $opts['interior-url'] ?? null;

if ($exteriorUrl === null || $interiorUrl === null) {
    $rfEnv = parseEnvFile($rfEnvPath);
    if (isset($opts['rf-prod'])) {
        $resolved = resolveUrlsViaProdSsh($rfEnv);
    } else {
        $resolved = resolveUrlsViaLocalDb($rfEnv);
    }
    $exteriorUrl = $exteriorUrl ?? ($resolved[EXT_CAMERA_NAME] ?? null);
    $interiorUrl = $interiorUrl ?? ($resolved[INT_CAMERA_NAME] ?? null);
}

if ($exteriorUrl === null || $interiorUrl === null) {
    fwrite(STDERR, "[seed] ERROR: no se pudieron resolver las URLs RTSP (exterior={$EXT_CAMERA_NAME}, interior={$INT_CAMERA_NAME}).\n");
    fwrite(STDERR, "[seed] Usa --rf-prod, --rf-env=... o --exterior-url/--interior-url.\n");
    exit(1);
}

$pdo = PdoFactory::make();

$room = fetchOne($pdo, 'SELECT id, code, room_type_id, pack_id FROM rooms WHERE code = :c LIMIT 1', [':c' => ROOM_CODE]);
if ($room === null) {
    fwrite(STDERR, "[seed] ERROR: no existe la habitación " . ROOM_CODE . "\n");
    exit(1);
}
$roomId = (int) $room['id'];
$packId = $room['pack_id'] !== null ? (int) $room['pack_id'] : null;
if ($packId === null) {
    fwrite(STDERR, "[seed] ERROR: " . ROOM_CODE . " no tiene pack asignado\n");
    exit(1);
}

$whType = fetchOne($pdo, 'SELECT id, code FROM room_types WHERE code = :c LIMIT 1', [':c' => WAREHOUSE_TYPE_CODE]);
if ($whType === null) {
    fwrite(STDERR, "[seed] ERROR: no existe el tipo " . WAREHOUSE_TYPE_CODE . " (ejecuta php bin/migrate.php)\n");
    exit(1);
}
$whTypeId = (int) $whType['id'];

echo "[seed] PROTO2 = room {$roomId}, pack {$packId}, tipo actual {$room['room_type_id']} → {$whTypeId} (" . WAREHOUSE_TYPE_CODE . ")\n";
echo "[seed] exterior (" . EXT_CAMERA_NAME . "): " . maskUrl((string) $exteriorUrl) . "\n";
echo "[seed] interior (" . INT_CAMERA_NAME . "): " . maskUrl((string) $interiorUrl) . "\n";

if ($dryRun) {
    echo "[seed] --dry-run: no se escribe nada.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE rooms SET room_type_id = :t WHERE id = :id')
        ->execute([':t' => $whTypeId, ':id' => $roomId]);

    upsertCamera($pdo, $packId, 'EXTERIOR', 'rf-puerta', 'Puerta (RF)', (string) $exteriorUrl);
    upsertCamera($pdo, $packId, 'INTERIOR', 'rf-tests', 'tests (RF)', (string) $interiorUrl);

    // El tipo de almacén necesita una franja RENTABLE para que la emisión de QR
    // (flujo de huésped que usa la batería E2E del runner, ahora sobre PROTO2) no
    // devuelva slot_not_rentable. Idempotente; solo afecta al tipo de pruebas.
    $hasSlot = (int) $pdo->query(
        "SELECT COUNT(*) FROM time_slots WHERE room_type_id = {$whTypeId} AND kind = 'RENTABLE'"
    )->fetchColumn();
    if ($hasSlot === 0) {
        $pdo->prepare(
            "INSERT INTO time_slots (room_type_id, starts_at, ends_at, kind)
             VALUES (:rt, '00:00:00', '23:59:59', 'RENTABLE')"
        )->execute([':rt' => $whTypeId]);
        echo "[seed]   añadida franja RENTABLE 00:00-24:00 al tipo " . WAREHOUSE_TYPE_CODE . "\n";
    }

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[seed] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

echo "[seed] OK. PROTO2 ahora es " . WAREHOUSE_TYPE_CODE . " con 2 cámaras (desactivadas).\n";
echo "[seed] Actívalas en /almacen (enabled/record_enabled) antes de probar.\n";
exit(0);

// ---------------------------------------------------------------------------

/** @return array<string,string> */
function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, '--') !== 0) {
            continue;
        }
        $arg = substr($arg, 2);
        if (strpos($arg, '=') !== false) {
            [$k, $v] = explode('=', $arg, 2);
            $out[$k] = $v;
        } else {
            $out[$arg] = '1';
        }
    }
    return $out;
}

/** @return array<string,string> */
function parseEnvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v);
    }
    return $out;
}

/**
 * Query the local RF DB using RF_DB_* from the given env file.
 * @param array<string,string> $env
 * @return array<string,string>  camera name => rtsp url (unmasked)
 */
function resolveUrlsViaLocalDb(array $env): array
{
    $host = $env['RF_DB_HOST'] ?? '127.0.0.1';
    $name = $env['RF_DB_NAME'] ?? 'reconocimientofacial';
    $user = $env['RF_DB_USER'] ?? '';
    $pass = $env['RF_DB_PASS'] ?? '';
    try {
        $pdo = new PDO("mysql:host={$host};dbname={$name};charset=latin1", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (\Throwable $e) {
        fwrite(STDERR, "[seed] aviso: no se pudo conectar a la BD RF local: " . $e->getMessage() . "\n");
        return [];
    }
    return queryCameras($pdo);
}

/**
 * Query the production RF DB over SSH (read-only), using RF_PROD_* env.
 * @param array<string,string> $env
 * @return array<string,string>
 */
function resolveUrlsViaProdSsh(array $env): array
{
    $host = $env['RF_PROD_HOST'] ?? '';
    $user = $env['RF_PROD_SSH_USER'] ?? 'root';
    $pass = $env['RF_PROD_SSH_PASS'] ?? '';
    $port = $env['RF_PROD_SSH_PORT'] ?? '22';
    $path = $env['RF_PROD_PATH'] ?? '/root/reconocimientoFacial';
    if ($host === '' || $pass === '') {
        fwrite(STDERR, "[seed] aviso: RF_PROD_HOST/RF_PROD_SSH_PASS no configurados en el fichero RF .env\n");
        return [];
    }

    $remotePhp = <<<'PHPCODE'
<?php
require "config/rutas.php";
$pdo = new PDO("mysql:host=" . BD_HOST . ";dbname=" . BD_BBDD . ";charset=latin1", BD_USUARIO, BD_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$st = $pdo->prepare("SELECT descripcion, url_conexion FROM camaras WHERE descripcion IN (?, ?)");
$st->execute([$argv[1], $argv[2]]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { echo $r["descripcion"] . "\t" . $r["url_conexion"] . "\n"; }
PHPCODE;

    $cmd = [
        'sshpass', '-p', $pass,
        'ssh', '-p', $port, '-o', 'StrictHostKeyChecking=accept-new', '-o', 'ConnectTimeout=20',
        "{$user}@{$host}",
        "cd " . escapeshellarg($path) . " && php -- " . escapeshellarg(EXT_CAMERA_NAME) . " " . escapeshellarg(INT_CAMERA_NAME),
    ];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "[seed] aviso: no se pudo lanzar SSH a prod\n");
        return [];
    }
    fwrite($pipes[0], $remotePhp);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);
    if ($rc !== 0) {
        fwrite(STDERR, "[seed] aviso: consulta a prod falló (rc={$rc}): " . trim((string) $stderr) . "\n");
        return [];
    }
    $out = [];
    foreach (explode("\n", (string) $stdout) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, "\t") === false) {
            continue;
        }
        [$name, $url] = explode("\t", $line, 2);
        $out[trim($name)] = trim($url);
    }
    return $out;
}

/**
 * @return array<string,string>
 */
function queryCameras(PDO $pdo): array
{
    $st = $pdo->prepare('SELECT descripcion, url_conexion FROM camaras WHERE descripcion IN (?, ?)');
    $st->execute([EXT_CAMERA_NAME, INT_CAMERA_NAME]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $out[trim((string) $r['descripcion'])] = trim((string) $r['url_conexion']);
    }
    return $out;
}

function upsertCamera(PDO $pdo, int $packId, string $subtype, string $externalId, string $label, string $rtspUrl): void
{
    $meta = json_encode([
        'rtsp_url' => $rtspUrl,
        'enabled' => false,
        'record_enabled' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $existing = fetchOne($pdo, "SELECT id FROM devices WHERE kind='CAMERA' AND external_id = :x LIMIT 1", [':x' => $externalId]);
    if ($existing !== null) {
        $pdo->prepare(
            "UPDATE devices SET pack_id=:p, subtype=:st, label=:l, meta_json=:m WHERE id=:id"
        )->execute([':p' => $packId, ':st' => $subtype, ':l' => $label, ':m' => $meta, ':id' => (int) $existing['id']]);
        echo "[seed]   actualizada cámara {$externalId} ({$subtype})\n";
        return;
    }
    $pdo->prepare(
        "INSERT INTO devices (pack_id, kind, subtype, external_id, label, meta_json)
         VALUES (:p, 'CAMERA', :st, :x, :l, :m)"
    )->execute([':p' => $packId, ':st' => $subtype, ':x' => $externalId, ':l' => $label, ':m' => $meta]);
    echo "[seed]   creada cámara {$externalId} ({$subtype})\n";
}

/** @param array<string,mixed> $params @return array<string,mixed>|null */
function fetchOne(PDO $pdo, string $sql, array $params): ?array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function maskUrl(string $url): string
{
    $p = parse_url($url);
    if ($p === false) {
        return '(url inválida)';
    }
    return ($p['scheme'] ?? '?') . '://' . (isset($p['user']) ? 'user:***@' : '')
        . ($p['host'] ?? '?') . (isset($p['port']) ? ':' . $p['port'] : '')
        . ($p['path'] ?? '') . (isset($p['query']) ? '?' . $p['query'] : '');
}
