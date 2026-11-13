<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sensors\RadarAutoTune;
use App\Domain\Sensors\RadarDistanceReport;
use App\Domain\Sensors\RadarFreshness;
use App\Domain\Sensors\RadarDistanceReportRepository;
use App\Domain\Sensors\RadarGuidedCalibration;
use App\Domain\Sensors\RadarPresenceReportRepository;
use App\Domain\Sensors\RadarScope;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Sensors\RadarLiveService;
use App\Infrastructure\Sensors\RadarStudioStore;
use App\Infrastructure\Sensors\SensorContextBuilder;
use App\Infrastructure\Zigbee\RadarCalibrationConvergence;
use App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService;
use PDO;
use RuntimeException;
use Throwable;

/**
 * RadarStudioController — F125/RF-350…RF-355.
 *
 * API del "Radar Studio": vista en vivo del radar Zigbee, aplicación de perfil
 * con confirmación por push y asistentes de calibración (paseo, sensibilidad,
 * reposo exterior y reentrada).
 *
 * No usa Tuya, no crea pollers y no inventa valores: todo sale de la BD local
 * y del push de Zigbee2MQTT. La UI presenta; el servidor decide.
 */
final class RadarStudioController
{
    /** @var callable():PDO */
    private $pdoProvider;
    private RadarStudioStore $store;
    /** @var callable(string,string):array{ok:bool,error:?string}|null */
    private $publisher;

    /**
     * @param callable():PDO $pdoProvider
     * @param callable(string,string):array{ok:bool,error:?string}|null $publisher
     */
    public function __construct(callable $pdoProvider, string $stateDir, ?callable $publisher = null)
    {
        $this->pdoProvider = $pdoProvider;
        $this->store = new RadarStudioStore($stateDir);
        $this->publisher = $publisher;
    }

    // ── GET /dashboard-api/sensores/v2/radar/scope ────────────────────────

    public function scope(Request $request): Response
    {
        $roomId = (int) ($request->query['room_id'] ?? 0);
        if ($roomId <= 0) {
            return Response::json(400, ['ok' => false, 'error' => 'room_id_required']);
        }
        $window = (int) ($request->query['window'] ?? 60);
        try {
            $payload = $this->scopePayload($roomId, $window);
            return Response::json(200, array_merge(['ok' => true], $payload))
                ->withHeader('Cache-Control', 'no-store');
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::json(500, ['ok' => false, 'error' => 'scope_failed', 'message' => $e->getMessage()]);
        }
    }

    // ── GET /dashboard-api/sensores/v2/radar/live ─────────────────────────

    public function live(Request $request): Response
    {
        $roomId = (int) ($request->query['room_id'] ?? 0);
        if ($roomId <= 0) {
            return Response::json(400, ['ok' => false, 'error' => 'room_id_required']);
        }
        try {
            $payload = (new RadarLiveService($this->pdo()))->payload($roomId);
            return Response::json(200, $payload)->withHeader('Cache-Control', 'no-store');
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::json(500, ['ok' => false, 'error' => 'live_failed', 'message' => $e->getMessage()]);
        }
    }

    // ── GET /dashboard-api/sensores/v2/radar/direct ───────────────────────

    /**
     * Lectura directa del broker: no espera a que el spool persista el reporte.
     * Solo observa; no publica ni modifica la calibración.
     */
    public function direct(Request $request): Response
    {
        $roomId = (int) ($request->query['room_id'] ?? 0);
        if ($roomId <= 0) {
            return Response::json(400, ['ok' => false, 'error' => 'room_id_required']);
        }
        $timeout = (int) ($request->query['timeout'] ?? 5);
        try {
            $device = $this->deviceForRoom($roomId);
            if ($device === null) {
                throw new RuntimeException('presence_device_missing');
            }
            $read = $this->service()->readDirect($device, $timeout);
            $reported = is_array($read['settings'] ?? null) ? $read['settings'] : [];
            $directCalibration = RadarCalibrationConvergence::statusFor(
                is_array($device['meta'] ?? null) ? $device['meta'] : [],
                $reported
            );
            $directInvalidReason = ZigbeeRadarCalibrationService::settingsInvalidReason($reported);
            $directCalibration['reported_invalid'] = $directInvalidReason !== null;
            $directCalibration['reported_invalid_reason'] = $directInvalidReason;
            $directCalibration['latest_reported_settings'] = $reported;
            $directCalibration['reported_unstable'] = false;
            $directCalibration['reported_variants'] = [];
            return Response::json(200, [
                'ok' => true,
                'room_id' => $roomId,
                'direct' => $read,
                'calibration' => $directCalibration,
            ])->withHeader('Cache-Control', 'no-store');
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::json(500, ['ok' => false, 'error' => 'direct_failed', 'message' => $e->getMessage()]);
        }
    }

    // ── POST /dashboard-api/sensores/v2/radar/reapply ─────────────────────

    /**
     * Fuerza la convergencia a la calibración guardada. La fuente de verdad es
     * siempre `devices.meta_json.calibration`; nunca se lee del dispositivo.
     */
    public function reapply(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $roomId = (int) ($body['room_id'] ?? 0);
        if ($roomId <= 0) {
            return Response::json(400, ['ok' => false, 'error' => 'room_id_required']);
        }
        try {
            $device = $this->deviceForRoom($roomId);
            if ($device === null) {
                throw new RuntimeException('presence_device_missing');
            }
            $meta = is_array($device['meta'] ?? null) ? $device['meta'] : [];
            $desired = RadarCalibrationConvergence::desired($meta);
            if ($desired === null) {
                return Response::json(409, [
                    'ok' => false,
                    'error' => 'calibration_missing',
                    'message' => 'La ficha del radar no tiene una calibración guardada válida.',
                ]);
            }
            $commands = [];
            foreach (['maximum_range', 'minimum_range', 'radar_sensitivity', 'detection_delay', 'fading_time'] as $key) {
                if (isset($desired['settings'][$key]) && is_numeric($desired['settings'][$key])) {
                    $commands[$key] = (float) $desired['settings'][$key];
                }
            }
            $result = $this->service()->apply($roomId, $device, $commands, true, 15, 'radar-reapply');
            if (($result['applied'] ?? false) !== true) {
                return $this->notConfirmedResponse(
                    $result,
                    'El radar no ha confirmado la calibración guardada.'
                );
            }
            return Response::json(200, [
                'ok' => true,
                'result' => $result,
                'calibration' => RadarCalibrationConvergence::statusFor(
                    $meta,
                    (array) (($result['status']['settings'] ?? []))
                ),
            ]);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::json(500, ['ok' => false, 'error' => 'reapply_failed', 'message' => $e->getMessage()]);
        }
    }

    // ── POST /dashboard-api/sensores/v2/radar/profile ─────────────────────

    public function profile(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $roomId = (int) ($body['room_id'] ?? 0);
        if ($roomId <= 0) {
            return Response::json(400, ['ok' => false, 'error' => 'room_id_required']);
        }
        $input = $body['settings'] ?? null;
        if (!is_array($input) || $input === []) {
            return Response::json(400, ['ok' => false, 'error' => 'settings_required']);
        }
        try {
            $device = $this->deviceForRoom($roomId);
            if ($device === null) {
                throw new RuntimeException('presence_device_missing');
            }
            $settings = ZigbeeRadarCalibrationService::normalizeCommands($input);
            if ($settings === []) {
                return Response::json(400, ['ok' => false, 'error' => 'no_parameters']);
            }
            $current = $this->service()->status($roomId, $device);
            $currentSettings = (array) ($current['settings'] ?? []);
            $fresh = ($current['stale'] ?? true) === false
                && (int) ($current['settings_age_seconds'] ?? 9999) <= 300;
            if ($fresh && ZigbeeRadarCalibrationService::settingsMatch($settings, $currentSettings)) {
                $saved = $this->service()->persistCalibration(
                    $device,
                    $settings,
                    $current['settings_at'] ?? null,
                    'radar-studio-v1'
                );
                $this->store->clear($roomId);
                return Response::json(200, [
                    'ok' => true,
                    'result' => [
                        'ok' => true,
                        'applied' => true,
                        'persisted' => true,
                        'saved' => $saved,
                        'confirmed_by' => 'stored_state',
                        'warning' => 'already_at_target',
                        'commands' => $settings,
                    ],
                    'scope' => $this->scopePayload($roomId, 60),
                ]);
            }
            $result = $this->service()->apply(
                $roomId,
                $device,
                $settings,
                true,
                15,
                'radar-studio-v1'
            );
            if (($result['applied'] ?? false) !== true) {
                return $this->notConfirmedResponse(
                    $result,
                    'El radar no ha confirmado la nueva configuración por push.',
                    $this->scopePayload($roomId, 60)
                );
            }
            $this->store->clear($roomId);
            return Response::json(200, [
                'ok' => true,
                'result' => $result,
                'scope' => $this->scopePayload($roomId, 60),
            ]);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::json(500, ['ok' => false, 'error' => 'profile_failed', 'message' => $e->getMessage()]);
        }
    }

    // ── POST /dashboard-api/sensores/v2/radar/autotune ────────────────────

    public function autotune(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $roomId = (int) ($body['room_id'] ?? 0);
        $action = (string) ($body['action'] ?? 'status');
        if ($roomId <= 0) {
            return Response::json(400, ['ok' => false, 'error' => 'room_id_required']);
        }
        try {
            $device = $this->deviceForRoom($roomId);
            if ($device === null) {
                throw new RuntimeException('presence_device_missing');
            }
            return $this->store->withLock($roomId, function (?array $state) use ($roomId, $action, $body, $device): Response {
                return $this->dispatchAutotune($roomId, $action, $body, $device, $state);
            });
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::json(500, ['ok' => false, 'error' => 'autotune_failed', 'message' => $e->getMessage()]);
        }
    }

    // ── GET /dashboard-api/sensores/v2/radar/wizard ───────────────────────

    public function wizard(Request $request): Response
    {
        $roomId = (int) ($request->query['room_id'] ?? 0);
        if ($roomId <= 0) {
            return Response::json(400, ['ok' => false, 'error' => 'room_id_required']);
        }
        try {
            $device = $this->deviceForRoom($roomId);
            if ($device === null) {
                throw new RuntimeException('presence_device_missing');
            }
            return $this->store->withWizardLock($roomId, function (?array $state) use ($roomId, $device): Response {
                return $this->wizardResponse($roomId, $device, $state);
            });
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::json(500, ['ok' => false, 'error' => 'wizard_failed', 'message' => $e->getMessage()]);
        }
    }

    // ── POST /dashboard-api/sensores/v2/radar/wizard ──────────────────────

    public function wizardAction(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $roomId = (int) ($body['room_id'] ?? 0);
        $action = (string) ($body['action'] ?? '');
        if ($roomId <= 0) {
            return Response::json(400, ['ok' => false, 'error' => 'room_id_required']);
        }
        try {
            $device = $this->deviceForRoom($roomId);
            if ($device === null) {
                throw new RuntimeException('presence_device_missing');
            }
            return $this->store->withWizardLock($roomId, function (?array $state) use ($roomId, $device, $action, $body): Response {
                return $this->dispatchWizardAction($roomId, $device, $state, $action, $body);
            });
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            return Response::json(500, ['ok' => false, 'error' => 'wizard_failed', 'message' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $device
     * @param array<string,mixed>|null $state
     */
    private function dispatchAutotune(int $roomId, string $action, array $body, array $device, ?array $state): Response
    {
        switch ($action) {
            case 'status':
                return Response::json(200, [
                    'ok' => true,
                    'state' => $state,
                    'scope' => $this->scopePayload($roomId, (int) ($body['window'] ?? 60)),
                ]);
            case 'auto_range_start':
                return $this->autoRangeStart($roomId, $body, $device);
            case 'auto_range_finish':
                return $this->autoRangeFinish($roomId, $body, $device, $state);
            case 'sensitivity_recommend':
                return $this->sensitivityRecommend($roomId, $body, $device);
            case 'sensitivity_apply':
                return $this->sensitivityApply($roomId, $body, $device);
            case 'soak_start':
                return $this->soakStart($roomId, $body, $device);
            case 'soak_check':
                return $this->soakCheck($roomId, $device, $state);
            case 'reentry_mark':
                return $this->reentryMark($roomId, $device);
            case 'reentry_check':
                return $this->reentryCheck($roomId, $device, $state);
            case 'restore':
                return $this->restoreOriginal($roomId, $device, $state, true);
            case 'cancel':
                return $this->restoreOriginal($roomId, $device, $state, false);
            default:
                return Response::json(400, ['ok' => false, 'error' => 'unknown_action']);
        }
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $device
     */
    private function autoRangeStart(int $roomId, array $body, array $device): Response
    {
        $status = $this->service()->status($roomId, $device);
        $settings = (array) ($status['settings'] ?? []);
        if (!ZigbeeRadarCalibrationService::isValidSettings($settings)) {
            throw new RuntimeException('settings_invalid');
        }
        $raw = $this->authenticPresence($status)['state'];
        if ($raw !== 'PRESENT') {
            return Response::json(409, [
                'ok' => false,
                'error' => 'presence_not_detected',
                'message' => 'Ponte delante del radar y muévete hasta que te detecte. Después empieza el paseo.',
                'scope' => $this->scopePayload($roomId, 60),
            ]);
        }
        $original = ZigbeeRadarCalibrationService::normalizeCommands($settings);
        $temp = $original;
        $temp['maximum_range'] = 9.5;
        $result = $this->service()->apply($roomId, $device, $temp, false, 15, 'radar-studio-v1-temp');
        $temp = (array) ($result['commands'] ?? $temp);
        if (($result['applied'] ?? false) !== true) {
            return Response::json(409, [
                'ok' => false,
                'error' => 'not_confirmed',
                'message' => (string) ($result['warning'] ?? 'El radar no confirmó el alcance temporal de 9,5 m.'),
                'result' => $result,
                'scope' => $this->scopePayload($roomId, 60),
            ]);
        }
        $now = $this->nowIso();
        $state = [
            'version' => 1,
            'room_id' => $roomId,
            'action' => 'auto_range',
            'phase' => 'walking',
            'started_at' => $now,
            'updated_at' => $now,
            'operator' => (string) ($body['operator'] ?? ''),
            'original_settings' => $original,
            'temp_settings' => $temp,
            'config_key' => RadarDistanceReport::configKey($temp),
            'reductions' => 0,
            'max_reductions' => 6,
            'history' => [],
        ];
        $this->store->save($roomId, $state);
        return Response::json(200, [
            'ok' => true,
            'state' => $state,
            'instruction' => 'Camina despacio hacia atrás, en línea recta, hasta que el radar deje de detectarte. No hace falta medir nada.',
            'scope' => $this->scopePayload($roomId, 60),
        ]);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $device
     * @param array<string,mixed>|null $state
     */
    private function autoRangeFinish(int $roomId, array $body, array $device, ?array $state): Response
    {
        if ($state === null || ($state['action'] ?? '') !== 'auto_range') {
            return Response::json(409, ['ok' => false, 'error' => 'no_active_operation']);
        }
        $started = (string) ($state['started_at'] ?? '');
        $sinceSql = $this->sqlFromIso($started);
        $configKey = isset($state['config_key']) ? (string) $state['config_key'] : null;
        $distances = $this->filterByConfig(
            (new RadarDistanceReportRepository($this->pdo()))->recentForRoom($roomId, $sinceSql, 1000),
            $configKey,
            true
        );
        $presence = $this->filterByConfig(
            (new RadarPresenceReportRepository($this->pdo()))->recentForRoom($roomId, $sinceSql, 500),
            $configKey,
            true
        );
        $now = $this->nowIso();
        $original = (array) ($state['original_settings'] ?? []);
        $boundary = RadarAutoTune::boundary(
            $distances,
            $presence,
            $started,
            $now,
            RadarAutoTune::DEFAULT_MARGIN_M,
            (float) ($original['minimum_range'] ?? 0.0),
            9.5
        );
        if (($boundary['found'] ?? false) !== true || $boundary['suggested_max'] === null) {
            return Response::json(409, [
                'ok' => false,
                'error' => 'boundary_not_found',
                'message' => 'No se ha detectado la pérdida de presencia. Aléjate un poco más, espera el ABSENT y vuelve a cerrar el paseo.',
                'boundary' => $boundary,
                'state' => $state,
                'scope' => $this->scopePayload($roomId, 60),
            ]);
        }
        $final = $original;
        $final['maximum_range'] = (float) $boundary['suggested_max'];
        $result = $this->service()->apply($roomId, $device, $final, true, 15, 'radar-studio-v1');
        if (($result['applied'] ?? false) !== true) {
            // No dejar el radar en 9,5 m si la aplicación final falla.
            $restore = $this->service()->apply($roomId, $device, $original, false, 12, 'radar-studio-v1-restore');
            $this->store->clear($roomId);
            return Response::json(409, [
                'ok' => false,
                'error' => 'not_confirmed',
                'message' => (string) ($result['warning'] ?? 'El radar no confirmó el alcance medido.'),
                'restored' => ($restore['applied'] ?? false) === true,
                'result' => $result,
                'boundary' => $boundary,
                'scope' => $this->scopePayload($roomId, 60),
            ]);
        }
        $this->store->clear($roomId);
        $commands = (array) ($result['commands'] ?? $final);
        return Response::json(200, [
            'ok' => true,
            'result' => $result,
            'boundary' => $boundary,
            'applied_maximum_range' => $commands['maximum_range'] ?? $final['maximum_range'],
            'scope' => $this->scopePayload($roomId, 60),
        ]);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $device
     */
    private function sensitivityRecommend(int $roomId, array $body, array $device): Response
    {
        $status = $this->service()->status($roomId, $device);
        $settings = (array) ($status['settings'] ?? []);
        $window = max(5, min(60, (int) ($body['window'] ?? 15)));
        $sinceSql = gmdate('Y-m-d H:i:s', time() - $window);
        $configKey = RadarDistanceReport::configKey($settings);
        $distances = $this->filterByConfig(
            (new RadarDistanceReportRepository($this->pdo()))->recentForRoom($roomId, $sinceSql, 1000),
            $configKey,
            true
        );
        $presence = $this->filterByConfig(
            (new RadarPresenceReportRepository($this->pdo()))->recentForRoom($roomId, $sinceSql, 500),
            $configKey,
            true
        );
        $recommendation = RadarAutoTune::sensitivity(
            $distances,
            $presence,
            $settings['radar_sensitivity'] ?? null,
            $window,
            $this->nowIso()
        );
        return Response::json(200, [
            'ok' => true,
            'window_seconds' => $window,
            'recommendation' => $recommendation,
            'scope' => $this->scopePayload($roomId, max(60, $window)),
        ]);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $device
     */
    private function sensitivityApply(int $roomId, array $body, array $device): Response
    {
        $value = $body['value'] ?? null;
        if (!is_numeric($value) || (int) $value < 0 || (int) $value > 9) {
            return Response::json(400, ['ok' => false, 'error' => 'sensitivity_out_of_bounds']);
        }
        $status = $this->service()->status($roomId, $device);
        $settings = ZigbeeRadarCalibrationService::normalizeCommands((array) ($status['settings'] ?? []));
        if ($settings === []) {
            throw new RuntimeException('settings_unavailable');
        }
        $settings['radar_sensitivity'] = (int) $value;
        $current = (array) ($status['settings'] ?? []);
        $fresh = ($status['stale'] ?? true) === false
            && (int) ($status['settings_age_seconds'] ?? 9999) <= 300;
        if ($fresh && ZigbeeRadarCalibrationService::settingsMatch($settings, $current)) {
            $saved = $this->service()->persistCalibration(
                $device,
                $settings,
                $status['settings_at'] ?? null,
                'radar-studio-v1'
            );
            return Response::json(200, [
                'ok' => true,
                'result' => [
                    'ok' => true,
                    'applied' => true,
                    'persisted' => true,
                    'saved' => $saved,
                    'confirmed_by' => 'stored_state',
                    'warning' => 'already_at_target',
                    'commands' => $settings,
                ],
                'scope' => $this->scopePayload($roomId, 60),
            ]);
        }
        $result = $this->service()->apply($roomId, $device, $settings, true, 15, 'radar-studio-v1');
        if (($result['applied'] ?? false) !== true) {
            return Response::json(409, [
                'ok' => false,
                'error' => 'not_confirmed',
                'message' => (string) ($result['warning'] ?? 'El radar no confirmó la sensibilidad.'),
                'result' => $result,
                'scope' => $this->scopePayload($roomId, 60),
            ]);
        }
        return Response::json(200, [
            'ok' => true,
            'result' => $result,
            'scope' => $this->scopePayload($roomId, 60),
        ]);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $device
     */
    private function soakStart(int $roomId, array $body, array $device): Response
    {
        $status = $this->service()->status($roomId, $device);
        $settings = ZigbeeRadarCalibrationService::normalizeCommands((array) ($status['settings'] ?? []));
        if ($settings === []) {
            throw new RuntimeException('settings_unavailable');
        }
        $latest = (new RadarPresenceReportRepository($this->pdo()))->latestForRoom($roomId);
        $baseline = strtoupper(trim((string) ($latest['value'] ?? '')));
        $raw = $this->authenticPresence($status)['state'];
        if ($baseline !== 'ABSENT' && $raw !== 'ABSENT') {
            return Response::json(409, [
                'ok' => false,
                'error' => 'presence_still_detected',
                'message' => 'El radar aún te detecta. Sal de la sala y espera a que deje de detectarte antes de empezar el reposo.',
                'scope' => $this->scopePayload($roomId, 60),
            ]);
        }
        $now = $this->nowIso();
        $state = [
            'version' => 1,
            'room_id' => $roomId,
            'action' => 'soak',
            'phase' => 'monitoring',
            'started_at' => $now,
            'updated_at' => $now,
            'operator' => (string) ($body['operator'] ?? ''),
            'original_settings' => $settings,
            'config_key' => RadarDistanceReport::configKey((array) ($status['settings'] ?? [])),
            'baseline_value' => 'ABSENT',
            'reductions' => 0,
            'max_reductions' => 6,
            'history' => [],
        ];
        $this->store->save($roomId, $state);
        return Response::json(200, [
            'ok' => true,
            'state' => $state,
            'instruction' => 'Permanece fuera de la sala y no vuelvas hasta que termine el reposo. Si el radar te detecta, se reducirá el alcance por pasos.',
            'scope' => $this->scopePayload($roomId, 60),
        ]);
    }

    /**
     * @param array<string,mixed> $device
     * @param array<string,mixed>|null $state
     */
    private function soakCheck(int $roomId, array $device, ?array $state): Response
    {
        if ($state === null || ($state['action'] ?? '') !== 'soak') {
            return Response::json(409, ['ok' => false, 'error' => 'no_active_operation']);
        }
        $status = $this->service()->status($roomId, $device);
        $settings = (array) ($status['settings'] ?? []);
        $configKey = isset($state['config_key']) ? (string) $state['config_key'] : null;
        $sinceSql = $this->sqlFromIso((string) ($state['started_at'] ?? ''));
        $presence = $this->filterByConfig(
            (new RadarPresenceReportRepository($this->pdo()))->recentForRoom($roomId, $sinceSql, 500),
            $configKey,
            true
        );
        $now = $this->nowIso();
        $verdict = RadarAutoTune::soak(
            $presence,
            (string) ($state['started_at'] ?? ''),
            $now,
            RadarAutoTune::DEFAULT_SOAK_SECONDS,
            (string) ($state['baseline_value'] ?? 'ABSENT')
        );

        if (($verdict['result'] ?? '') === 'detected') {
            $reductions = (int) ($state['reductions'] ?? 0);
            $maxReductions = (int) ($state['max_reductions'] ?? 6);
            $currentMax = (float) ($settings['maximum_range'] ?? 0.0);
            $minRange = (float) ($state['original_settings']['minimum_range'] ?? 0.0);
            $floor = max(0.15, $minRange + RadarAutoTune::GRID_M);
            $newMax = RadarAutoTune::snap($currentMax - 0.30, $floor, max($floor, $currentMax - RadarAutoTune::GRID_M));
            if ($reductions >= $maxReductions || $newMax >= $currentMax - 0.001) {
                $state['phase'] = 'stopped';
                $state['updated_at'] = $now;
                $state['history'][] = ['at' => $now, 'result' => 'stopped', 'verdict' => $verdict];
                $this->store->save($roomId, $state);
                return Response::json(200, [
                    'ok' => true,
                    'result' => 'stopped',
                    'reason' => $reductions >= $maxReductions ? 'attempts_reached' : 'range_min_reached',
                    'message' => 'No se ha podido dejar de detectar fuera con los ajustes automáticos. Revisa la colocación del radar.',
                    'state' => $state,
                    'verdict' => $verdict,
                    'scope' => $this->scopePayload($roomId, 60),
                ]);
            }
            $temp = $settings;
            $temp['maximum_range'] = $newMax;
            $result = $this->service()->apply($roomId, $device, $temp, false, 15, 'radar-studio-v1-temp');
            $temp = (array) ($result['commands'] ?? $temp);
            if (($result['applied'] ?? false) !== true) {
                return Response::json(409, [
                    'ok' => false,
                    'error' => 'not_confirmed',
                    'message' => (string) ($result['warning'] ?? 'El radar no confirmó la reducción de alcance.'),
                    'result' => $result,
                    'scope' => $this->scopePayload($roomId, 60),
                ]);
            }
            $after = $this->service()->status($roomId, $device);
            if (($after['stale'] ?? false) === true) {
                $state['updated_at'] = $now;
                $this->store->save($roomId, $state);
                return Response::json(409, [
                    'ok' => false,
                    'error' => 'presence_stale',
                    'message' => 'El radar ha dejado de reportar durante el reposo. Comprueba la alimentación y la red Zigbee.',
                    'state' => $state,
                    'scope' => $this->scopePayload($roomId, 60),
                ]);
            }
            $afterRaw = $this->authenticPresence($after)['state'];
            $state['reductions'] = $reductions + 1;
            $state['started_at'] = $now;
            $state['updated_at'] = $now;
            $state['config_key'] = RadarDistanceReport::configKey($temp);
            $state['baseline_value'] = $afterRaw === 'PRESENT' ? 'PRESENT' : 'ABSENT';
            $state['history'][] = ['at' => $now, 'result' => 'reducing', 'from' => $currentMax, 'to' => $newMax, 'verdict' => $verdict];
            $this->store->save($roomId, $state);
            return Response::json(200, [
                'ok' => true,
                'result' => 'reducing',
                'attempt' => $state['reductions'],
                'maximum_range' => $newMax,
                'message' => 'Se ha reducido el alcance a ' . number_format($newMax, 2, ',', '') . ' m. Sigue fuera de la sala.',
                'state' => $state,
                'verdict' => $verdict,
                'scope' => $this->scopePayload($roomId, 60),
            ]);
        }

        if (($verdict['result'] ?? '') === 'clear') {
            // Los ajustes ya se aplicaron y confirmaron durante el reposo (o
            // eran los vigentes). Persistir la foto no exige un push nuevo.
            $saved = $this->service()->persistCalibration(
                $device,
                $settings,
                $status['settings_at'] ?? null,
                'radar-studio-v1'
            );
            $this->store->clear($roomId);
            return Response::json(200, [
                'ok' => true,
                'result' => 'clear',
                'message' => 'Reposo correcto: el radar no detecta a nadie fuera de la sala.',
                'saved' => $saved,
                'verdict' => $verdict,
                'scope' => $this->scopePayload($roomId, 60),
            ]);
        }

        return Response::json(200, [
            'ok' => true,
            'result' => $verdict['result'] ?? 'inconclusive',
            'verdict' => $verdict,
            'state' => $state,
            'scope' => $this->scopePayload($roomId, 60),
        ]);
    }

    /** @param array<string,mixed> $device */
    private function reentryMark(int $roomId, array $device): Response
    {
        $status = $this->service()->status($roomId, $device);
        $raw = $this->authenticPresence($status)['state'];
        if ($raw !== 'ABSENT') {
            return Response::json(409, [
                'ok' => false,
                'error' => 'presence_still_detected',
                'message' => 'Sal de la sala y espera a que el radar deje de detectarte antes de marcar la reentrada.',
                'scope' => $this->scopePayload($roomId, 60),
            ]);
        }
        $latest = (new RadarPresenceReportRepository($this->pdo()))->latestForRoom($roomId);
        $now = $this->nowIso();
        $state = [
            'version' => 1,
            'room_id' => $roomId,
            'action' => 'reentry',
            'phase' => 'waiting',
            'started_at' => $now,
            'updated_at' => $now,
            'baseline_marker' => (string) ($latest['marker'] ?? ''),
            'reductions' => 0,
            'max_reductions' => 0,
            'history' => [],
        ];
        $this->store->save($roomId, $state);
        return Response::json(200, [
            'ok' => true,
            'state' => $state,
            'instruction' => 'Entra ahora en la sala. El radar debe volver a detectarte.',
            'scope' => $this->scopePayload($roomId, 60),
        ]);
    }

    /**
     * @param array<string,mixed> $device
     * @param array<string,mixed>|null $state
     */
    private function reentryCheck(int $roomId, array $device, ?array $state): Response
    {
        if ($state === null || ($state['action'] ?? '') !== 'reentry') {
            return Response::json(409, ['ok' => false, 'error' => 'no_active_operation']);
        }
        $sinceSql = $this->sqlFromIso((string) ($state['started_at'] ?? ''));
        $presence = (new RadarPresenceReportRepository($this->pdo()))->recentForRoom($roomId, $sinceSql, 500);
        $verdict = RadarAutoTune::reentry(
            $presence,
            (string) ($state['started_at'] ?? ''),
            $this->nowIso(),
            (string) ($state['baseline_marker'] ?? ''),
            90
        );
        if (($verdict['result'] ?? '') === 'entered') {
            $this->store->clear($roomId);
            return Response::json(200, [
                'ok' => true,
                'result' => 'entered',
                'message' => 'Reentrada verificada: el radar ha vuelto a detectarte.',
                'verdict' => $verdict,
                'scope' => $this->scopePayload($roomId, 60),
            ]);
        }
        if (($verdict['result'] ?? '') === 'timeout') {
            $this->store->clear($roomId);
            return Response::json(200, [
                'ok' => true,
                'result' => 'timeout',
                'message' => 'No ha llegado una presencia nueva. Revisa la sensibilidad o repite la calibración.',
                'verdict' => $verdict,
                'scope' => $this->scopePayload($roomId, 60),
            ]);
        }
        return Response::json(200, [
            'ok' => true,
            'result' => 'waiting',
            'verdict' => $verdict,
            'state' => $state,
            'scope' => $this->scopePayload($roomId, 60),
        ]);
    }

    /**
     * @param array<string,mixed> $device
     * @param array<string,mixed>|null $state
     */
    private function restoreOriginal(int $roomId, array $device, ?array $state, bool $explicit): Response
    {
        if ($state === null && !$explicit) {
            $this->store->clear($roomId);
            return Response::json(200, [
                'ok' => true,
                'result' => null,
                'scope' => $this->scopePayload($roomId, 60),
            ]);
        }
        $original = is_array($state['original_settings'] ?? null) && $state['original_settings'] !== []
            ? (array) $state['original_settings']
            : ZigbeeRadarCalibrationService::BASELINE;
        $result = $this->service()->apply($roomId, $device, $original, false, 15, 'radar-studio-v1-restore');
        $this->store->clear($roomId);
        if (($result['applied'] ?? false) !== true && $explicit) {
            return Response::json(409, [
                'ok' => false,
                'error' => 'not_confirmed',
                'message' => (string) ($result['warning'] ?? 'El radar no confirmó la restauración.'),
                'result' => $result,
                'scope' => $this->scopePayload($roomId, 60),
            ]);
        }
        return Response::json(200, [
            'ok' => true,
            'result' => $result,
            'scope' => $this->scopePayload($roomId, 60),
        ]);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function wizardResponse(int $roomId, array $device, ?array $state): Response
    {
        $payload = $this->wizardPayload($roomId, $device, $state);
        return Response::json(200, array_merge(['ok' => true], $payload))
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string,mixed> $device
     * @param array<string,mixed>|null $state
     * @return array<string,mixed>
     */
    private function wizardPayload(int $roomId, array $device, ?array $state): array
    {
        $status = $this->service()->status($roomId, $device);
        $settings = (array) ($status['settings'] ?? []);
        $configKey = RadarDistanceReport::configKey($settings);
        $now = $this->nowIso();

        if ($state !== null) {
            $alreadyChanged = ($state['status'] ?? '') === RadarGuidedCalibration::STATUS_CONFIG_CHANGED;
            if (!$alreadyChanged && ($state['config_key'] ?? null) !== $configKey) {
                $state = RadarGuidedCalibration::markConfigChanged($state, $settings, $now);
                $this->store->saveWizard($roomId, $state);
            } elseif (($state['status'] ?? '') === RadarGuidedCalibration::STATUS_RUNNING) {
                $ctx = $this->wizardContext($roomId, $settings, $status);
                $state = $this->evaluateWizard($roomId, $device, $state, $ctx, $now);
                $this->store->saveWizard($roomId, $state);
            }
        }

        $scope = $this->scopePayload($roomId, 60);
        $view = RadarGuidedCalibration::view($state);
        return [
            'wizard' => $state,
            'current_step' => $view['current_step'],
            'wizard_status' => $view['status'],
            'steps' => $view['steps'],
            'scope' => $scope,
            'server_ts' => $now,
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    private function wizardContext(int $roomId, array $settings, array $status): array
    {
        $configKey = RadarDistanceReport::configKey($settings);
        $sinceSql = gmdate('Y-m-d H:i:s', time() - 600);
        $presence = $this->filterByConfig(
            (new RadarPresenceReportRepository($this->pdo()))->recentForRoom($roomId, $sinceSql, 1000),
            $configKey,
            true
        );
        $distances = $this->filterByConfig(
            (new RadarDistanceReportRepository($this->pdo()))->recentForRoom($roomId, $sinceSql, 1000),
            $configKey,
            true
        );
        $authentic = $this->authenticPresence($status);
        return [
            'settings' => $settings,
            'authentic_state' => $authentic['state'],
            'authentic_marker' => $authentic['marker'],
            'stale' => (bool) ($status['stale'] ?? false),
            'presence_reports' => $presence,
            'distance_reports' => $distances,
            'config_key' => $configKey,
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $ctx
     */
    private function evaluateWizard(int $roomId, array $device, array $state, array $ctx, string $now): array
    {
        $current = (string) ($state['current_step'] ?? '');
        $kind = RadarGuidedCalibration::kindOf($current);
        if ($kind === 'manual_walk') {
            return RadarGuidedCalibration::evaluateWalk($state, $now, $ctx);
        }
        if ($kind === 'sensitivity') {
            return $this->evaluateSensitivityStep($roomId, $device, $state, $ctx, $now);
        }
        if (in_array($kind, ['present', 'present_reentry', 'absent'], true)) {
            $state = RadarGuidedCalibration::evaluateAuto($state, $now, $ctx);
            if (($state['status'] ?? '') === RadarGuidedCalibration::STATUS_DONE) {
                $state = $this->finalizeWizard($roomId, $device, $state);
            }
        }
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $ctx
     */
    private function evaluateSensitivityStep(
        int $roomId,
        array $device,
        array $state,
        array $ctx,
        string $now
    ): array {
        $stepId = RadarGuidedCalibration::SENSITIVITY;
        if ((string) ($state['current_step'] ?? '') !== $stepId) {
            return $state;
        }
        $step = $state['steps'][$stepId] ?? null;
        if (!is_array($step) || ($step['status'] ?? '') !== RadarGuidedCalibration::STEP_ACTIVE) {
            return $state;
        }
        $startedAt = (string) ($step['started_at'] ?? '');
        $startMs = RadarAutoTune::toMs($startedAt);
        $nowMs = RadarAutoTune::toMs($now);
        $elapsed = ($startMs !== null && $nowMs !== null)
            ? max(0, (int) round(($nowMs - $startMs) / 1000))
            : 0;
        if ($elapsed < RadarGuidedCalibration::SENSITIVITY_SECONDS) {
            $remaining = max(0, RadarGuidedCalibration::SENSITIVITY_SECONDS - $elapsed);
            $state['steps'][$stepId]['detail'] = [
                'message' => 'Midiendo sensibilidad… quedan ' . $remaining . ' s.',
                'countdown' => $remaining,
            ];
            $state['updated_at'] = $now;
            return $state;
        }
        $current = (int) ($ctx['settings']['radar_sensitivity'] ?? 0);
        $recommendation = RadarAutoTune::sensitivity(
            (array) ($ctx['distance_reports'] ?? []),
            (array) ($ctx['presence_reports'] ?? []),
            $current,
            RadarGuidedCalibration::SENSITIVITY_SECONDS,
            $now,
            (string) ($ctx['authentic_state'] ?? '')
        );
        $value = $recommendation['value'] ?? null;
        if ($value === null || (int) $value === $current) {
            $state = RadarGuidedCalibration::markPassed($state, $stepId, $now, [
                'message' => 'Sin cambios de sensibilidad.',
                'recommendation' => $recommendation,
                'decision' => 'keep',
            ]);
            if (($state['status'] ?? '') === RadarGuidedCalibration::STATUS_DONE) {
                $state = $this->finalizeWizard($roomId, $device, $state);
            }
            return $state;
        }
        return RadarGuidedCalibration::sensitivityAwaiting($state, $recommendation, $now);
    }

    /** @param array<string,mixed> $state */
    private function finalizeWizard(int $roomId, array $device, array $state): array
    {
        if (($state['calibration_persisted'] ?? false) === true) {
            return $state;
        }
        try {
            $status = $this->service()->status($roomId, $device);
            $settings = (array) ($status['settings'] ?? []);
            $summary = (array) ($state['summary'] ?? []);
            $saved = $this->service()->persistCalibration(
                $device,
                $settings,
                $status['settings_at'] ?? null,
                'radar-studio-guided-v1',
                ['wizard' => $summary]
            );
            $state['calibration_persisted'] = true;
            $state['calibration_error'] = null;
            $state['saved'] = $saved;
        } catch (Throwable $e) {
            $state['calibration_persisted'] = false;
            $state['calibration_error'] = $e->getMessage();
        }
        $state['updated_at'] = $this->nowIso();
        return $state;
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $device
     * @param array<string,mixed>|null $state
     */
    private function dispatchWizardAction(
        int $roomId,
        array $device,
        ?array $state,
        string $action,
        array $body
    ): Response {
        $now = $this->nowIso();
        $status = $this->service()->status($roomId, $device);
        $settings = (array) ($status['settings'] ?? []);
        $configKey = RadarDistanceReport::configKey($settings);

        if ($action === 'reset' || $action === 'cancel') {
            $this->store->clearWizard($roomId);
            return $this->wizardResponse($roomId, $device, null);
        }
        if ($action === 'status') {
            return $this->wizardResponse($roomId, $device, $state);
        }
        if ($action === 'profile_applied') {
            if (!ZigbeeRadarCalibrationService::isValidSettings($settings) || ($status['stale'] ?? false) === true) {
                return Response::json(409, [
                    'ok' => false,
                    'error' => 'profile_not_ready',
                    'message' => 'El radar no tiene una configuración reciente y válida. Aplica el perfil y espera la confirmación por push.',
                ]);
            }
            $state = RadarGuidedCalibration::markProfileApplied($state, $roomId, $settings, $configKey, $now);
            $this->store->saveWizard($roomId, $state);
            return $this->wizardResponse($roomId, $device, $state);
        }
        if ($state === null) {
            return Response::json(409, [
                'ok' => false,
                'error' => 'wizard_not_started',
                'message' => 'Primero aplica el perfil de ajuste manual para iniciar la calibración guiada.',
            ]);
        }

        $ctx = $this->wizardContext($roomId, $settings, $status);
        $current = (string) ($state['current_step'] ?? '');

        switch ($action) {
            case 'step_start':
                $stepId = (string) ($body['step_id'] ?? $current);
                if ($stepId !== $current || !isset($state['steps'][$stepId])) {
                    return Response::json(409, ['ok' => false, 'error' => 'step_locked', 'message' => 'Ese paso no está activo.']);
                }
                $state = RadarGuidedCalibration::startStep($state, $stepId, $now, $ctx);
                $this->store->saveWizard($roomId, $state);
                return $this->wizardResponse($roomId, $device, $state);

            case 'step_pass':
                if ($current !== RadarGuidedCalibration::WALK) {
                    return Response::json(409, ['ok' => false, 'error' => 'step_not_manual', 'message' => 'Solo el paseo se marca manualmente.']);
                }
                $walk = $state['steps'][RadarGuidedCalibration::WALK] ?? [];
                if (($walk['status'] ?? '') !== RadarGuidedCalibration::STEP_ACTIVE) {
                    return Response::json(409, ['ok' => false, 'error' => 'step_not_started', 'message' => 'Empieza el paseo antes de marcarlo como bueno.']);
                }
                $state = RadarGuidedCalibration::evaluateWalk($state, $now, $ctx);
                $state = RadarGuidedCalibration::markPassed($state, RadarGuidedCalibration::WALK, $now, [
                    'message' => 'Prueba buena confirmada por el operador.',
                ]);
                $this->store->saveWizard($roomId, $state);
                return $this->wizardResponse($roomId, $device, $state);

            case 'sensitivity_accept':
                if ($current !== RadarGuidedCalibration::SENSITIVITY) {
                    return Response::json(409, ['ok' => false, 'error' => 'step_not_sensitivity']);
                }
                $sensStep = $state['steps'][RadarGuidedCalibration::SENSITIVITY] ?? [];
                if (($sensStep['status'] ?? '') !== RadarGuidedCalibration::STEP_AWAITING) {
                    return Response::json(409, ['ok' => false, 'error' => 'not_awaiting_decision']);
                }
                $recommendation = is_array($sensStep['recommendation'] ?? null) ? $sensStep['recommendation'] : null;
                $value = $recommendation['value'] ?? null;
                if (!is_numeric($value) || (int) $value < 0 || (int) $value > 9) {
                    return Response::json(409, ['ok' => false, 'error' => 'no_recommendation']);
                }
                $apply = ZigbeeRadarCalibrationService::normalizeCommands($settings);
                if ($apply === []) {
                    return Response::json(409, ['ok' => false, 'error' => 'settings_unavailable']);
                }
                $apply['radar_sensitivity'] = (int) $value;
                $result = $this->service()->apply($roomId, $device, $apply, true, 15, 'radar-studio-guided-v1');
                if (($result['applied'] ?? false) !== true) {
                    return Response::json(409, [
                        'ok' => false,
                        'error' => 'not_confirmed',
                        'message' => (string) ($result['warning'] ?? 'El radar no confirmó la sensibilidad.'),
                        'result' => $result,
                    ]);
                }
                $state['settings']['radar_sensitivity'] = (int) $value;
                $state = RadarGuidedCalibration::sensitivityDecision($state, 'accept', $now);
                $this->store->saveWizard($roomId, $state);
                return $this->wizardResponse($roomId, $device, $state);

            case 'sensitivity_keep':
                if ($current !== RadarGuidedCalibration::SENSITIVITY) {
                    return Response::json(409, ['ok' => false, 'error' => 'step_not_sensitivity']);
                }
                $sensStep = $state['steps'][RadarGuidedCalibration::SENSITIVITY] ?? [];
                if (($sensStep['status'] ?? '') !== RadarGuidedCalibration::STEP_AWAITING) {
                    return Response::json(409, ['ok' => false, 'error' => 'not_awaiting_decision']);
                }
                $state = RadarGuidedCalibration::sensitivityDecision($state, 'keep', $now);
                $this->store->saveWizard($roomId, $state);
                return $this->wizardResponse($roomId, $device, $state);

            case 'retry':
                $state = RadarGuidedCalibration::retryStep($state, $now);
                $this->store->saveWizard($roomId, $state);
                return $this->wizardResponse($roomId, $device, $state);

            case 'finish':
                if (($state['status'] ?? '') !== RadarGuidedCalibration::STATUS_DONE) {
                    return Response::json(409, ['ok' => false, 'error' => 'wizard_not_done']);
                }
                $state = $this->finalizeWizard($roomId, $device, $state);
                $this->store->saveWizard($roomId, $state);
                return $this->wizardResponse($roomId, $device, $state);

            default:
                return Response::json(400, ['ok' => false, 'error' => 'unknown_action']);
        }
    }

    /**
     * F125.4: estado de calibración + diagnóstico del último reporte inválido.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed> $status
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function calibrationPayload(array $device, array $status, array $settings): array
    {
        $calibration = RadarCalibrationConvergence::statusFor(
            is_array($device['meta'] ?? null) ? $device['meta'] : [],
            $settings
        );
        $calibration['reported_invalid'] = (bool) ($status['reported_invalid'] ?? false);
        $calibration['reported_invalid_reason'] = $status['reported_invalid_reason'] ?? null;
        $calibration['latest_reported_settings'] = $status['latest_reported_settings'] ?? null;
        $calibration['reported_unstable'] = (bool) ($status['reported_unstable'] ?? false);
        $calibration['reported_variants'] = $status['reported_variants'] ?? [];
        return $calibration;
    }

    private function scopePayload(int $roomId, int $window = 60): array
    {
        $window = max(10, min(300, $window));
        $device = $this->deviceForRoom($roomId);
        if ($device === null) {
            throw new RuntimeException('presence_device_missing');
        }
        $status = $this->service()->status($roomId, $device);
        $settings = (array) ($status['settings'] ?? []);
        $valid = ZigbeeRadarCalibrationService::isValidSettings($settings);
        $now = $this->nowIso();
        $sinceSql = gmdate('Y-m-d H:i:s', time() - $window);
        $configKey = RadarDistanceReport::configKey($settings);
        $distances = $this->filterByConfig(
            (new RadarDistanceReportRepository($this->pdo()))->recentForRoom($roomId, $sinceSql, 1000),
            $configKey,
            false
        );
        $presence = $this->filterByConfig(
            (new RadarPresenceReportRepository($this->pdo()))->recentForRoom($roomId, $sinceSql, 500),
            $configKey,
            false
        );
        $ctx = new SensorContextBuilder($this->pdo());
        $live = $ctx->live($roomId);
        $room = $this->roomMeta($roomId);
        $discarded = $this->discardedUncorroborated($roomId, $sinceSql);
        $state = $this->store->load($roomId);
        $authentic = $this->authenticPresence($status);
        $scope = RadarScope::build(
            $settings,
            $distances,
            $presence,
            [
                'state' => $authentic['state'],
                'age_seconds' => $status['settings_age_seconds'] ?? null,
                'stale' => (bool) ($status['stale'] ?? false),
                'presence_report' => $status['presence_report'] ?? null,
            ],
            [
                'now' => $now,
                'window_seconds' => $window,
                'settings_valid' => $valid,
                'settings_invalid_reason' => ZigbeeRadarCalibrationService::settingsInvalidReason($settings),
                'stale_seconds' => $status['stale_seconds'] ?? RadarFreshness::staleSeconds(),
                'presence_mode' => $room['presence_mode'],
                'door_state' => $live['door_state'] ?? null,
                'discarded_uncorroborated' => $discarded,
                'active_operation' => $state,
            ]
        );
        // F130/F132: decisión operativa final y evaluación de alcance. La
        // sesión es la fuente autoritativa; el meta del evento queda como
        // compatibilidad con datos anteriores a la migración 0150.
        $decision = $ctx->presenceOutcome($roomId) ?? $this->latestRadarDecision($roomId);
        $rangeGate = is_array($decision['range_gate'] ?? null) ? $decision['range_gate'] : null;
        $scope['presence_decision'] = $decision;
        $scope['range_gate'] = $rangeGate;
        $scope['range_evaluation'] = $rangeGate;

        return [
            'room_id' => $roomId,
            'room' => $room,
            'device' => [
                'id' => (int) $device['id'],
                'external_id' => (string) $device['external_id'],
                'label' => (string) ($device['label'] ?? ''),
                'model' => (string) ($device['model'] ?? ''),
                'transport' => (string) ($device['transport'] ?? ''),
                'health' => (string) ($device['health'] ?? ''),
                'health_detail' => (string) ($device['health_detail'] ?? ''),
                'lqi' => $device['lqi'] ?? null,
                'battery_pct' => $device['battery_pct'] ?? null,
                'last_seen_at' => $device['last_seen_at'] ?? null,
            ],
            'saved' => $status['saved'] ?? null,
            'calibration' => $this->calibrationPayload($device, $status, $settings),
            'baseline' => ZigbeeRadarCalibrationService::BASELINE,
            'scope' => $scope,
        ];
    }

    /**
     * F130: última decisión radar persistida (aditiva, solo lectura).
     *
     * @return array<string,mixed>|null
     */
    private function latestRadarDecision(int $roomId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT meta_json
               FROM presence_events
              WHERE room_id = :rid AND provider = 'ZIGBEE2MQTT' AND sensor = 'PRESENCE'
              ORDER BY occurred_at DESC, id DESC
              LIMIT 1"
        );
        $stmt->execute([':rid' => $roomId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null) {
            return null;
        }
        $meta = json_decode((string) $raw, true);
        return is_array($meta) && is_array($meta['radar_decision'] ?? null)
            ? $meta['radar_decision'] : null;
    }

    /** @return array<string,mixed>|null */
    private function deviceForRoom(int $roomId): ?array
    {
        $devices = (new SensorContextBuilder($this->pdo()))->devices($roomId);
        return $devices['presence'] ?? null;
    }

    /** @return array{id:int,code:string,presence_mode:?string,presence_mode_source:string,room_type_code:string} */
    private function roomMeta(int $roomId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT r.id, r.code, r.presence_mode_override,
                    COALESCE(r.presence_mode_override, rt.presence_mode) AS presence_mode,
                    COALESCE(rt.code, \'\') AS room_type_code
               FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id
              WHERE r.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $override = $row['presence_mode_override'] ?? null;
        return [
            'id' => (int) ($row['id'] ?? $roomId),
            'code' => (string) ($row['code'] ?? ''),
            'presence_mode' => isset($row['presence_mode']) && $row['presence_mode'] !== null
                ? (string) $row['presence_mode'] : null,
            'presence_mode_source' => $override !== null && $override !== '' ? 'override' : 'room_type',
            'room_type_code' => (string) ($row['room_type_code'] ?? ''),
        ];
    }

    private function discardedUncorroborated(int $roomId, string $sinceSql): int
    {
        try {
            $stmt = $this->pdo()->prepare(
                "SELECT COUNT(*) FROM presence_events
                  WHERE room_id = :rid AND provider = 'ZIGBEE2MQTT' AND sensor = 'PRESENCE'
                    AND applied = 0 AND discard_reason = 'uncorroborated'
                    AND occurred_at >= :since"
            );
            $stmt->execute([':rid' => $roomId, ':since' => $sinceSql]);
            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function filterByConfig(array $rows, ?string $configKey, bool $strict): array
    {
        if ($configKey === null) {
            return $rows;
        }
        $out = [];
        foreach ($rows as $row) {
            $key = $row['config_key'] ?? null;
            if ($key === $configKey || (!$strict && $key === null)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Presencia auténtica: prefiere el marcador DP 1 (solo cambia con un
     * reporte real). Si el conversor no lo aporta, cae al valor del evento.
     *
     * @param array<string,mixed> $status
     * @return array{state:string,source:string,marker:string,age_seconds:?int}
     */
    private function authenticPresence(array $status): array
    {
        $report = is_array($status['presence_report'] ?? null) ? $status['presence_report'] : null;
        $state = strtoupper(trim((string) ($report['value'] ?? '')));
        $source = 'dp1';
        if (!in_array($state, ['PRESENT', 'ABSENT'], true)) {
            $state = strtoupper(trim((string) ($status['effective_presence'] ?? '')));
            $source = 'event';
        }
        if (!in_array($state, ['PRESENT', 'ABSENT'], true)) {
            $state = 'UNKNOWN';
            $source = 'none';
        }
        return [
            'state' => $state,
            'source' => $source,
            'marker' => (string) ($report['marker'] ?? ''),
            'age_seconds' => $status['settings_age_seconds'] ?? null,
        ];
    }

    private function service(): ZigbeeRadarCalibrationService
    {
        return new ZigbeeRadarCalibrationService($this->pdo(), $this->publisher);
    }

    private function pdo(): PDO
    {
        return ($this->pdoProvider)();
    }

    private function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private function sqlFromIso(string $iso): string
    {
        $ts = RadarAutoTune::toMs($iso);
        if ($ts === null) {
            return gmdate('Y-m-d H:i:s', time() - 60);
        }
        return gmdate('Y-m-d H:i:s', intdiv($ts, 1000));
    }

    /**
     * F125.3: distingue un reporte inválido del radar (valor imposible del
     * broker/converter) de una confirmación que simplemente no ha llegado.
     *
     * @param array<string,mixed> $result
     * @param array<string,mixed>|null $extra
     */
    private function notConfirmedResponse(array $result, string $fallback, ?array $extra = null): Response
    {
        $warning = (string) ($result['warning'] ?? $fallback);
        $invalidReason = isset($result['settings_invalid_reason'])
            ? (string) $result['settings_invalid_reason'] : null;
        $isInvalid = $invalidReason !== null || str_starts_with($warning, 'reported_settings_invalid');
        $payload = [
            'ok' => false,
            'error' => $isInvalid ? 'reported_settings_invalid' : 'not_confirmed',
            'message' => $isInvalid
                ? 'El radar está reportando un valor inválido (' . ($invalidReason ?? 'configuración fuera de rango')
                    . '). Revisa el broker/converter Zigbee2MQTT; la calibración no se ha modificado.'
                : $warning,
            'result' => $result,
        ];
        if ($extra !== null) {
            $payload = array_merge($payload, $extra);
        }
        return Response::json(409, $payload);
    }

    private function runtimeError(RuntimeException $e): Response
    {
        $code = $e->getMessage();
        $status = match ($code) {
            'presence_device_missing' => 404,
            default => 409,
        };
        $messages = [
            'presence_device_missing' => 'Esta sala no tiene un radar de presencia Zigbee.',
            'settings_invalid' => 'La configuración del radar no es válida; restaura la base antes de calibrar.',
            'settings_unavailable' => 'No hay configuración reciente del radar. Muévete delante del radar y reintenta.',
            'no_parameters' => 'No se ha indicado ningún parámetro.',
            'invalid_radar_sensitivity' => 'Sensibilidad fuera de rango.',
        ];
        return Response::json($status, [
            'ok' => false,
            'error' => $code,
            'message' => $messages[$code] ?? $code,
        ]);
    }
}
