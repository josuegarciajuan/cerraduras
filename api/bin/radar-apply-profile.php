<?php
declare(strict_types=1);

/**
 * F114+ — re-aplica el perfil de calibración guardado del radar del almacén.
 *
 * Uso puntual (nunca en bucle ni desde un temporizador):
 *   php bin/radar-apply-profile.php --room=14
 *   php bin/radar-apply-profile.php --room=14 --dry-run
 *
 * Toma `devices.meta_json.calibration` (o `calibration_previous` si aún no se
 * ha vuelto a confirmar) y lo publica por MQTT. Espera la confirmación real por
 * push; no consulta Tuya ni crea pollers.
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepository;
use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService;
use App\Support\Config;

$opts = getopt('', ['room:', 'dry-run']);
$roomId = isset($opts['room']) ? (int) $opts['room'] : 0;
$dryRun = array_key_exists('dry-run', $opts);

Config::load(dirname(__DIR__) . '/.env');
$pdo = PdoFactory::make();

if ($roomId <= 0) {
    $roomId = (int) ($pdo->query(
        "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
          WHERE rt.code = 'ALMACEN_BEBIDAS' ORDER BY r.id ASC LIMIT 1"
    )->fetchColumn() ?: 0);
}
if ($roomId <= 0) {
    fwrite(STDERR, "No hay sala ALMACEN_BEBIDAS; usa --room=<id>\n");
    exit(2);
}

$repo = new DeviceRepository($pdo);
$device = $repo->findForRoomKind($roomId, Device::KIND_PRESENCE);
if ($device === null) {
    fwrite(STDERR, "No hay radar de presencia en la sala {$roomId}\n");
    exit(1);
}

$meta = is_array($device->meta) ? $device->meta : [];
$profile = null;
$source = null;
foreach (['calibration', 'calibration_previous'] as $key) {
    if (is_array($meta[$key] ?? null)) {
        $profile = $meta[$key];
        $source = $key;
        break;
    }
}
if ($profile === null) {
    fwrite(STDERR, "La ficha del radar no tiene calibración guardada; ejecuta primero /sensores\n");
    exit(1);
}

$commands = [];
foreach (['maximum_range', 'minimum_range', 'radar_sensitivity', 'detection_delay', 'fading_time'] as $key) {
    if (isset($profile[$key]) && is_numeric($profile[$key])) {
        $commands[$key] = (float) $profile[$key];
    }
}
$fading = Config::get('RADAR_WAREHOUSE_FADING_TIME', '5.0') ?? '5.0';
if (!is_numeric($fading)) {
    $fading = '5.0';
}
$commands['fading_time'] = max(0.5, min(1500.0, (float) $fading));

if ($commands === []) {
    fwrite(STDERR, "La calibración guardada no tiene parámetros utilizables\n");
    exit(1);
}

if ($dryRun) {
    echo json_encode([
        'ok' => true,
        'dry_run' => true,
        'room_id' => $roomId,
        'device_id' => (int) $device->id,
        'profile_source' => $source,
        'commands' => $commands,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$service = new ZigbeeRadarCalibrationService($pdo);
$result = $service->apply($roomId, [
    'id' => (int) $device->id,
    'external_id' => (string) $device->externalId,
    'meta' => $meta,
], $commands, true, 45);

echo json_encode([
    'ok' => (bool) ($result['applied'] ?? false),
    'room_id' => $roomId,
    'device_id' => (int) $device->id,
    'profile_source' => $source,
    'commands' => $commands,
    'result' => $result,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit(($result['applied'] ?? false) === true ? 0 : 1);
