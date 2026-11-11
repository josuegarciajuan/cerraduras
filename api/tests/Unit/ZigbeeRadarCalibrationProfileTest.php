<?php
declare(strict_types=1);

/**
 * F125.1.2 — el publicador del radar recibe el entorno MQTT del dispositivo.
 *
 * Usa un PDO falso (solo SELECT MAX) y un publicador inyectado. No requiere
 * MQTT, BD real ni hardware.
 *
 * Run: php tests/Unit/ZigbeeRadarCalibrationProfileTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService;

final class FakeRadarProfileStatement extends PDOStatement
{
    public function __construct(private string $query) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchColumn(int $column = 0): mixed { return 0; }
}

final class FakeRadarProfilePdo extends PDO
{
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakeRadarProfileStatement($query);
    }
}

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c, string $d = ''): void { $c ? ok($m) : ko($m . ($d ? " — {$d}" : '')); }

echo "Zigbee radar calibration MQTT profile (F125.1.2)\n";

$apiDir = dirname(__DIR__, 2);
$runDir = $apiDir . '/run';
if (!is_dir($runDir)) {
    mkdir($runDir, 0775, true);
}
$passFile = $runDir . '/test-mqtt-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.pass';
file_put_contents($passFile, "dummy\n");
$configFile = sys_get_temp_dir() . '/zigbee-profiles-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.json';
file_put_contents($configFile, json_encode([
    'version' => 1,
    'profiles' => ['dev' => ['port' => 1886, 'username' => 'zigbee2mqtt-dev', 'password_file' => $passFile]],
], JSON_UNESCAPED_SLASHES));

$settings = ['maximum_range' => 3.0, 'minimum_range' => 0.6, 'radar_sensitivity' => 7, 'detection_delay' => 0.1, 'fading_time' => 5.0];
$prodDevice = [
    'id' => 438,
    'external_id' => '0xa4c138b2a3c49dbe',
    'label' => 'Zigbee · Presencia · almacenZigbee',
    'meta' => ['topic' => 'zigbee2mqtt/almacen-zb-presencia'],
];
$devDevice = [
    'id' => 1339,
    'external_id' => '0xa4c138718a950dcf',
    'label' => 'Dev · Presencia · DEVELOPMENTROOM',
    'meta' => ['topic' => 'zigbee2mqtt/dev-zb-presencia', 'mqtt_profile' => 'dev'],
];
$badDevice = [
    'id' => 999,
    'external_id' => 'bad',
    'label' => 'bad',
    'meta' => ['topic' => 'zigbee2mqtt/dev-zb-presencia', 'mqtt_profile' => 'nope'],
];

$calls = [];
$publisher = function (string $topic, string $payload, array $env = []) use (&$calls): array {
    $calls[] = [$topic, $payload, $env];
    return ['ok' => true, 'error' => null];
};

$service = new ZigbeeRadarCalibrationService(new FakeRadarProfilePdo(), $publisher, $configFile);

$prod = $service->publish(12, $prodDevice, $settings);
check('producción publish ok', ($prod['ok'] ?? false) === true);
same('producción topic', $calls[0][0] ?? null, 'zigbee2mqtt/almacen-zb-presencia/set');
same('producción env', $calls[0][2] ?? null, []);

$dev = $service->publish(201, $devDevice, $settings);
check('dev publish ok', ($dev['ok'] ?? false) === true);
same('dev topic', $calls[1][0] ?? null, 'zigbee2mqtt/dev-zb-presencia/set');
same('dev env port', $calls[1][2]['ZIGBEE_MQTT_PORT'] ?? null, '1886');
same('dev env username', $calls[1][2]['ZIGBEE_MQTT_USERNAME'] ?? null, 'zigbee2mqtt-dev');
same('dev env password file', $calls[1][2]['ZIGBEE_MQTT_PASSWORD_FILE'] ?? null, $passFile);

$bad = $service->publish(201, $badDevice, $settings);
check('perfil desconocido falla cerrado', ($bad['ok'] ?? true) === false);
same('error de perfil', $bad['error'] ?? null, 'unknown_mqtt_profile');

@unlink($passFile);
@unlink($configFile);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
