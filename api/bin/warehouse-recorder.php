<?php
declare(strict_types=1);

/**
 * bin/warehouse-recorder.php — Recorder daemon for the drinks warehouse (F64/RF-73).
 *
 * Long-running process (systemd: cerraduras-warehouse-recorder.service). Each
 * tick (1 s) it:
 *   1. Fires X/M deadline signals through WarehouseRecordingService::tickDeadlines().
 *   2. Starts PENDING recordings (unless discard_requested) with ffmpeg.
 *   3. Stops RECORDING rows with stop_requested: finalizes (SAVE) or deletes (DISCARD).
 *   4. Reconciles rows whose ffmpeg process died unexpectedly (FAILED).
 *
 * It is the ONLY writer of file_path/poster_path/size_bytes/pid/status after a row
 * leaves PENDING. RTSP URLs (with credentials) are never logged.
 *
 * Usage: php bin/warehouse-recorder.php
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Domain\Warehouse\WarehouseRecordingService;
use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

const TICK_US      = 1_000_000;
const STOP_TIMEOUT = 8; // seconds to wait for a graceful ffmpeg stop

$ffmpeg = Config::get('WAREHOUSE_FFMPEG', 'ffmpeg') ?? 'ffmpeg';
$storageRoot = __DIR__ . '/../data/cameras';
if (!is_dir($storageRoot) && !@mkdir($storageRoot, 0775, true) && !is_dir($storageRoot)) {
    fwrite(STDERR, "[warehouse-recorder] cannot create {$storageRoot}\n");
    exit(1);
}

$pdo = PdoFactory::make();
$service = new WarehouseRecordingService($pdo);

// F73/RF-85: tope de duración de grabaciones (0 = sin límite).
$maxRecordingSeconds = Config::getInt('WAREHOUSE_MAX_RECORDING_SECONDS', 0) ?? 0;
if ($maxRecordingSeconds > 0) {
    fwrite(STDERR, "[warehouse-recorder] recording cap active: {$maxRecordingSeconds}s\n");
}

/** @var array<int, resource> $procs recording_id => ffmpeg process resource */
$procs = [];
$log = static function (string $msg): void {
    fwrite(STDERR, '[warehouse-recorder ' . gmdate('Y-m-d\TH:i:s\Z') . '] ' . $msg . "\n");
};

$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running) { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running) { $running = false; });
}

$log('started');

while ($running) {
    try {
        $service->tickDeadlines();
        // F73/RF-85: marca para parar los clips que superan el tope; el propio
        // tick los finaliza como SAVED al procesar stop_requested.
        $service->enforceRecordingCap($maxRecordingSeconds);
        $pdo->beginTransaction();

        // 1. Start pending recordings.
        // F85/RF-109.4: no arrancar un PENDING si el mismo device ya tiene una fila
        // RECORDING (en la re-entrada la grabación anterior sigue viva hasta que se
        // procesa su stop en este mismo tick). El PENDING se recoge en un tick
        // posterior; evita dos ffmpeg sobre la misma cámara.
        $busyDevices = [];
        foreach ($pdo->query(
            "SELECT DISTINCT device_id FROM camera_recordings WHERE status='RECORDING'"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [] as $busyId) {
            $busyDevices[(int) $busyId] = true;
        }
        foreach ($pdo->query(
            "SELECT id, room_id, device_id, position, episode, visit_id
             FROM camera_recordings
             WHERE status='PENDING' AND discard_requested=0 AND stop_requested=0
             ORDER BY id ASC LIMIT 20"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if (isset($busyDevices[(int) $r['device_id']])) {
                continue;
            }
            startRecording($pdo, $procs, $log, $ffmpeg, $storageRoot, $r);
        }

        // 2. Process stop requests.
        foreach ($pdo->query(
            "SELECT id, room_id, device_id, position, episode, file_path, pid, discard_requested
             FROM camera_recordings
             WHERE status='RECORDING' AND stop_requested=1
             ORDER BY id ASC LIMIT 50"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            stopRecording($pdo, $procs, $log, $r);
        }

        // 3. Reconcile dead processes without a stop request.
        foreach ($pdo->query(
            "SELECT id, pid, file_path FROM camera_recordings WHERE status='RECORDING' AND stop_requested=0"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $id = (int) $r['id'];
            $inMemory = isset($procs[$id]);
            if (!$inMemory && !isProcessAlive((int) $r['pid'])) {
                finalizeFailed($pdo, $log, $id, (string) $r['file_path'], 'ffmpeg_exited');
            }
        }

        // 3b. F77.6: matar ffmpeg gestionados cuyo registro ya NO existe en la BD
        // (p. ej. tras una purga F73 que borra las filas mientras grababan). Sin
        // esto, el proceso seguía escribiendo a un fichero borrado e inmóvil,
        // reteniendo GB de disco hasta reiniciar el recorder.
        if ($procs !== []) {
            $liveIds = [];
            foreach ($pdo->query(
                "SELECT id FROM camera_recordings WHERE status IN ('PENDING','RECORDING')"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [] as $liveId) {
                $liveIds[(int) $liveId] = true;
            }
            foreach (array_keys($procs) as $managedId) {
                if (isset($liveIds[$managedId])) {
                    continue;
                }
                $proc = $procs[$managedId];
                if (is_resource($proc)) {
                    @proc_terminate($proc, 2);
                    usleep(200_000);
                    $st = proc_get_status($proc);
                    if (is_array($st) && ($st['running'] ?? false)) {
                        @proc_terminate($proc, 9);
                    }
                    @proc_close($proc);
                }
                unset($procs[$managedId]);
                $log("recording {$managedId}: abortado (registro ya no existe en BD)");
            }
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $log('tick error: ' . $e->getMessage());
    }

    usleep(TICK_US);
}

// Graceful shutdown: stop every managed process.
foreach ($procs as $pid => $proc) {
    @proc_terminate($proc, 2);
}
$log('stopped');
exit(0);

// ---------------------------------------------------------------------------

/**
 * @param array<int, resource> $procs
 * @param array<string,mixed> $r
 */
function startRecording(\PDO $pdo, array &$procs, callable $log, string $ffmpeg, string $storageRoot, array $r): void
{
    $id = (int) $r['id'];
    $rtsp = resolveRtsp($pdo, (int) $r['device_id']);
    if ($rtsp === null) {
        $pdo->prepare("UPDATE camera_recordings SET status='FAILED', error='no_rtsp_url' WHERE id=:id")
            ->execute([':id' => $id]);
        $log("recording {$id}: no rtsp_url");
        return;
    }

    $dir = $storageRoot . '/' . (int) $r['room_id'] . '/' . gmdate('Ymd');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        $pdo->prepare("UPDATE camera_recordings SET status='FAILED', error='mkdir_failed' WHERE id=:id")
            ->execute([':id' => $id]);
        return;
    }

    $base = ($r['visit_id'] !== null ? (int) $r['visit_id'] : 'x') . '_' . $r['position'] . '_' . $r['episode'] . '_' . time();
    $tmp  = $dir . '/' . $base . '.tmp.mp4';

    $cmd = [
        $ffmpeg, '-nostdin', '-rtsp_transport', 'tcp', '-i', $rtsp,
        '-c:v', 'copy', '-an', '-f', 'mp4', '-movflags', '+faststart', '-y', $tmp,
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', $dir . '/' . $base . '.ffmpeg.log', 'w'],
    ];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        $pdo->prepare("UPDATE camera_recordings SET status='FAILED', error='ffmpeg_spawn' WHERE id=:id")
            ->execute([':id' => $id]);
        $log("recording {$id}: ffmpeg spawn failed");
        return;
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    $status = proc_get_status($proc);
    $pid = (int) ($status['pid'] ?? 0);
    $procs[$id] = $proc;

    $pdo->prepare(
        "UPDATE camera_recordings SET status='RECORDING', pid=:pid, started_at=UTC_TIMESTAMP(3), file_path=:fp WHERE id=:id"
    )->execute([':pid' => $pid, ':fp' => relativePath($storageRoot, $tmp), ':id' => $id]);
    $log("recording {$id}: started (pid={$pid})");
}

/**
 * @param array<int, resource> $procs
 * @param array<string,mixed> $r
 */
function stopRecording(\PDO $pdo, array &$procs, callable $log, array $r): void
{
    $id = (int) $r['id'];
    $discard = (int) $r['discard_requested'] === 1;
    $tmpRel = (string) ($r['file_path'] ?? '');
    $tmpAbs = absolutePath($tmpRel);

    // Ask ffmpeg to finalize (SIGINT) and wait for it.
    if (isset($procs[$id]) && is_resource($procs[$id])) {
        @proc_terminate($procs[$id], 2);
        $deadline = time() + STOP_TIMEOUT;
        while (time() < $deadline) {
            $st = proc_get_status($procs[$id]);
            if (!$st['running']) {
                break;
            }
            usleep(100_000);
        }
        if (isset($st['running']) && $st['running']) {
            @proc_terminate($procs[$id], 9);
        }
        @proc_close($procs[$id]);
        unset($procs[$id]);
    } elseif ((int) ($r['pid'] ?? 0) > 0) {
        @exec('kill -INT ' . (int) $r['pid'] . ' 2>/dev/null');
    }

    if ($discard) {
        if ($tmpAbs !== null && is_file($tmpAbs)) {
            @unlink($tmpAbs);
        }
        $pdo->prepare("UPDATE camera_recordings SET status='DISCARDED', stopped_at=UTC_TIMESTAMP(3), pid=NULL WHERE id=:id")
            ->execute([':id' => $id]);
        $log("recording {$id}: discarded");
        return;
    }

    if ($tmpAbs === null || !is_file($tmpAbs)) {
        $pdo->prepare("UPDATE camera_recordings SET status='FAILED', error='missing_file', stopped_at=UTC_TIMESTAMP(3), pid=NULL WHERE id=:id")
            ->execute([':id' => $id]);
        $log("recording {$id}: missing tmp file");
        return;
    }

    $finalAbs = preg_replace('/\.tmp\.mp4$/', '.mp4', $tmpAbs) ?? $tmpAbs;
    if (!@rename($tmpAbs, $finalAbs)) {
        $pdo->prepare("UPDATE camera_recordings SET status='FAILED', error='rename_failed', stopped_at=UTC_TIMESTAMP(3), pid=NULL WHERE id=:id")
            ->execute([':id' => $id]);
        $log("recording {$id}: rename failed");
        return;
    }

    $size = @filesize($finalAbs) ?: 0;
    $posterAbs = preg_replace('/\.mp4$/', '.poster.jpg', $finalAbs) ?? '';
    $posterRel = null;
    if ($posterAbs !== '' && makePoster($finalAbs, $posterAbs)) {
        $posterRel = relativePathFromApi($posterAbs);
    }
    $duration = durationSeconds($pdo, $id, (string) $r['started_at'] ?? null);

    $pdo->prepare(
        "UPDATE camera_recordings
         SET status='SAVED', stopped_at=UTC_TIMESTAMP(3), duration_s=:dur,
             file_path=:fp, poster_path=:pp, size_bytes=:sz, pid=NULL
         WHERE id=:id"
    )->execute([
        ':dur' => $duration,
        ':fp' => relativePathFromApi($finalAbs),
        ':pp' => $posterRel,
        ':sz' => $size,
        ':id' => $id,
    ]);
    $log("recording {$id}: saved (" . $size . ' bytes)');
}

function finalizeFailed(\PDO $pdo, callable $log, int $id, string $tmpRel, string $error): void
{
    $tmpAbs = absolutePath($tmpRel);
    if ($tmpAbs !== null && is_file($tmpAbs)) {
        @unlink($tmpAbs);
    }
    $pdo->prepare("UPDATE camera_recordings SET status='FAILED', error=:e, stopped_at=UTC_TIMESTAMP(3), pid=NULL WHERE id=:id")
        ->execute([':e' => $error, ':id' => $id]);
    $log("recording {$id}: FAILED ({$error})");
}

function resolveRtsp(\PDO $pdo, int $deviceId): ?string
{
    $stmt = $pdo->prepare("SELECT meta_json FROM devices WHERE id=:id AND kind='CAMERA' LIMIT 1");
    $stmt->execute([':id' => $deviceId]);
    $json = $stmt->fetchColumn();
    if ($json === false || $json === null) {
        return null;
    }
    $meta = json_decode((string) $json, true);
    if (!is_array($meta)) {
        return null;
    }
    if (array_key_exists('enabled', $meta) && $meta['enabled'] === false) {
        return null;
    }
    $url = $meta['rtsp_url'] ?? null;
    return (is_string($url) && $url !== '') ? $url : null;
}

function makePoster(string $video, string $poster): bool
{
    $cmd = ['ffmpeg', '-nostdin', '-y', '-i', $video, '-frames:v', '1', '-q:v', '3', $poster];
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($proc)) {
        return false;
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    $deadline = time() + STOP_TIMEOUT;
    while (time() < $deadline) {
        if (!proc_get_status($proc)['running']) {
            break;
        }
        usleep(100_000);
    }
    proc_close($proc);
    return is_file($poster);
}

function durationSeconds(\PDO $pdo, int $id, ?string $startedAt): int
{
    $stmt = $pdo->prepare('SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, UTC_TIMESTAMP(3))) FROM camera_recordings WHERE id=:id');
    $stmt->execute([':id' => $id]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function isProcessAlive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    $out = [];
    $rc = 1;
    @exec('kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
    return $rc === 0;
}

/** Return path relative to api/ (e.g. data/cameras/...). */
function relativePathFromApi(string $abs): string
{
    $api = dirname(__DIR__);
    return ltrim(str_replace($api, '', $abs), '/');
}

function relativePath(string $storageRoot, string $abs): string
{
    return relativePathFromApi($abs);
}

function absolutePath(string $rel): ?string
{
    if ($rel === '') {
        return null;
    }
    $api = dirname(__DIR__);
    $abs = $rel[0] === '/' ? $rel : $api . '/' . $rel;
    $real = realpath(dirname($abs));
    if ($real === false) {
        return $abs; // parent may not exist yet (tmp write path)
    }
    return $real . '/' . basename($abs);
}
