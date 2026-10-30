<?php
declare(strict_types=1);

namespace App\Infrastructure\Zigbee;

use App\Support\Config;
use PDO;
use RuntimeException;

/**
 * ZigbeeRadarCalibrationService — F93/RF-156.
 *
 * Calibración del radar de presencia Zigbee (ZY-M100/S_2 y equivalentes) sin
 * sondear el dispositivo: Zigbee2MQTT no expone `/get` para `presence`, pero
 * publica la configuración completa del radar en cada reporte de presencia.
 *
 * - `status()` lee de la BD el último snapshot `meta.settings` + la última
 *   calibración guardada. Es una lectura real del dispositivo (llegó por push),
 *   no un valor inventado.
 * - `apply()` publica en `<topic>/set` (QoS 1) y espera a que el dispositivo
 *   reemita su configuración con un evento NUEVO. Solo entonces `applied=true`.
 *
 * Nunca llama a Tuya y no crea temporizadores ni pollers.
 */
final class ZigbeeRadarCalibrationService
{
    public const PARAMETERS = [
        'radar_sensitivity' => ['min' => 0.0, 'max' => 9.0,  'step' => 1.0,  'unit' => null],
        'minimum_range'     => ['min' => 0.0, 'max' => 9.5,  'step' => 0.15, 'unit' => 'm'],
        'maximum_range'     => ['min' => 0.15, 'max' => 9.5, 'step' => 0.15, 'unit' => 'm'],
        'detection_delay'   => ['min' => 0.0, 'max' => 10.0, 'step' => 0.1,  'unit' => 's'],
        'fading_time'       => ['min' => 0.5, 'max' => 1500.0, 'step' => 1.0, 'unit' => 's'],
    ];

    /**
     * F96.5: valores que el dispositivo puede reportar y que tienen sentido.
     * Un alcance máximo de 0,01 m o 4.866.048,01 m deja el radar inservible;
     * hay que detectarlo y ofrecer restauración, nunca seguir calibrando.
     *
     * @param array<string,mixed> $settings
     */
    public static function isValidSettings(array $settings): bool
    {
        $max = $settings['maximum_range'] ?? null;
        $min = $settings['minimum_range'] ?? null;
        $sens = $settings['radar_sensitivity'] ?? null;
        if (!is_numeric($max) || !is_numeric($min) || !is_numeric($sens)) {
            return false;
        }
        $max = (float) $max;
        $min = (float) $min;
        $sens = (float) $sens;
        if ($max < 0.15 || $max > 9.5 || $min < 0.0 || $min > 9.5) {
            return false;
        }
        if ($min > $max || $sens < 0 || $sens > 9) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string,mixed> $settings
     */
    public static function settingsInvalidReason(array $settings): ?string
    {
        $max = $settings['maximum_range'] ?? null;
        $min = $settings['minimum_range'] ?? null;
        $sens = $settings['radar_sensitivity'] ?? null;
        if (!is_numeric($max) || !is_numeric($min) || !is_numeric($sens)) {
            return 'settings_missing';
        }
        $max = (float) $max;
        $min = (float) $min;
        $sens = (float) $sens;
        if ($max < 0.15 || $max > 9.5) return 'maximum_range_out_of_bounds';
        if ($min < 0.0 || $min > 9.5) return 'minimum_range_out_of_bounds';
        if ($min > $max) return 'minimum_greater_than_maximum';
        if ($sens < 0 || $sens > 9) return 'sensitivity_out_of_bounds';
        return null;
    }

    /** @var callable(string,string):array{ok:bool,error:?string} */
    private $publisher;

    /**
     * @param callable(string,string):array{ok:bool,error:?string}|null $publisher
     *        Injected in tests; production uses the Node MQTT helper.
     */
    public function __construct(private PDO $pdo, ?callable $publisher = null)
    {
        $this->publisher = $publisher ?? [$this, 'runPublisher'];
    }

    /**
     * @param array{id:int,external_id:string,label?:string,meta?:array<string,mixed>|null} $device
     * @return array<string,mixed>
     */
    public function status(int $roomId, array $device): array
    {
        $snapshot = $this->latestSnapshot($roomId);
        $settings = $snapshot['settings'];
        $meta = $device['meta'] ?? [];
        $saved = is_array($meta) && isset($meta['calibration']) && is_array($meta['calibration'])
            ? $meta['calibration']
            : null;
        if (is_array($saved)) {
            // Compatibilidad con el modal existente (cm y sensibilidad entera).
            if (isset($saved['maximum_range']) && !isset($saved['far_detection'])) {
                $saved['far_detection'] = (int) round(((float) $saved['maximum_range']) * 100);
            }
            if (isset($saved['radar_sensitivity']) && !isset($saved['sensitivity'])) {
                $saved['sensitivity'] = (int) round((float) $saved['radar_sensitivity']);
            }
        }

        $maximum = $this->num($settings['maximum_range'] ?? null);
        $minimum = $this->num($settings['minimum_range'] ?? null);
        $sens    = $this->num($settings['radar_sensitivity'] ?? null);
        // F93/RF-157.3: sin push reciente no se afirma presencia ni "online".
        $stale = $snapshot['age_seconds'] === null || $snapshot['age_seconds'] > 300;
        $presence = $stale ? null : $snapshot['value'];

        return [
            'room_id'   => $roomId,
            'transport' => 'ZIGBEE2MQTT',
            'device'    => [
                'id'          => (int) $device['id'],
                'external_id' => (string) $device['external_id'],
                'label'       => (string) ($device['label'] ?? ''),
                'transport'   => 'ZIGBEE2MQTT',
                // Liveness por push: sin evento reciente no se afirma "online".
                'online'      => $snapshot['last_at'] !== null
                    && $snapshot['age_seconds'] !== null
                    && $snapshot['age_seconds'] <= 300,
            ],
            // El panel reutiliza los mismos controles: cm para el rango máximo y
            // sensibilidad del radar. La conversión vive aquí, no en el JS.
            'dp_caps' => [
                'far_min'      => 15,
                'far_max'      => 950,
                'far_step'     => 15,
                'sens_min'     => 0,
                'sens_max'     => 9,
                'off_far'      => null,
                'target_scale' => 1,
                'near_min'     => 0,
            ],
            'far_detection'      => $maximum === null ? null : (int) round($maximum * 100),
            'near_detection'     => $minimum === null ? null : (int) round($minimum * 100),
            'sensitivity'        => $sens === null ? null : (int) round($sens),
            'detection_delay_s'  => isset($settings['detection_delay']) ? (float) $settings['detection_delay'] : null,
            'fading_time_s'      => isset($settings['fading_time']) ? (float) $settings['fading_time'] : null,
            'presence_state'     => $presence === null ? null : ($presence === 'PRESENT' ? 'presence' : 'none'),
            'target_dis_closest' => isset($settings['target_distance']) ? (int) round((float) $settings['target_distance'] * 100) : null,
            'target_distance_m'  => isset($settings['target_distance']) ? (float) $settings['target_distance'] : null,
            'illuminance_value'  => $settings['illuminance'] ?? null,
            'mode'               => 'ON',
            'effective_presence' => $presence,
            'settings'           => $settings,
            'settings_valid'     => self::isValidSettings($settings),
            'settings_invalid_reason' => self::settingsInvalidReason($settings),
            'settings_at'        => $snapshot['last_at'],
            'settings_age_seconds' => $snapshot['age_seconds'],
            'stale'              => $stale,
            'saved'              => $saved,
        ];
    }

    /**
     * F102/RF-175: publica sin bloquear a la espera de confirmación.
     * La confirmación se reconcilia después con `confirm()`.
     *
     * @param array{id:int,external_id:string,label?:string,meta?:array<string,mixed>|null} $device
     * @param array<string,mixed> $input
     * @return array{ok:bool,commands:array<string,int|float>,before_event_id:int,published_at:string,error:?string}
     */
    public function publish(int $roomId, array $device, array $input): array
    {
        $commands = self::normalizeCommands($input);
        if ($commands === []) {
            return ['ok' => false, 'commands' => [], 'before_event_id' => 0,
                'published_at' => gmdate('Y-m-d\TH:i:s\Z'), 'error' => 'no_parameters'];
        }
        $beforeId = $this->latestEventId($roomId);
        $publishedAt = gmdate('Y-m-d\TH:i:s.v\Z');
        $result = ($this->publisher)($this->setTopic($device), json_encode($commands, JSON_UNESCAPED_SLASHES));
        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'commands' => $commands,
            'before_event_id' => $beforeId,
            'published_at' => $publishedAt,
            'error' => ($result['ok'] ?? false) ? null : (string) ($result['error'] ?? 'publish_failed'),
        ];
    }

    /**
     * Reconcilia una orden publicada contra el último reporte del radar.
     *
     * @param array<string,int|float> $requested
     * @return array{state:string,settings:array<string,mixed>,event_id:?int,at:?string,reason:?string}
     */
    public function confirm(int $roomId, int $beforeEventId, array $requested): array
    {
        $snapshot = $this->latestSnapshot($roomId);
        if ($snapshot['id'] === null || $snapshot['id'] <= $beforeEventId) {
            return ['state' => 'pending', 'settings' => $snapshot['settings'],
                'event_id' => $snapshot['id'], 'at' => $snapshot['last_at'], 'reason' => 'no_new_report'];
        }
        $settings = $snapshot['settings'];
        $invalid = self::settingsInvalidReason($settings);
        if ($invalid !== null) {
            return ['state' => 'invalid', 'settings' => $settings,
                'event_id' => $snapshot['id'], 'at' => $snapshot['last_at'], 'reason' => $invalid];
        }
        foreach ($requested as $key => $expected) {
            $actual = $this->num($settings[$key] ?? null);
            if ($actual === null || abs($actual - (float) $expected) > 0.001) {
                return ['state' => 'mismatch', 'settings' => $settings,
                    'event_id' => $snapshot['id'], 'at' => $snapshot['last_at'], 'reason' => 'value_not_applied'];
            }
        }
        return ['state' => 'confirmed', 'settings' => $settings,
            'event_id' => $snapshot['id'], 'at' => $snapshot['last_at'], 'reason' => null];
    }

    /**
     * Publica los parámetros y espera confirmación REAL por push.
     *
     * @param array{id:int,external_id:string,label?:string,meta?:array<string,mixed>|null} $device
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function apply(int $roomId, array $device, array $input, bool $persist): array
    {
        $commands = self::normalizeCommands($input);
        if ($commands === []) {
            throw new RuntimeException('no_parameters');
        }

        $topic = $this->setTopic($device);
        $beforeId = $this->latestEventId($roomId);
        $publishedAt = gmdate('Y-m-d H:i:s.v');

        $result = ($this->publisher)($topic, json_encode($commands, JSON_UNESCAPED_SLASHES));
        if (($result['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'applied' => false,
                'persisted' => false,
                'error' => (string) ($result['error'] ?? 'publish_failed'),
                'warning' => 'No se pudo publicar la orden en Zigbee2MQTT',
                'status' => $this->status($roomId, $device),
            ];
        }

        // Read-back por push: el radar reemite su configuración en cada reporte.
        // Se acepta solo un evento NUEVO (id > beforeId) que refleje lo pedido.
        $applied = false;
        $latest = null;
        for ($attempt = 0; $attempt < 12; $attempt++) {
            if ($attempt > 0) {
                usleep(700_000);
            }
            $snapshot = $this->latestSnapshot($roomId);
            if ($snapshot['id'] === null || $snapshot['id'] <= $beforeId) {
                continue;
            }
            $latest = $snapshot;
            $applied = true;
            foreach ($commands as $key => $expected) {
                $actual = $this->num($snapshot['settings'][$key] ?? null);
                if ($actual === null || abs($actual - (float) $expected) > 0.001) {
                    $applied = false;
                    break;
                }
            }
            if ($applied) {
                break;
            }
        }

        $snapshotForStatus = $latest ?? $this->latestSnapshot($roomId);
        $invalidReason = self::settingsInvalidReason($snapshotForStatus['settings']);
        if ($applied && $invalidReason !== null) {
            // El dispositivo confirmó el comando pero quedó en un estado inválido
            // (p. ej. max 0,01 m). No se persiste ni se sigue calibrando.
            return [
                'ok' => true,
                'applied' => false,
                'persisted' => false,
                'error' => null,
                'warning' => 'invalid_device_settings:' . $invalidReason,
                'settings_invalid_reason' => $invalidReason,
                'status' => $this->status($roomId, $device),
            ];
        }

        $saved = null;
        if ($persist && $applied) {
            $saved = $this->persistSnapshot($device, $commands, $snapshotForStatus['last_at']);
            // Refresca el meta del dispositivo para que `status.saved` refleje
            // exactamente la calibración recién persistida.
            $fresh = $this->pdo->prepare('SELECT meta_json FROM devices WHERE id = :id LIMIT 1');
            $fresh->execute([':id' => (int) $device['id']]);
            $decoded = json_decode((string) ($fresh->fetchColumn() ?: ''), true);
            if (is_array($decoded)) {
                $device['meta'] = $decoded;
            }
        }
        $status = $this->status($roomId, $device);

        return [
            'ok' => true,
            'applied' => $applied,
            'persisted' => $saved !== null,
            'saved' => $saved,
            'warning' => $applied
                ? null
                : 'El radar aún no ha confirmado la nueva configuración por push; no se guarda',
            'published_at' => $publishedAt,
            'commands' => $commands,
            'status' => $status,
        ];
    }

    /**
     * @return array{id:?int,value:?string,last_at:?string,age_seconds:?int,settings:array<string,mixed>}
     */
    private function latestSnapshot(int $roomId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, value, occurred_at, meta_json
               FROM presence_events
              WHERE room_id = :rid AND provider = 'ZIGBEE2MQTT' AND sensor = 'PRESENCE'
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['id' => null, 'value' => null, 'last_at' => null, 'age_seconds' => null, 'settings' => []];
        }
        $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
        $settings = is_array($meta) && is_array($meta['settings'] ?? null) ? $meta['settings'] : [];
        $lastAt = (string) ($row['occurred_at'] ?? '');
        $age = null;
        if ($lastAt !== '') {
            $ts = strtotime($lastAt . ' UTC');
            if ($ts !== false) {
                $age = max(0, time() - $ts);
            }
        }
        return [
            'id' => (int) $row['id'],
            'value' => (string) ($row['value'] ?? ''),
            'last_at' => $lastAt === '' ? null : $lastAt,
            'age_seconds' => $age,
            'settings' => $settings,
        ];
    }

    private function latestEventId(int $roomId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT MAX(id) FROM presence_events WHERE room_id = :rid"
        );
        $stmt->execute([':rid' => $roomId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * @param array{id:int,external_id:string,meta?:array<string,mixed>|null} $device
     */
    private function setTopic(array $device): string
    {
        $meta = is_array($device['meta'] ?? null) ? $device['meta'] : [];
        $base = isset($meta['topic']) && is_string($meta['topic']) && $meta['topic'] !== ''
            ? rtrim($meta['topic'], '/')
            : 'zigbee2mqtt/' . (isset($meta['friendly_name']) && is_string($meta['friendly_name']) && $meta['friendly_name'] !== ''
                ? $meta['friendly_name']
                : (string) $device['external_id']);
        if (str_ends_with($base, '/set')) {
            $base = substr($base, 0, -4);
        }
        return $base . '/set';
    }

    /**
     * @param array{id:int,external_id:string,meta?:array<string,mixed>|null} $device
     * @param array<string,int|float> $commands
     * @return array<string,mixed>
     */
    private function persistSnapshot(array $device, array $commands, ?string $settingsAt): array
    {
        $stmt = $this->pdo->prepare('SELECT meta_json FROM devices WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $device['id']]);
        $raw = $stmt->fetchColumn();
        $meta = json_decode((string) ($raw === false ? '' : $raw), true);
        $meta = is_array($meta) ? $meta : [];

        $snap = [
            'source' => 'dashboard-calib-zigbee',
            'calibrated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'settings_at' => $settingsAt,
        ];
        foreach ($commands as $key => $value) {
            $snap[$key] = $value;
        }
        $meta['calibration'] = $snap;
        $this->pdo->prepare('UPDATE devices SET meta_json = :meta WHERE id = :id')
            ->execute([
                ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                ':id'   => (int) $device['id'],
            ]);
        return $snap;
    }

    /** @return array{ok:bool,error:?string} */
    private function runPublisher(string $topic, string $payload): array
    {
        $apiDir = dirname(__DIR__, 3);
        $helper = $apiDir . '/bin/zigbee-ingress/publish-json.js';
        if (!is_file($helper)) {
            return ['ok' => false, 'error' => 'publish_helper_missing'];
        }
        $node = (string) (Config::get('ZIGBEE_NODE_BIN', 'node') ?? 'node');
        $timeout = (int) (Config::get('ZIGBEE_SWITCH_TIMEOUT_SECONDS', '5') ?? 5);
        $process = proc_open(
            [$node, $helper, $topic, $payload, (string) max(1, $timeout)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $apiDir
        );
        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'publish_process_failed'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            $error = trim($stderr) !== '' ? trim($stderr) : 'publish_failed';
            return ['ok' => false, 'error' => substr($error, 0, 300)];
        }
        if (!str_contains($stdout, 'OK')) {
            return ['ok' => false, 'error' => 'publish_no_ack'];
        }
        return ['ok' => true, 'error' => null];
    }

    /**
     * Valida y ajusta los parámetros a la rejilla real del dispositivo.
     *
     * @param array<string,mixed> $input
     * @return array<string,int|float>
     */
    public static function normalizeCommands(array $input): array
    {
        $commands = [];
        foreach (self::PARAMETERS as $key => $cap) {
            if (!array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
                continue;
            }
            if (!is_numeric($input[$key])) {
                throw new RuntimeException('invalid_' . $key);
            }
            $value = self::snapStatic((float) $input[$key], (float) $cap['min'], (float) $cap['max'], (float) $cap['step']);
            $commands[$key] = $key === 'radar_sensitivity' ? (int) $value : $value;
        }
        return $commands;
    }

    private static function snapStatic(float $value, float $min, float $max, float $step): float
    {
        $value = max($min, min($max, $value));
        $steps = round(($value - $min) / $step);
        return round($min + $steps * $step, 3);
    }

    private function num(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : (is_numeric($value) ? (float) $value : null);
    }
}
