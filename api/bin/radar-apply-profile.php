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

function radarApplyUsage(): string
{
    return <<<TXT
Uso: php bin/radar-apply-profile.php [opciones]

Re-aplica el perfil de calibración guardado del radar de una sala.

Opciones:
  --room=<id>        Sala del radar (entero > 0). Por defecto, la primera ALMACEN_BEBIDAS.
  --profile-file=<f> Perfil JSON a aplicar (p. ej. snapshot del runner). Si no se
                     indica, usa devices.meta_json.calibration o calibration_previous.
  --fading=<seg>     Override explícito de fading_time (0.5..1500 segundos).
  --dry-run          Muestra el perfil y los comandos sin publicar por MQTT.
  -h, --help         Muestra esta ayuda y no toca la base de datos ni MQTT.

TXT;
}

// El parsing ocurre ANTES de cargar Config/PDO: un argumento inválido no debe
// abrir conexión ni publicar nada.
$roomId = 0;
$dryRun = false;
$fadingOpt = null;
$profileFile = null;
$args = array_slice($argv ?? [], 1);
for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if ($arg === '--help' || $arg === '-h') {
        echo radarApplyUsage();
        exit(0);
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if ($arg === '--room' || $arg === '--fading' || $arg === '--profile-file') {
        if (!isset($args[$i + 1])) {
            fwrite(STDERR, "Falta valor para {$arg}\n\n" . radarApplyUsage());
            exit(2);
        }
        $value = $args[++$i];
        if ($arg === '--room') {
            if (!preg_match('/^\d+$/', $value) || (int) $value <= 0) {
                fwrite(STDERR, "Valor inválido para --room: '{$value}' (entero > 0)\n\n" . radarApplyUsage());
                exit(2);
            }
            $roomId = (int) $value;
        } elseif ($arg === '--fading') {
            if (!is_numeric($value)) {
                fwrite(STDERR, "Valor inválido para --fading: '{$value}' (numérico 0.5..1500)\n\n" . radarApplyUsage());
                exit(2);
            }
            $fading = (float) $value;
            if ($fading < 0.5 || $fading > 1500.0) {
                fwrite(STDERR, "Valor fuera de rango para --fading: '{$value}' (0.5..1500)\n\n" . radarApplyUsage());
                exit(2);
            }
            $fadingOpt = $fading;
        } else {
            $profileFile = $value;
        }
        continue;
    }
    if (str_starts_with($arg, '--room=')) {
        $value = substr($arg, strlen('--room='));
        if ($value === '' || !preg_match('/^\d+$/', $value) || (int) $value <= 0) {
            fwrite(STDERR, "Valor inválido para --room: '{$value}' (entero > 0)\n\n" . radarApplyUsage());
            exit(2);
        }
        $roomId = (int) $value;
        continue;
    }
    if (str_starts_with($arg, '--profile-file=')) {
        $profileFile = substr($arg, strlen('--profile-file='));
        if ($profileFile === '') {
            fwrite(STDERR, "Valor inválido para --profile-file: vacío\n\n" . radarApplyUsage());
            exit(2);
        }
        continue;
    }
    if (str_starts_with($arg, '--fading=')) {
        $value = substr($arg, strlen('--fading='));
        if ($value === '' || !is_numeric($value)) {
            fwrite(STDERR, "Valor inválido para --fading: '{$value}' (numérico 0.5..1500)\n\n" . radarApplyUsage());
            exit(2);
        }
        $fading = (float) $value;
        if ($fading < 0.5 || $fading > 1500.0) {
            fwrite(STDERR, "Valor fuera de rango para --fading: '{$value}' (0.5..1500)\n\n" . radarApplyUsage());
            exit(2);
        }
        $fadingOpt = $fading;
        continue;
    }
    fwrite(STDERR, "Argumento desconocido: {$arg}\n\n" . radarApplyUsage());
    exit(2);
}

require __DIR__ . '/../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepository;
use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService;
use App\Support\Config;

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
if ($profileFile !== null) {
    if (!is_file($profileFile) || !is_readable($profileFile)) {
        fwrite(STDERR, "No se puede leer --profile-file: {$profileFile}\n");
        exit(1);
    }
    $decoded = json_decode((string) file_get_contents($profileFile), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "JSON inválido en --profile-file: {$profileFile}\n");
        exit(1);
    }
    $profile = is_array($decoded['profile'] ?? null) ? $decoded['profile'] : $decoded;
    $source = 'profile_file';
} else {
    foreach (['calibration', 'calibration_previous'] as $key) {
        if (is_array($meta[$key] ?? null)) {
            $profile = $meta[$key];
            $source = $key;
            break;
        }
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
// Por defecto se respeta el fading_time del perfil guardado. `--fading=`
// permite un override explícito para el A/B físico.
if ($fadingOpt !== null) {
    $commands['fading_time'] = $fadingOpt;
} elseif (!isset($commands['fading_time'])) {
    $fading = Config::get('RADAR_WAREHOUSE_FADING_TIME', '2.5') ?? '2.5';
    if (!is_numeric($fading)) {
        $fading = '2.5';
    }
    $commands['fading_time'] = max(0.5, min(1500.0, (float) $fading));
}

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
