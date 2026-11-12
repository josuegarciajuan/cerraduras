<?php
declare(strict_types=1);

namespace App\Infrastructure\Zigbee;

use App\Domain\Sensors\RadarCalibrationDrift;
use App\Domain\Sensors\RadarFreshness;
use App\Infrastructure\Gateways\Sensor\ZigbeeMqttMessageMapper;
use App\Support\Config;
use PDO;
use RuntimeException;
use Throwable;

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
 *   Excepción F112: al restaurar la base (`persist=false`) se acepta el último
 *   reporte real si ya coincide y es reciente; el radar solo publica cambios.
 *
 * Nunca llama a Tuya y no crea temporizadores ni pollers.
 */
final class ZigbeeRadarCalibrationService
{
    /**
     * F110: base sin calibrar de cada corrida. El 3,15 m anterior era una
     * calibración, no un límite del hardware.
     */
    public const BASELINE = [
        'maximum_range'     => 6.0,
        'minimum_range'     => 0.6,
        'radar_sensitivity' => 7,
        'detection_delay'   => 0.1,
        'fading_time'       => 15.5,
    ];

    /**
     * F125.2: objetivo temporal mientras se aplica/prueba una configuración.
     * El reconciliador lo usa para no revertir una prueba en curso. Si expira,
     * vuelve a mandar la calibración guardada.
     */
    public const PENDING_KEY = 'calibration_pending';
    public const PENDING_UNTIL_KEY = 'calibration_pending_until';

    public const PARAMETERS = [
        'radar_sensitivity' => ['min' => 0.0, 'max' => 9.0,  'step' => 1.0,  'unit' => null],
        'minimum_range'     => ['min' => 0.0, 'max' => 9.5,  'step' => 0.15, 'unit' => 'm'],
        'maximum_range'     => ['min' => 0.15, 'max' => 9.5, 'step' => 0.15, 'unit' => 'm'],
        'detection_delay'   => ['min' => 0.0, 'max' => 10.0, 'step' => 0.1,  'unit' => 's'],
        // El ZY-M100 reporta valores como 5.0 y 15.5; la rejilla real es de
        // 0.5 s. Con paso 1.0 anclado en 0.5, 5.0 se convertía en 5.5 y la
        // ruta idempotente nunca coincidía con el estado del radar.
        'fading_time'       => ['min' => 0.5, 'max' => 1500.0, 'step' => 0.5, 'unit' => 's'],
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

    /** @var callable(string,string,array=):array{ok:bool,error:?string} */
    private $publisher;
    /** @var callable(array,int=):array{ok:bool,error:?string,settings?:array<string,mixed>,received_at?:?string,presence?:mixed}|null */
    private $directReader;
    private ?string $profileConfigFile;

    /**
     * @param callable(string,string,array=):array{ok:bool,error:?string}|null $publisher
     *        Injected in tests; production uses the Node MQTT helper.
     * @param callable(array,int=):array<string,mixed>|null $directReader
     *        Injected in tests; production uses `readDirect()`.
     */
    public function __construct(
        private PDO $pdo,
        ?callable $publisher = null,
        ?string $profileConfigFile = null,
        ?callable $directReader = null
    ) {
        $this->publisher = $publisher ?? [$this, 'runPublisher'];
        $this->profileConfigFile = $profileConfigFile;
        $this->directReader = $directReader;
    }

    /**
     * @param array{id:int,external_id:string,label?:string,meta?:array<string,mixed>|null} $device
     * @return array<string,mixed>
     */
    public function status(int $roomId, array $device): array
    {
        $snapshot = $this->latestSnapshot($roomId);
        $latestId = $snapshot['id'];
        $staleSeconds = RadarFreshness::staleSeconds();
        $latestSettings = is_array($snapshot['settings']) ? $snapshot['settings'] : [];
        $reportedInvalidReason = $latestSettings === []
            ? null : self::settingsInvalidReason($latestSettings);
        $reportedInvalid = $reportedInvalidReason !== null;
        $latestReportedSettings = $latestSettings;
        $valid = $this->recentValidSnapshots(
            $roomId,
            $staleSeconds,
            self::unstableWindowSeconds()
        );
        if ($valid['latest'] !== null) {
            // F125.4: el radar puede intercalar reportes inválidos o valores
            // contradictorios. Se usa la última lectura VÁLIDA y se marca la
            // inestabilidad como un estado único para no hacer parpadear el panel.
            $snapshot = $valid['latest'];
        }
        $reportedUnstable = (bool) ($valid['unstable'] ?? false);
        $reportedVariants = (array) ($valid['variants'] ?? []);
        $settings = is_array($snapshot['settings']) ? $snapshot['settings'] : [];
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
        // F93/RF-157.3 / F125.1.4: el radar publica cada ~1,5 s; 30 s sin
        // mensajes ya es "sin datos". El estado de negocio de la sala se
        // calcula aparte.
        $stale = $snapshot['age_seconds'] === null || $snapshot['age_seconds'] > $staleSeconds;
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
                    && $snapshot['age_seconds'] <= $staleSeconds,
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
            'stale_seconds'      => $staleSeconds,
            'reported_invalid'   => $reportedInvalid,
            'reported_invalid_reason' => $reportedInvalidReason,
            'latest_reported_settings' => $latestReportedSettings,
            'reported_unstable' => $reportedUnstable,
            'reported_variants' => $reportedVariants,
            'using_valid_snapshot' => $snapshot['id'] !== null && $snapshot['id'] !== $latestId,
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
    public function publish(int $roomId, array $device, array $input, bool $markPending = true): array
    {
        $commands = self::normalizeCommands($input);
        if ($commands === []) {
            return ['ok' => false, 'commands' => [], 'before_event_id' => 0,
                'published_at' => gmdate('Y-m-d\TH:i:s\Z'), 'error' => 'no_parameters'];
        }
        $beforeId = $this->latestEventId($roomId);
        $publishedAt = gmdate('Y-m-d\TH:i:s.v\Z');
        if ($markPending) {
            $this->setPending($device, $commands, 300);
        }
        try {
            $env = $this->profileEnv($device);
        } catch (Throwable $e) {
            if ($markPending) {
                $this->clearPending($device);
            }
            return [
                'ok' => false,
                'commands' => $commands,
                'before_event_id' => $beforeId,
                'published_at' => $publishedAt,
                'error' => $e->getMessage(),
            ];
        }
        $result = ($this->publisher)(
            $this->setTopic($device),
            json_encode($commands, JSON_UNESCAPED_SLASHES),
            $env
        );
        $ok = (bool) ($result['ok'] ?? false);
        if ($markPending && !$ok) {
            $this->clearPending($device);
        }
        return [
            'ok' => $ok,
            'commands' => $commands,
            'before_event_id' => $beforeId,
            'published_at' => $publishedAt,
            'error' => $ok ? null : (string) ($result['error'] ?? 'publish_failed'),
        ];
    }

    /**
     * F125.2: lectura directa del siguiente estado del radar, sin esperar a que
     * el spool lo persista. Se suscribe al topic con el perfil MQTT del
     * dispositivo y devuelve el primer mensaje. No publica ni persiste.
     *
     * @param array{id:int,external_id:string,label?:string,meta?:array<string,mixed>|null} $device
     * @return array<string,mixed>
     */
    public function readDirect(array $device, int $timeoutSeconds = 5): array
    {
        $apiDir = dirname(__DIR__, 3);
        $helper = $apiDir . '/bin/zigbee-ingress/read-next.js';
        if (!is_file($helper)) {
            return ['ok' => false, 'error' => 'read_helper_missing'];
        }
        try {
            $env = $this->profileEnv($device);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $topic = $this->stateTopic($device);
        if (!$this->isValidStateTopic($topic)) {
            return ['ok' => false, 'error' => 'invalid_topic'];
        }
        $node = (string) (Config::get('ZIGBEE_NODE_BIN', 'node') ?? 'node');
        $timeout = max(1, min(15, $timeoutSeconds));
        $process = proc_open(
            [$node, $helper, $topic, (string) $timeout],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $apiDir,
            ZigbeeMqttProfile::childEnv($env)
        );
        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'read_process_failed'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            $error = trim($stderr) !== '' ? trim($stderr) : 'read_timeout';
            return ['ok' => false, 'error' => substr($error, 0, 300)];
        }
        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'read_invalid_json'];
        }
        $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
        return [
            'ok' => true,
            'error' => null,
            'topic' => (string) ($decoded['topic'] ?? $topic),
            'received_at' => $decoded['received_at'] ?? null,
            'payload' => $payload,
            'settings' => ZigbeeMqttMessageMapper::radarSettings((object) $payload),
            'presence' => $payload['presence'] ?? null,
            'target_distance' => $payload['target_distance'] ?? null,
        ];
    }

    /**
     * F125.4: confirma la orden leyendo el topic de estado directamente del
     * broker, sin esperar a que el spool la persista. Así `apply` y
     * `resetToBaseline` no dependen del backlog del spool.
     *
     * @param array{id:int,external_id:string,label?:string,meta?:array<string,mixed>|null} $device
     * @param array<string,int|float> $commands
     * @return array<string,mixed>|null
     */
    public function awaitDirectConfirmation(array $device, array $commands, ?int $timeoutSeconds = null): ?array
    {
        $enabled = Config::getBool('RADAR_DIRECT_CONFIRM_ENABLED', true);
        if (!$enabled) {
            return null;
        }
        $timeout = $timeoutSeconds ?? self::directConfirmTimeoutSeconds();
        if ($timeout <= 0) {
            return null;
        }
        $reader = $this->directReader ?? [$this, 'readDirect'];
        $deadline = microtime(true) + $timeout;
        $invalidStreak = 0;
        while (microtime(true) < $deadline) {
            $remaining = max(1, (int) ceil($deadline - microtime(true)));
            $read = $reader($device, (int) min(4, $remaining));
            if (!is_array($read) || ($read['ok'] ?? false) !== true) {
                $error = (string) (($read['error'] ?? '') ?: 'read_failed');
                // Un fallo de configuración/arranque del helper no se arregla
                // reintentando: el llamador cae al read-back por BD.
                if ($error !== 'read_timeout') {
                    return null;
                }
                usleep(100_000);
                continue;
            }
            $settings = is_array($read['settings'] ?? null) ? $read['settings'] : [];
            if ($settings !== [] && self::settingsMatch($commands, $settings)) {
                return $read;
            }
            // F125.4.1: si el radar solo emite valores imposibles, no se agota
            // la ventana entera: se devuelve el motivo para cortar en ~2 s.
            $invalidReason = $settings === [] ? null : self::settingsInvalidReason($settings);
            if ($invalidReason !== null) {
                $invalidStreak++;
                if ($invalidStreak >= 2) {
                    return [
                        'ok' => false,
                        'error' => 'reported_settings_invalid',
                        'invalid_reason' => $invalidReason,
                        'settings' => $settings,
                        'received_at' => $read['received_at'] ?? null,
                    ];
                }
            } else {
                $invalidStreak = 0;
            }
        }
        return null;
    }

    public static function unstableWindowSeconds(): int
    {
        $raw = Config::get('RADAR_UNSTABLE_WINDOW_SECONDS', '10') ?? '10';
        $value = is_numeric($raw) ? (int) $raw : 10;
        return max(3, min(60, $value));
    }

    public static function directConfirmTimeoutSeconds(): int
    {
        $raw = Config::get('RADAR_DIRECT_CONFIRM_TIMEOUT_SECONDS', '6') ?? '6';
        $value = is_numeric($raw) ? (int) $raw : 6;
        return max(1, min(20, $value));
    }

    private function stateTopic(array $device): string
    {
        return preg_replace('/\/set$/', '', $this->setTopic($device)) ?? $this->setTopic($device);
    }

    private function isValidStateTopic(string $topic): bool
    {
        if ($topic === '' || strlen($topic) > 220 || str_contains(strtolower($topic), '-oficina')) {
            return false;
        }
        return preg_match('/^[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*$/', $topic) === 1;
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
    /**
     * F110: restaura la base sin calibrar. No persiste una calibración.
     *
     * @param array{id:int,external_id:string,label?:string,meta?:array<string,mixed>|null} $device
     * @return array<string,mixed>
     */
    /**
     * @param array<string,mixed> $overrides Perfil operativo de la sala (p. ej.
     *        `fading_time=5.0` en el almacén). No altera `BASELINE`.
     */
    public function resetToBaseline(int $roomId, array $device, array $overrides = []): array
    {
        $settings = $overrides === [] ? self::BASELINE : array_merge(self::BASELINE, $overrides);
        return $this->apply($roomId, $device, $settings, false, 45);
    }

    public function apply(
        int $roomId,
        array $device,
        array $input,
        bool $persist,
        int $waitAttempts = 12,
        string $source = 'dashboard-calib-zigbee'
    ): array
    {
        $commands = self::normalizeCommands($input);
        if ($commands === []) {
            throw new RuntimeException('no_parameters');
        }

        if (!$persist) {
            // F112: si el radar ya está en la base no se publica nada. Evita
            // esperas inútiles y trafico Zigbee cuando se encadenan corridas.
            $trust = (int) (Config::get('RADAR_BASELINE_TRUST_SECONDS', '900') ?? 900);
            $stored = $this->latestSnapshot($roomId);
            if ($trust > 0
                && self::settingsMatch($commands, $stored['settings'])
                && $stored['age_seconds'] !== null
                && $stored['age_seconds'] <= $trust) {
                return [
                    'ok' => true,
                    'applied' => true,
                    'persisted' => false,
                    'saved' => null,
                    'confirmed_by' => 'stored_state',
                    'warning' => 'already_at_target',
                    'published_at' => null,
                    'commands' => $commands,
                    'status' => $this->status($roomId, $device),
                ];
            }
        }

        $topic = $this->setTopic($device);
        $beforeId = $this->latestEventId($roomId);
        $publishedAt = gmdate('Y-m-d H:i:s.v');

        // F125.2: mientras se aplica o prueba una configuración, el objetivo de
        // convergencia es este candidato, no la calibración guardada.
        $this->setPending($device, $commands, $persist ? 300 : 600);

        try {
            $env = $this->profileEnv($device);
        } catch (Throwable $e) {
            $this->clearPending($device);
            return [
                'ok' => false,
                'applied' => false,
                'persisted' => false,
                'error' => $e->getMessage(),
                'warning' => 'No se pudo resolver el broker MQTT del dispositivo',
                'commands' => $commands,
                'status' => $this->status($roomId, $device),
            ];
        }

        $result = ($this->publisher)($topic, json_encode($commands, JSON_UNESCAPED_SLASHES), $env);
        if (($result['ok'] ?? false) !== true) {
            $this->clearPending($device);
            return [
                'ok' => false,
                'applied' => false,
                'persisted' => false,
                'error' => (string) ($result['error'] ?? 'publish_failed'),
                'warning' => 'No se pudo publicar la orden en Zigbee2MQTT',
                'status' => $this->status($roomId, $device),
            ];
        }

        // F125.4: primero se confirma por lectura directa del broker. Es
        // independiente de que el spool haya persistido el evento, que es lo
        // que bloqueaba apply/reset cuando el spool iba atrasado.
        $directEnabled = Config::getBool('RADAR_DIRECT_CONFIRM_ENABLED', true);
        $direct = $directEnabled ? $this->awaitDirectConfirmation($device, $commands) : null;
        if (is_array($direct) && ($direct['error'] ?? '') === 'reported_settings_invalid') {
            $this->clearPending($device);
            $reason = (string) ($direct['invalid_reason'] ?? 'reported_settings_invalid');
            return [
                'ok' => true,
                'applied' => false,
                'persisted' => false,
                'error' => null,
                'warning' => 'reported_settings_invalid:' . $reason,
                'settings_invalid_reason' => $reason,
                'invalid_reports' => 2,
                'status' => $this->status($roomId, $device),
            ];
        }

        $applied = false;
        $latest = null;
        $invalidReports = 0;
        $invalidReasonSeen = null;
        $consecutiveInvalid = 0;
        $confirmedBy = null;

        if ($direct !== null) {
            $applied = true;
            $confirmedBy = 'direct_push';
            $presence = $direct['presence'] ?? null;
            $latest = [
                'id' => null,
                'value' => $presence === true ? 'PRESENT' : ($presence === false ? 'ABSENT' : null),
                'last_at' => isset($direct['received_at']) && is_string($direct['received_at'])
                    ? $direct['received_at'] : gmdate('Y-m-d H:i:s.v'),
                'age_seconds' => 0,
                'settings' => is_array($direct['settings'] ?? null) ? $direct['settings'] : [],
                'report' => null,
            ];
        } else {
            // Read-back por push: el radar reemite su configuración en cada reporte.
            // Se acepta solo un evento NUEVO (id > beforeId) que refleje lo pedido.
            $dbAttempts = $directEnabled ? min($waitAttempts, 5) : $waitAttempts;
            for ($attempt = 0; $attempt < max(1, $dbAttempts); $attempt++) {
                if ($attempt > 0) {
                    usleep(700_000);
                }
                $snapshot = $this->latestSnapshot($roomId);
                if ($snapshot['id'] === null || $snapshot['id'] <= $beforeId) {
                    continue;
                }
                // F125.3: un reporte fuera de rango (p. ej. maximum_range=-334889.04
                // en el broker dev) no confirma ni niega la orden. Se ignora y, si
                // se repite, se corta la espera con un error accionable.
                $snapshotInvalid = self::settingsInvalidReason($snapshot['settings']);
                if ($snapshotInvalid !== null) {
                    $invalidReports++;
                    $invalidReasonSeen = $snapshotInvalid;
                    $consecutiveInvalid++;
                    if ($consecutiveInvalid >= 3) {
                        break;
                    }
                    continue;
                }
                $consecutiveInvalid = 0;
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
                    $confirmedBy = 'push';
                    break;
                }
            }
        }
        if (!$applied && !$persist) {
            // F112: restaurar la base cuando el radar YA está en la base no debe
            // exigir un push nuevo. El ZY-M100 solo publica cambios, así que una
            // segunda corrida seguida podía quedarse en 409 sin motivo real.
            $trust = (int) (Config::get('RADAR_BASELINE_TRUST_SECONDS', '900') ?? 900);
            $stored = $this->latestSnapshot($roomId);
            if ($trust > 0
                && self::settingsMatch($commands, $stored['settings'])
                && $stored['age_seconds'] !== null
                && $stored['age_seconds'] <= $trust) {
                $applied = true;
                $confirmedBy = 'stored_state';
                $latest = $stored;
            }
        }

        if (!$applied && $invalidReasonSeen !== null) {
            // El radar solo publicó valores imposibles: no hay confirmación
            // posible hasta corregir el broker/converter. Se devuelve el motivo
            // exacto en lugar de un `not_confirmed` genérico.
            $this->clearPending($device);
            return [
                'ok' => true,
                'applied' => false,
                'persisted' => false,
                'error' => null,
                'warning' => 'reported_settings_invalid:' . $invalidReasonSeen,
                'settings_invalid_reason' => $invalidReasonSeen,
                'invalid_reports' => $invalidReports,
                'status' => $this->status($roomId, $device),
            ];
        }

        $snapshotForStatus = $latest ?? $this->latestSnapshot($roomId);
        $invalidReason = self::settingsInvalidReason($snapshotForStatus['settings']);
        if ($applied && $invalidReason !== null) {
            // El dispositivo confirmó el comando pero quedó en un estado inválido
            // (p. ej. max 0,01 m). No se persiste ni se sigue calibrando.
            $this->clearPending($device);
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
            $saved = $this->persistSnapshot($device, $commands, $snapshotForStatus['last_at'], $source);
            // La calibración ya es la fuente de verdad; se retira el objetivo
            // temporal para que la convergencia use `calibration`.
            $this->clearPending($device);
            // Refresca el meta del dispositivo para que `status.saved` refleje
            // exactamente la calibración recién persistida.
            $fresh = $this->pdo->prepare('SELECT meta_json FROM devices WHERE id = :id LIMIT 1');
            $fresh->execute([':id' => (int) $device['id']]);
            $decoded = json_decode((string) ($fresh->fetchColumn() ?: ''), true);
            if (is_array($decoded)) {
                $device['meta'] = $decoded;
            }
        } elseif (!$applied) {
            // No se confirmó el candidato: se vuelve al objetivo guardado.
            $this->clearPending($device);
        }
        $status = $this->status($roomId, $device);
        if ($direct !== null) {
            // La confirmación directa es más fresca que el spool; el panel y
            // el wizard deben recibir la configuración realmente confirmada.
            $directSettings = is_array($direct['settings'] ?? null) ? $direct['settings'] : [];
            $directAt = isset($direct['received_at']) && is_string($direct['received_at'])
                ? $direct['received_at'] : gmdate('Y-m-d H:i:s.v');
            $status['settings'] = $directSettings;
            $status['settings_at'] = $directAt;
            $status['settings_age_seconds'] = 0;
            $status['stale'] = false;
            $status['settings_valid'] = self::isValidSettings($directSettings);
            $status['settings_invalid_reason'] = self::settingsInvalidReason($directSettings);
            $status['reported_invalid'] = false;
            $status['reported_invalid_reason'] = null;
            $status['latest_reported_settings'] = $directSettings;
            $status['reported_unstable'] = false;
            $status['reported_variants'] = [];
            $status['using_valid_snapshot'] = true;
            if (isset($status['device']) && is_array($status['device'])) {
                $status['device']['online'] = true;
            }
        }

        return [
            'ok' => true,
            'applied' => $applied,
            'persisted' => $saved !== null,
            'saved' => $saved,
            'confirmed_by' => $confirmedBy,
            'warning' => $applied
                ? ($confirmedBy === 'stored_state' ? 'already_at_target' : null)
                : 'El radar aún no ha confirmado la nueva configuración por push; no se guarda',
            'published_at' => $publishedAt,
            'commands' => $commands,
            'status' => $status,
        ];
    }

    /**
     * Compara los parámetros pedidos con los del último reporte real.
     * Compartido por la confirmación por push y por la base ya aplicada.
     *
     * @param array<string,mixed> $commands
     * @param array<string,mixed> $settings
     */
    public static function settingsMatch(array $commands, array $settings): bool
    {
        if ($commands === [] || $settings === []) {
            return false;
        }
        foreach ($commands as $key => $expected) {
            $actual = $settings[$key] ?? null;
            if (!is_numeric($actual) || abs((float) $actual - (float) $expected) > 0.001) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array{id:?int,value:?string,last_at:?string,age_seconds:?int,
     *               settings:array<string,mixed>,report:?array<string,mixed>}
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
            return ['id' => null, 'value' => null, 'last_at' => null, 'age_seconds' => null,
                'settings' => [], 'report' => null];
        }
        $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
        $settings = is_array($meta) && is_array($meta['settings'] ?? null) ? $meta['settings'] : [];
        $report = is_array($meta) && is_array($meta['presence_report'] ?? null)
            ? $meta['presence_report'] : null;
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
            'report' => $report,
        ];
    }

    /**
     * Lecturas VÁLIDAS recientes. Devuelve la más reciente y, si hay varias
     * configuraciones distintas dentro de la ventana de inestabilidad, las
     * variantes. Así el panel puede mostrar un único estado "inestable" en vez
     * de alternar entre applied/mismatch.
     *
     * @return array{latest:?array<string,mixed>,variants:list<array<string,mixed>>,unstable:bool}
     */
    private function recentValidSnapshots(int $roomId, int $maxAgeSeconds, int $unstableWindowSeconds): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - max(1, $maxAgeSeconds));
        $windowSince = time() - max(1, $unstableWindowSeconds);
        $stmt = $this->pdo->prepare(
            "SELECT id, value, occurred_at, meta_json
               FROM presence_events
              WHERE room_id = :rid AND provider = 'ZIGBEE2MQTT' AND sensor = 'PRESENCE'
                AND occurred_at >= :since
              ORDER BY id DESC LIMIT 100"
        );
        $stmt->execute([':rid' => $roomId, ':since' => $since]);
        $latest = null;
        $variants = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
            $settings = is_array($meta) && is_array($meta['settings'] ?? null) ? $meta['settings'] : [];
            if ($settings === [] || self::settingsInvalidReason($settings) !== null || !self::isValidSettings($settings)) {
                continue;
            }
            $lastAt = (string) ($row['occurred_at'] ?? '');
            $age = null;
            $ts = $lastAt === '' ? false : strtotime($lastAt . ' UTC');
            if ($ts !== false) {
                $age = max(0, time() - $ts);
            }
            if ($latest === null) {
                $latest = [
                    'id' => (int) $row['id'],
                    'value' => (string) ($row['value'] ?? ''),
                    'last_at' => $lastAt === '' ? null : $lastAt,
                    'age_seconds' => $age,
                    'settings' => $settings,
                    'report' => is_array($meta['presence_report'] ?? null) ? $meta['presence_report'] : null,
                ];
            }
            if ($ts !== false && $ts >= $windowSince) {
                $key = self::settingsSignature($settings);
                if (!isset($variants[$key])) {
                    $variants[$key] = self::comparableSettings($settings);
                }
            }
        }
        return [
            'latest' => $latest,
            'variants' => array_values($variants),
            'unstable' => count($variants) > 1,
        ];
    }

    /** @param array<string,mixed> $settings */
    private static function settingsSignature(array $settings): string
    {
        $parts = [];
        foreach (RadarCalibrationDrift::KEYS as $key) {
            $parts[$key] = isset($settings[$key]) && is_numeric($settings[$key])
                ? round((float) $settings[$key], 3) : null;
        }
        return json_encode($parts, JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,float|null>
     */
    private static function comparableSettings(array $settings): array
    {
        $out = [];
        foreach (RadarCalibrationDrift::KEYS as $key) {
            $out[$key] = isset($settings[$key]) && is_numeric($settings[$key])
                ? (float) $settings[$key] : null;
        }
        return $out;
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
     * Overrides MQTT del dispositivo según `meta.mqtt_profile`.
     *
     * @param array{id:int,external_id:string,meta?:array<string,mixed>|null} $device
     * @return array<string,string>
     */
    private function profileEnv(array $device): array
    {
        $meta = is_array($device['meta'] ?? null) ? $device['meta'] : [];
        return ZigbeeMqttProfile::envForDevice($meta, $this->profileConfigFile);
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
     * Fija el objetivo temporal de convergencia. Se mezcla con la calibración
     * guardada para que el objetivo sea siempre una configuración completa.
     *
     * @param array{id:int,external_id:string,meta?:array<string,mixed>|null} $device
     * @param array<string,mixed> $settings
     */
    public function setPending(array $device, array $settings, int $ttlSeconds = 300): void
    {
        $commands = self::normalizeCommands($settings);
        if ($commands === []) {
            return;
        }
        $ttlSeconds = max(30, min(900, $ttlSeconds));
        $meta = $this->loadMeta((int) $device['id']);
        $base = [];
        foreach ([self::PENDING_KEY, 'calibration', 'calibration_previous'] as $key) {
            if (is_array($meta[$key] ?? null)) {
                $base = $meta[$key];
                break;
            }
        }
        $target = array_merge($base, $commands);
        if (!self::isValidSettings($target)) {
            return;
        }
        $meta[self::PENDING_KEY] = $target;
        $meta[self::PENDING_UNTIL_KEY] = gmdate('Y-m-d\TH:i:s\Z', time() + $ttlSeconds);
        $this->saveMeta((int) $device['id'], $meta);
    }

    /** @param array{id:int,external_id:string,meta?:array<string,mixed>|null} $device */
    public function clearPending(array $device): void
    {
        $meta = $this->loadMeta((int) $device['id']);
        if (!array_key_exists(self::PENDING_KEY, $meta)
            && !array_key_exists(self::PENDING_UNTIL_KEY, $meta)) {
            return;
        }
        unset($meta[self::PENDING_KEY], $meta[self::PENDING_UNTIL_KEY]);
        $this->saveMeta((int) $device['id'], $meta);
    }

    /** @return array<string,mixed> */
    private function loadMeta(int $deviceId): array
    {
        $stmt = $this->pdo->prepare('SELECT meta_json FROM devices WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $deviceId]);
        $raw = $stmt->fetchColumn();
        $decoded = json_decode((string) ($raw === false ? '' : $raw), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $meta */
    private function saveMeta(int $deviceId, array $meta): void
    {
        $this->pdo->prepare('UPDATE devices SET meta_json = :meta WHERE id = :id')
            ->execute([
                ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':id' => $deviceId,
            ]);
    }

    /**
     * F104/RF-215: guarda en la ficha del dispositivo la calibración que el
     * radar confirmó por push. No escribe en el dispositivo (eso ya lo hizo la
     * orden MQTT); solo deja la fotografía para auditoría y estado del panel.
     *
     * @param array{id:int,external_id:string,meta?:array<string,mixed>|null} $device
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public function persistCalibration(
        array $device,
        array $settings,
        ?string $settingsAt,
        string $source = 'sensores-f104-auto',
        array $extra = []
    ): array
    {
        $stmt = $this->pdo->prepare('SELECT meta_json FROM devices WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $device['id']]);
        $raw = $stmt->fetchColumn();
        $meta = json_decode((string) ($raw === false ? '' : $raw), true);
        $meta = is_array($meta) ? $meta : [];

        $snap = [
            'source' => $source,
            'calibrated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'settings_at' => $settingsAt,
        ];
        foreach (['maximum_range', 'minimum_range', 'radar_sensitivity', 'detection_delay', 'fading_time'] as $key) {
            if (isset($settings[$key]) && is_numeric($settings[$key])) {
                $snap[$key] = (float) $settings[$key];
            }
        }
        if ($extra !== []) {
            $snap = array_merge($snap, $extra);
        }
        $meta['calibration'] = $snap;
        $this->pdo->prepare('UPDATE devices SET meta_json = :meta WHERE id = :id')
            ->execute([
                ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                ':id'   => (int) $device['id'],
            ]);
        return $snap;
    }

    /**
     * @param array{id:int,external_id:string,meta?:array<string,mixed>|null} $device
     * @param array<string,int|float> $commands
     * @return array<string,mixed>
     */
    private function persistSnapshot(array $device, array $commands, ?string $settingsAt, string $source = 'dashboard-calib-zigbee'): array
    {
        $stmt = $this->pdo->prepare('SELECT meta_json FROM devices WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $device['id']]);
        $raw = $stmt->fetchColumn();
        $meta = json_decode((string) ($raw === false ? '' : $raw), true);
        $meta = is_array($meta) ? $meta : [];

        $snap = [
            'source' => $source,
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
    private function runPublisher(string $topic, string $payload, array $env = []): array
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
            $apiDir,
            ZigbeeMqttProfile::childEnv($env)
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
