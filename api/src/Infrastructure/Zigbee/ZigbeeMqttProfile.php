<?php
declare(strict_types=1);

namespace App\Infrastructure\Zigbee;

use App\Support\Config;
use RuntimeException;
use Throwable;

/**
 * ZigbeeMqttProfile — F125.1.2.
 *
 * Resuelve el broker MQTT de un dispositivo Zigbee a partir de
 * `devices.meta_json.mqtt_profile`.
 *
 * - Sin perfil (o `default`) → no se añaden overrides: el hijo hereda el
 *   `.env` actual (producción, 1884).
 * - `dev` → overrides explícitos al broker de desarrollo (1886), sin tocar
 *   `.env` ni el control de producción.
 *
 * El fichero de perfiles no contiene contraseñas: solo puerto, usuario y ruta
 * al fichero de credenciales. Las rutas se validan contra una allowlist y el
 * perfil desconocido falla cerrado.
 */
final class ZigbeeMqttProfile
{
    public const DEFAULT = 'default';
    public const META_KEY = 'mqtt_profile';

    public static function defaultConfigFile(): string
    {
        return dirname(__DIR__, 3) . '/config/zigbee-mqtt-profiles.json';
    }

    /**
     * @param array<string,mixed> $meta
     */
    public static function profileName(array $meta): string
    {
        $value = $meta[self::META_KEY] ?? null;
        if ($value === null || $value === '') {
            return self::DEFAULT;
        }
        if (!is_string($value)) {
            throw new RuntimeException('mqtt_profile_invalid');
        }
        $name = strtolower(trim($value));
        if (!preg_match('/^[a-z0-9_-]{1,32}$/', $name)) {
            throw new RuntimeException('mqtt_profile_invalid');
        }
        return $name;
    }

    /**
     * @param array<string,mixed> $meta
     * @return array{profile:string,port:int,username:string,password_file:string,host:string,env:array<string,string>}
     */
    public static function resolve(array $meta, ?string $configFile = null): array
    {
        $name = self::profileName($meta);
        if ($name === self::DEFAULT) {
            return self::defaultResolved();
        }

        $config = self::loadConfig($configFile);
        $profiles = $config['profiles'] ?? null;
        if (!is_array($profiles) || !isset($profiles[$name]) || !is_array($profiles[$name])) {
            throw new RuntimeException('unknown_mqtt_profile');
        }
        $profile = $profiles[$name];
        $allowed = ['port', 'username', 'password_file'];
        foreach (array_keys($profile) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new RuntimeException('mqtt_profile_invalid');
            }
        }

        $port = $profile['port'] ?? null;
        if (is_string($port) && ctype_digit($port)) {
            $port = (int) $port;
        }
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new RuntimeException('mqtt_profile_invalid_port');
        }

        $username = $profile['username'] ?? null;
        if (!is_string($username) || $username === '' || strlen($username) > 64
            || preg_match('/[\x00-\x1F\s]/', $username) === 1) {
            throw new RuntimeException('mqtt_profile_invalid_username');
        }

        $passwordFile = $profile['password_file'] ?? null;
        if (!is_string($passwordFile) || $passwordFile === '' || !str_starts_with($passwordFile, '/')) {
            throw new RuntimeException('mqtt_profile_invalid_password_file');
        }
        $real = realpath($passwordFile);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new RuntimeException('mqtt_profile_password_file_missing');
        }
        $allowedRoots = [
            '/etc/cerraduras/',
            dirname(__DIR__, 3) . '/run/',
        ];
        $allowedPath = false;
        foreach ($allowedRoots as $root) {
            if (str_starts_with($real, rtrim($root, '/') . '/')) {
                $allowedPath = true;
                break;
            }
        }
        if (!$allowedPath) {
            throw new RuntimeException('mqtt_profile_password_file_path');
        }

        $host = (string) (Config::get('ZIGBEE_MQTT_HOST', '127.0.0.1') ?? '127.0.0.1');
        return [
            'profile' => $name,
            'port' => $port,
            'username' => $username,
            'password_file' => $passwordFile,
            'host' => $host,
            'env' => [
                'ZIGBEE_MQTT_PORT' => (string) $port,
                'ZIGBEE_MQTT_USERNAME' => $username,
                'ZIGBEE_MQTT_PASSWORD_FILE' => $passwordFile,
            ],
        ];
    }

    /**
     * Overrides para el proceso hijo. `[]` significa heredar el entorno actual.
     *
     * @param array<string,mixed> $meta
     * @return array<string,string>
     */
    public static function envForDevice(array $meta, ?string $configFile = null): array
    {
        return self::resolve($meta, $configFile)['env'];
    }

    /**
     * Entorno completo para proc_open. Devuelve null si no hay overrides para
     * conservar exactamente el comportamiento anterior.
     *
     * @param array<string,string> $overrides
     * @return array<string,string>|null
     */
    public static function childEnv(array $overrides): ?array
    {
        if ($overrides === []) {
            return null;
        }
        $base = getenv();
        if (!is_array($base)) {
            $base = [];
        }
        if (!isset($base['PATH']) || $base['PATH'] === '') {
            $path = getenv('PATH');
            $base['PATH'] = is_string($path) && $path !== ''
                ? $path
                : '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        }
        return array_merge($base, $overrides);
    }

    /**
     * Diagnóstico sin secretos.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public static function describe(array $meta, ?string $configFile = null): array
    {
        try {
            $resolved = self::resolve($meta, $configFile);
            $passwordFile = $resolved['password_file'];
            $absolute = str_starts_with($passwordFile, '/')
                ? $passwordFile
                : dirname(__DIR__, 3) . '/' . ltrim($passwordFile, '/');
            return [
                'ok' => true,
                'profile' => $resolved['profile'],
                'host' => $resolved['host'],
                'port' => $resolved['port'],
                'username' => $resolved['username'],
                'password_file' => $passwordFile,
                'password_file_exists' => is_file($absolute) && is_readable($absolute),
                'env_keys' => array_keys($resolved['env']),
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'profile' => $meta[self::META_KEY] ?? self::DEFAULT,
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @return array{profile:string,port:int,username:string,password_file:string,host:string,env:array<string,string>} */
    private static function defaultResolved(): array
    {
        $port = (int) (Config::get('ZIGBEE_MQTT_PORT', '1883') ?? 1883);
        if ($port < 1 || $port > 65535) {
            $port = 1883;
        }
        $username = (string) (Config::get('ZIGBEE_MQTT_USERNAME', '') ?? '');
        $passwordFile = (string) (Config::get('ZIGBEE_MQTT_PASSWORD_FILE', '') ?? '');
        $host = (string) (Config::get('ZIGBEE_MQTT_HOST', '127.0.0.1') ?? '127.0.0.1');
        return [
            'profile' => self::DEFAULT,
            'port' => $port,
            'username' => $username,
            'password_file' => $passwordFile,
            'host' => $host,
            'env' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function loadConfig(?string $configFile): array
    {
        $path = $configFile ?? self::defaultConfigFile();
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('mqtt_profiles_config_missing');
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('mqtt_profiles_config_missing');
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['profiles'] ?? null)) {
            throw new RuntimeException('mqtt_profiles_config_invalid');
        }
        return $data;
    }
}
