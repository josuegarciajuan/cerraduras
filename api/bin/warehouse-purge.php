<?php
declare(strict_types=1);

/**
 * bin/warehouse-purge.php — Purga total del almacén de bebidas (F73/RF-86).
 *
 * Elimina, para una sala ALMACEN_BEBIDAS (o todas):
 *   - filas de camera_recordings, warehouse_visits y warehouse_state;
 *   - ficheros bajo data/cameras/<room_id>/.
 *
 * Recomendado parar el recorder antes (evita procesos ffmpeg huérfanos):
 *   systemctl stop cerraduras-warehouse-recorder
 *   php bin/warehouse-purge.php --room=12        # o --all
 *   systemctl start cerraduras-warehouse-recorder
 *
 * Solo borra ficheros cuya ruta real quede dentro de data/cameras/.
 * No expone ninguna ruta HTTP: es una herramienta de mantenimiento.
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$pdo = PdoFactory::make();
$api = dirname(__DIR__);
$storageRoot = realpath($api . '/data/cameras') ?: ($api . '/data/cameras');
$rootReal = realpath($storageRoot);

$roomArg = null;
$all = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--all') {
        $all = true;
    } elseif (preg_match('/^--room=(\d+)$/', $arg, $m)) {
        $roomArg = (int) $m[1];
    } else {
        fwrite(STDERR, "[warehouse-purge] unknown argument: {$arg}\n");
        exit(2);
    }
}

// Resolve the target rooms (all warehouse rooms by default).
$stmt = $pdo->query(
    "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
     WHERE rt.code = 'ALMACEN_BEBIDAS' ORDER BY r.id ASC"
);
$rooms = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
if ($roomArg !== null) {
    $rooms = in_array($roomArg, $rooms, true) ? [$roomArg] : [];
}
if (!$all && $roomArg === null) {
    // Default: every warehouse room.
    $all = true;
}
if ($rooms === []) {
    fwrite(STDOUT, "[warehouse-purge] no warehouse rooms to purge\n");
    exit(0);
}

$totalRecordings = 0;
$totalVisits = 0;
$totalState = 0;
$totalFiles = 0;

foreach ($rooms as $roomId) {
    // 1. Ask the recorder to stop and discard any active recording.
    $pdo->prepare(
        "UPDATE camera_recordings SET stop_requested=1, discard_requested=1
         WHERE room_id=:r AND status IN ('PENDING','RECORDING')"
    )->execute([':r' => $roomId]);

    // 2. Remove files for the room (path-traversal safe).
    $dir = $rootReal !== false ? $rootReal . '/' . $roomId : null;
    if ($dir !== null && is_dir($dir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $path) {
            $real = realpath((string) $path);
            if ($real === false || $rootReal === false || strpos($real, $rootReal) !== 0) {
                continue;
            }
            if ($path->isDir()) {
                @rmdir($real);
            } else {
                if (@unlink($real)) {
                    $totalFiles++;
                }
            }
        }
        @rmdir($dir);
    }

    // 3. Delete rows (FK-safe: state → recordings → visits).
    $s = $pdo->prepare('DELETE FROM warehouse_state WHERE room_id=:r');
    $s->execute([':r' => $roomId]);
    $totalState += $s->rowCount();

    $s = $pdo->prepare('DELETE FROM camera_recordings WHERE room_id=:r');
    $s->execute([':r' => $roomId]);
    $totalRecordings += $s->rowCount();

    $s = $pdo->prepare('DELETE FROM warehouse_visits WHERE room_id=:r');
    $s->execute([':r' => $roomId]);
    $totalVisits += $s->rowCount();
}

fwrite(
    STDOUT,
    "[warehouse-purge] rooms=" . count($rooms)
    . " recordings={$totalRecordings} visits={$totalVisits} state={$totalState} files={$totalFiles}\n"
);
exit(0);
