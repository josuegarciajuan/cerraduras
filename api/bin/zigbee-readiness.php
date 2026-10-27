#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/zigbee-readiness.php — F91/TSK-91.13
 *
 * Comprobación de solo lectura antes de arrancar/instalar el banco Zigbee.
 * No publica MQTT, no escribe BD, no toca Tuya ni el hardware.
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');
$apiDir = dirname(__DIR__);
$ok = true;

function line(string $level, string $message): void
{
    $tag = $level === 'FAIL' ? 'FAIL' : ($level === 'WARN' ? 'WARN' : ' OK ');
    echo "[{$tag}] {$message}\n";
}

function resolvePath(string $apiDir, string $path): string
{
    if ($path === '') {
        return $path;
    }
    return $path[0] === '/' ? $path : $apiDir . '/' . ltrim($path, '/');
}

$ingressEnabled = Config::getBool('ZIGBEE_INGRESS_ENABLED', false);
$spoolEnabled = Config::getBool('ZIGBEE_SPOOL_ENABLED', false);
line('OK', 'ingress=' . ($ingressEnabled ? 'true' : 'false') . ' spool=' . ($spoolEnabled ? 'true' : 'false'));

$host = Config::get('ZIGBEE_MQTT_HOST', '127.0.0.1') ?? '127.0.0.1';
$port = (int) (Config::get('ZIGBEE_MQTT_PORT', '1883') ?? 1883);
$prefix = Config::get('ZIGBEE_MQTT_TOPIC_PREFIX', 'zigbee2mqtt') ?? 'zigbee2mqtt';

$mapFile = resolvePath($apiDir, Config::get('ZIGBEE_MQTT_MAP_FILE', 'config/zigbee2mqtt-devices.json') ?? 'config/zigbee2mqtt-devices.json');
$raw = is_file($mapFile) ? file_get_contents($mapFile) : false;
$map = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($map) || ($map['version'] ?? null) !== 2 || !is_array($map['devices'] ?? null) || count($map['devices']) < 3) {
    line('FAIL', 'mapa v2 inválido o incompleto: ' . $mapFile);
    $ok = false;
} else {
    line('OK', 'mapa v2 con ' . count($map['devices']) . ' dispositivos');
    foreach (array_keys($map['devices']) as $topic) {
        if (str_contains(strtolower((string) $topic), '-oficina')) {
            line('FAIL', 'topic de banco en mapa de producción: ' . $topic);
            $ok = false;
        }
    }
}

$user = Config::get('ZIGBEE_MQTT_USERNAME', '');
$passFile = Config::get('ZIGBEE_MQTT_PASSWORD_FILE', '');
$passEnv = Config::get('ZIGBEE_MQTT_PASSWORD', '');
if ($user === null || $user === '') {
    line('WARN', 'ZIGBEE_MQTT_USERNAME vacío');
}
if (($passFile === null || $passFile === '') && ($passEnv === null || $passEnv === '')) {
    line('WARN', 'sin fichero/valor de contraseña MQTT');
} elseif ($passFile !== null && $passFile !== '') {
    $resolved = resolvePath($apiDir, $passFile);
    if (!is_file($resolved) || !is_readable($resolved)) {
        line('FAIL', 'fichero de contraseña MQTT no legible: ' . $resolved);
        $ok = false;
    } else {
        line('OK', 'fichero de contraseña MQTT presente');
    }
}

$errno = 0;
$errstr = '';
$sock = @fsockopen($host, $port, $errno, $errstr, 2);
if (is_resource($sock)) {
    fclose($sock);
    line('OK', "broker alcanzable en {$host}:{$port}");
} else {
    line('FAIL', "broker no alcanzable en {$host}:{$port} ({$errstr})");
    $ok = false;
}

$helper = $apiDir . '/bin/zigbee-ingress/node_modules/mqtt/package.json';
if (is_file($helper)) {
    line('OK', 'dependencia Node mqtt instalada');
} else {
    line('FAIL', 'falta npm install en bin/zigbee-ingress');
    $ok = false;
}

try {
    $pdo = PdoFactory::make();
    $q = $pdo->query(
        "SELECT r.id, r.code, r.presence_mode_override, rt.code AS type_code
         FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
         WHERE r.code = 'almacenZigbee' LIMIT 1"
    );
    $room = $q->fetch(PDO::FETCH_ASSOC);
    if ($room === false) {
        line('FAIL', 'sala almacenZigbee no provisionada');
        $ok = false;
    } else {
        line('OK', 'sala almacenZigbee id=' . $room['id'] . ' tipo=' . $room['type_code'] . ' modo=' . ($room['presence_mode_override'] ?? 'heredado'));
        $d = $pdo->prepare(
            "SELECT d.kind, d.external_id, d.transport FROM devices d
             JOIN rooms r ON r.pack_id = d.pack_id
             WHERE r.code = 'almacenZigbee' ORDER BY d.kind"
        );
        $d->execute();
        $devices = $d->fetchAll(PDO::FETCH_ASSOC);
        if (count($devices) !== 3) {
            line('FAIL', 'se esperaban 3 dispositivos en almacenZigbee, hay ' . count($devices));
            $ok = false;
        } else {
            foreach ($devices as $dev) {
                if (($dev['transport'] ?? null) !== 'ZIGBEE2MQTT') {
                    line('FAIL', 'dispositivo ' . $dev['kind'] . ' sin transporte ZIGBEE2MQTT: ' . var_export($dev['transport'], true));
                    $ok = false;
                } else {
                    line('OK', 'dispositivo ' . $dev['kind'] . ' ' . $dev['external_id']);
                }
            }
        }
    }
} catch (Throwable $e) {
    line('FAIL', 'BD no disponible: ' . $e->getMessage());
    $ok = false;
}

$spoolDir = resolvePath($apiDir, Config::get('ZIGBEE_SPOOL_DIR', 'run/zigbee-spool') ?? 'run/zigbee-spool');
if (is_dir($spoolDir)) {
    line('OK', 'spool presente: ' . $spoolDir);
} else {
    line('WARN', 'spool aún no creado (se crea al arrancar el worker): ' . $spoolDir);
}

$statusFile = resolvePath($apiDir, Config::get('ZIGBEE_INGRESS_STATUS_FILE', 'run/zigbee-ingress-status.json') ?? 'run/zigbee-ingress-status.json');
line('OK', 'status ingress: ' . (is_file($statusFile) ? 'presente' : 'aún no generado'));

if (Config::get('ALMACEN_MOTION_TOKEN', '') !== '') {
    line('OK', 'motion token configurado');
} else {
    line('WARN', 'ALMACEN_MOTION_TOKEN vacío (solo loopback directo para motion)');
}

exit($ok ? 0 : 1);
