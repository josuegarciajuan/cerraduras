<?php
declare(strict_types=1);

/**
 * F110 — prepara una prueba limpia de sensores para una sala.
 *
 * Hace SOLO limpieza de estado local:
 *  - copia de seguridad de las corridas y del meta del radar;
 *  - abandona las corridas que sigan en `running`;
 *  - cancela intentos activos;
 *  - borra `calibration` y `calibration_previous` del radar;
 *  - asegura que la pausa manual está desactivada.
 *
 * No aplica la base del radar, no arranca la corrida y no toca la luz: eso se
 * hace después con la API, tal como describe docs/ops-sensores.md §12.
 *
 * Uso:
 *   php bin/sensor-test-prepare.php --room=14
 *   php bin/sensor-test-prepare.php --room=14 --dry-run
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepository;
use App\Domain\Presence\RoomAutomationPauseRepository;
use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Sensors\SensorRunStore;
use App\Support\Config;

$opts = getopt('', ['room:', 'dry-run']);
$roomId = isset($opts['room']) ? (int) $opts['room'] : 0;
$dryRun = array_key_exists('dry-run', $opts);
if ($roomId <= 0) {
    fwrite(STDERR, "Uso: php bin/sensor-test-prepare.php --room=14 [--dry-run]\n");
    exit(2);
}

Config::load(dirname(__DIR__) . '/.env');
$pdo = PdoFactory::make();
$store = new SensorRunStore(dirname(__DIR__) . '/run/sensores');
$repo = new DeviceRepository($pdo);
$device = $repo->findForRoomKind($roomId, Device::KIND_PRESENCE);
if ($device === null) {
    fwrite(STDERR, "No hay radar de presencia en la sala {$roomId}\n");
    exit(1);
}

$backupDir = '/tmp/f110-prep-' . gmdate('Ymd\THis\Z');
$backupFiles = [];
if (!$dryRun) {
    if (!is_dir($backupDir) && !mkdir($backupDir, 0770, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "No se pudo crear {$backupDir}\n");
        exit(1);
    }
    foreach (glob(dirname(__DIR__) . "/run/sensores/{$roomId}-*.json") ?: [] as $file) {
        $target = $backupDir . '/' . basename($file);
        copy($file, $target);
        $backupFiles[] = $target;
    }
    $metaBackup = $backupDir . '/device-' . (int) $device->id . '-meta.json';
    file_put_contents($metaBackup, json_encode($device->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    $backupFiles[] = $metaBackup;
}

$abandoned = [];
$cancelled = [];
foreach (glob(dirname(__DIR__) . "/run/sensores/{$roomId}-*.json") ?: [] as $file) {
    $run = json_decode((string) @file_get_contents($file), true);
    if (!is_array($run) || (int) ($run['room_id'] ?? 0) !== $roomId || ($run['status'] ?? '') !== 'running') {
        continue;
    }
    $runId = (string) ($run['run_id'] ?? basename($file, '.json'));
    if ($dryRun) {
        $abandoned[] = $runId;
        foreach ((array) ($run['steps'] ?? []) as $stepId => $step) {
            if (is_array($step['active_attempt'] ?? null)) {
                $cancelled[] = $runId . ':' . $stepId;
            }
        }
        continue;
    }
    $store->withLock($runId, static function (array $r) use ($runId, &$cancelled): array {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        foreach ((array) ($r['steps'] ?? []) as $stepId => $step) {
            $active = $step['active_attempt'] ?? null;
            if (!is_array($active)) {
                continue;
            }
            $active['status'] = 'CANCELLED';
            $active['checked_at'] = $now;
            $active['verdict'] = ['status' => 'SKIP', 'reason' => 'operator_restart_prepare', 'details' => []];
            $active['check_id'] = 'prepare-' . bin2hex(random_bytes(4));
            $step['attempts'][] = $active;
            unset($step['active_attempt']);
            $step['status'] = 'SKIP';
            $r['steps'][$stepId] = $step;
            $cancelled[] = $runId . ':' . $stepId;
        }
        $r['status'] = 'abandoned';
        $r['abandoned_at'] = $now;
        $r['abandon_reason'] = 'operator_restart_prepare';
        $r['updated_at'] = $now;
        $r['verdict'] = [
            'verdict' => 'INCOMPLETE',
            'devices' => [],
            'reasons' => ['operator_restart_prepare'],
            'soak' => (string) ($r['soak']['status'] ?? 'PENDING'),
        ];
        return $r;
    });
    $store->appendLog($runId, ['type' => 'run_abandoned', 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'reason' => 'operator_restart_prepare']);
    $abandoned[] = $runId;
}

$meta = is_array($device->meta) ? $device->meta : [];
$cleared = [];
foreach (['calibration', 'calibration_previous'] as $key) {
    if (array_key_exists($key, $meta)) {
        unset($meta[$key]);
        $cleared[] = $key;
    }
}
if (!$dryRun && $cleared !== []) {
    $repo->update((int) $device->id, ['meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}
if (!$dryRun) {
    (new RoomAutomationPauseRepository($pdo))->resume($roomId);
}

echo json_encode([
    'ok' => true,
    'dry_run' => $dryRun,
    'room_id' => $roomId,
    'presence_device_id' => (int) $device->id,
    'backup_dir' => $dryRun ? null : $backupDir,
    'backup_files' => $backupFiles,
    'abandoned_runs' => $abandoned,
    'cancelled_attempts' => $cancelled,
    'cleared_meta' => $cleared,
    'paused' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
