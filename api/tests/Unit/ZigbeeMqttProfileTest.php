<?php
declare(strict_types=1);

/**
 * F125.1.2 — resolución de perfiles MQTT por dispositivo.
 *
 * No usa broker, MQTT, BD ni hardware. El fichero de perfil y el de
 * credenciales son temporales bajo `api/run/` (raíz permitida).
 *
 * Run: php tests/Unit/ZigbeeMqttProfileTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Zigbee\ZigbeeMqttProfile;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c, string $d = ''): void { $c ? ok($m) : ko($m . ($d ? " — {$d}" : '')); }
function throwsCode(string $m, string $code, callable $fn): void {
    try {
        $fn();
        ko($m . ' — no lanzó');
    } catch (Throwable $e) {
        same($m, $e->getMessage(), $code);
    }
}

echo "Zigbee MQTT profiles (F125.1.2)\n";

$apiDir = dirname(__DIR__, 2);
$runDir = $apiDir . '/run';
if (!is_dir($runDir)) {
    mkdir($runDir, 0775, true);
}
$passFile = $runDir . '/test-mqtt-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.pass';
file_put_contents($passFile, "dummy\n");
$configFile = sys_get_temp_dir() . '/zigbee-profiles-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.json';

$writeConfig = static function (array $profile) use ($configFile): void {
    file_put_contents($configFile, json_encode([
        'version' => 1,
        'profiles' => ['dev' => $profile],
    ], JSON_UNESCAPED_SLASHES));
};

// ── Default (sin perfil) ────────────────────────────────────────────────
$default = ZigbeeMqttProfile::resolve([]);
same('default profile', $default['profile'], ZigbeeMqttProfile::DEFAULT);
same('default no añade env', $default['env'], []);
same('default meta vacía', ZigbeeMqttProfile::profileName([]), 'default');
same('default literal', ZigbeeMqttProfile::profileName(['mqtt_profile' => 'default']), 'default');
check('default describe ok', (ZigbeeMqttProfile::describe([])['ok'] ?? false) === true);

// ── Dev válido ──────────────────────────────────────────────────────────
$writeConfig([
    'port' => 1886,
    'username' => 'zigbee2mqtt-dev',
    'password_file' => $passFile,
]);
$dev = ZigbeeMqttProfile::resolve(['mqtt_profile' => 'dev'], $configFile);
same('dev profile', $dev['profile'], 'dev');
same('dev port', $dev['port'], 1886);
same('dev username', $dev['username'], 'zigbee2mqtt-dev');
same('dev env port', $dev['env']['ZIGBEE_MQTT_PORT'] ?? null, '1886');
same('dev env username', $dev['env']['ZIGBEE_MQTT_USERNAME'] ?? null, 'zigbee2mqtt-dev');
same('dev env password file', $dev['env']['ZIGBEE_MQTT_PASSWORD_FILE'] ?? null, $passFile);
$desc = ZigbeeMqttProfile::describe(['mqtt_profile' => 'dev'], $configFile);
check('dev describe ok', ($desc['ok'] ?? false) === true);
same('dev describe profile', $desc['profile'] ?? null, 'dev');
same('dev describe port', $desc['port'] ?? null, 1886);
check('dev describe password file exists', ($desc['password_file_exists'] ?? false) === true);

// ── Perfil desconocido / inválido ───────────────────────────────────────
throwsCode('perfil desconocido', 'unknown_mqtt_profile', static fn () => ZigbeeMqttProfile::resolve(['mqtt_profile' => 'nope'], $configFile));

$writeConfig(['port' => 0, 'username' => 'u', 'password_file' => $passFile]);
throwsCode('puerto inválido', 'mqtt_profile_invalid_port', static fn () => ZigbeeMqttProfile::resolve(['mqtt_profile' => 'dev'], $configFile));

$writeConfig(['port' => 1886, 'username' => '', 'password_file' => $passFile]);
throwsCode('usuario inválido', 'mqtt_profile_invalid_username', static fn () => ZigbeeMqttProfile::resolve(['mqtt_profile' => 'dev'], $configFile));

$writeConfig(['port' => 1886, 'username' => 'u', 'password_file' => 'run/relative.pass']);
throwsCode('password_file relativo', 'mqtt_profile_invalid_password_file', static fn () => ZigbeeMqttProfile::resolve(['mqtt_profile' => 'dev'], $configFile));

$writeConfig(['port' => 1886, 'username' => 'u', 'password_file' => '/etc/passwd']);
throwsCode('password_file fuera de allowlist', 'mqtt_profile_password_file_path', static fn () => ZigbeeMqttProfile::resolve(['mqtt_profile' => 'dev'], $configFile));

$writeConfig(['port' => 1886, 'username' => 'u', 'password_file' => $runDir . '/no-existe-' . getmypid() . '.pass']);
throwsCode('password_file inexistente', 'mqtt_profile_password_file_missing', static fn () => ZigbeeMqttProfile::resolve(['mqtt_profile' => 'dev'], $configFile));

$writeConfig(['port' => 1886, 'username' => 'u', 'password_file' => $passFile, 'host' => 'evil.example']);
throwsCode('clave desconocida', 'mqtt_profile_invalid', static fn () => ZigbeeMqttProfile::resolve(['mqtt_profile' => 'dev'], $configFile));

throwsCode('nombre de perfil inválido', 'mqtt_profile_invalid', static fn () => ZigbeeMqttProfile::profileName(['mqtt_profile' => '../dev']));
throwsCode('perfil no string', 'mqtt_profile_invalid', static fn () => ZigbeeMqttProfile::profileName(['mqtt_profile' => 123]));

// ── Entorno hijo ────────────────────────────────────────────────────────
same('sin overrides → null', ZigbeeMqttProfile::childEnv([]), null);
$env = ZigbeeMqttProfile::childEnv(['ZIGBEE_MQTT_PORT' => '1886']);
check('overrides incluyen el puerto', ($env['ZIGBEE_MQTT_PORT'] ?? null) === '1886');
check('overrides conservan PATH', !empty($env['PATH']));

// Limpieza.
@unlink($passFile);
@unlink($configFile);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
