<?php
declare(strict_types=1);

/**
 * API entry point.
 *
 * Composition root: wires config, logger, router, middlewares and handlers,
 * then dispatches the incoming request and emits the response.
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Domain\Auth\ApiClientRepository;
use App\Domain\Crm\CrmSessionRepository;
use App\Domain\Crm\CrmUserRepository;
use App\Domain\Crm\CrmUserService;
use App\Domain\Devices\ApplyPackService;
use App\Domain\Devices\DevicePackRepository;
use App\Domain\Devices\CameraReachabilityProbe;
use App\Domain\Devices\DeviceIntegrationStatusService;
use App\Domain\Devices\DeviceLivenessService;
use App\Domain\Devices\DeviceRepository;
use App\Domain\Devices\DeviceService;
use App\Domain\Devices\SwitchService;
use App\Domain\Debts\DebtRepository;
use App\Domain\Debts\DebtsService;
use App\Domain\Debts\OutboxVb6Repository;
use App\Domain\Debts\OverstayCalculator;
use App\Domain\Locks\AccessEventRepository;
use App\Domain\Locks\LockService;
use App\Domain\Presence\IotSessionRepository;
use App\Domain\Presence\IotSessionServiceFactory;
use App\Domain\Presence\PresenceEventRepository;
use App\Domain\Qr\QrCredentialRepository;
use App\Domain\Qr\QrIssueService;
use App\Domain\Qr\QrValidateService;
use App\Infrastructure\Gateways\Lock\LockGatewayFactory;
use App\Infrastructure\Gateways\Sensor\SensorIngressFactory;
use App\Infrastructure\Gateways\Sensor\SimulatedSensorIngress;
use App\Infrastructure\Gateways\Sensor\TuyaSensorIngress;
use App\Domain\Rooms\RoomRepository;
use App\Domain\Rooms\RoomService;
use App\Domain\Rooms\RoomStateService;
use App\Domain\Rooms\RoomTypeRepository;
use App\Domain\Rooms\RoomTypeService;
use App\Domain\Stays\StayRepository;
use App\Domain\Stays\StayService;
use App\Domain\Stays\StayStateMachine;
use App\Domain\Anomalies\Anomaly;
use App\Domain\Anomalies\AnomalyPipeline;
use App\Domain\Anomalies\AnomalyRepository;
use App\Domain\Anomalies\AnomalyService;
use App\Domain\Anomalies\Detectors\PresenceWithoutDoorOpen;
use App\Domain\Anomalies\Detectors\PresenceWithoutStay;
use App\Domain\Anomalies\Detectors\DoorOpenWithoutQr;
use App\Domain\Anomalies\Detectors\PresenceAfterExit;
use App\Domain\Anomalies\Detectors\SensorFlapping;
use App\Domain\Anomalies\Detectors\ExitWithoutDoorOpen;
use App\Domain\TimeSlots\TimeSlotRepository;
use App\Domain\TimeSlots\TimeSlotService;
use App\Domain\Workers\WorkerRepository;
use App\Domain\Workers\WorkerRoleRepository;
use App\Domain\Workers\WorkerRoleService;
use App\Domain\Workers\WorkerService;
use App\Domain\Workers\WorkerSessionRepository;
use App\Domain\Workers\WorkerRoomOverrideRepository;
use App\Domain\Workers\WarehouseAccessPolicy;
use App\Http\Request;
use App\Http\ResponseEmitter;
use App\Http\Router;
use App\Http\Middleware;
use App\Http\Middlewares\AuthApiKeyMiddleware;
use App\Http\Middlewares\AuthScopeMiddleware;
use App\Http\Middlewares\CrmSessionMiddleware;
use App\Http\Middlewares\ErrorHandlerMiddleware;
use App\Http\Middlewares\IdempotencyMiddleware;
use App\Http\Middlewares\RequestLogMiddleware;
use App\Http\Middlewares\PanelAccessMiddleware;
use App\Http\Middlewares\KeyDeskAccessMiddleware;
use App\Http\Controllers\AdminApiClientController;
use App\Http\Controllers\AccessKeyController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\KeyDeskController;
use App\Http\Controllers\DevEchoController;
use App\Http\Controllers\EventStreamController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\QrRejectionController;
use App\Http\Controllers\DevicePackController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\LockController;
use App\Http\Controllers\NfcCardController;
use App\Http\Controllers\OverstayController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\QrController;
use App\Http\Controllers\QrTestController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomTypeController;
use App\Http\Controllers\SimController;
use App\Http\Controllers\SwitchController;
use App\Http\Controllers\AnomalyController;
use App\Http\Controllers\TuyaWebhookController;
use App\Http\Controllers\StayController;
use App\Http\Controllers\TimeSlotController;
use App\Http\Controllers\WorkerRoleController;
use App\Http\Controllers\WorkerController;
use App\Http\Controllers\WorkerQrController;
use App\Http\Controllers\WarehouseAccessController;
use App\Http\Controllers\WarehouseVisitController;
use App\Http\Controllers\WarehouseRecordingController;
use App\Http\Controllers\WarehouseCameraController;
use App\Http\Controllers\WarehouseStateController;
use App\Http\Controllers\AlmacenEventStreamController;
use App\Http\Controllers\FactoryDeviceController;
use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Persistence\FactoryDeviceRepository;
use App\Support\Config;
use App\Support\Idempotency\IdempotencyStore;
use App\Support\Logger\Logger;
use App\Support\Qr\QrTokenizer;

Config::load(__DIR__ . '/../.env');

// Fase 2 — T2.4: validar variables criticas al arranque
Config::validate([
    'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER',
    'QR_SIGNING_SECRET',
]);
if (!Config::getBool('SIMULATED_MODE', true)) {
    Config::validate(['TUYA_ACCESS_ID', 'TUYA_ACCESS_SECRET', 'TUYA_BASE_URL']);
}
if (Config::get('LOCK_PROVIDER', 'LOCAL') === 'TUYA') {
    Config::validate(['TUYA_ACCESS_ID', 'TUYA_ACCESS_SECRET', 'TUYA_BASE_URL']);
}
date_default_timezone_set(Config::get('APP_TZ', 'Europe/Madrid') ?? 'UTC');

// Serve static files for PHP built-in server (router script must return false
// so that PHP serves .js, .css, .png etc. directly from the filesystem).
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = __DIR__ . $path;
    if (is_file($file)) {
        return false;
    }
}

// --- Infrastructure / logging ---
$logger = new Logger(
    Config::get('LOG_LEVEL', 'info') ?? 'info',
    self_resolveLogPath(__DIR__ . '/..', Config::get('LOG_PATH'))
);

$pdoProvider = static function (): \PDO {
    return PdoFactory::make();
};

// F91: provisioning/health status derived from persisted data only (no probes).
$deviceStatusFactory = static function (): DeviceIntegrationStatusService {
    static $service = null;
    if ($service === null) {
        $mapFile = Config::get('ZIGBEE_MAP_FILE');
        $spoolDir = Config::get('ZIGBEE_SPOOL_DIR');
        $stale = (int) (Config::get('ZIGBEE_PRESENCE_STALE_SECONDS', '300') ?? 300);
        $service = new DeviceIntegrationStatusService($mapFile, $spoolDir, $stale);
    }
    return $service;
};

// --- Router with global middlewares ---
// Order:
//   1. RequestLog: outermost, attaches correlation id and measures duration.
//   2. ErrorHandler: converts exceptions into JSON envelopes.
$router = new Router();
$router->use(new RequestLogMiddleware($logger));
$router->use(new ErrorHandlerMiddleware($logger));

// --- Public routes ---
$health = new HealthController();
$router->get('/api/v1/health', [$health, 'show']);
$router->get('/api/v1/health/deep', [$health, 'deep']);

// Public dashboard HTML (F21, no auth — LAN/MVP)
$router->get(
    '/dashboard',
    function (\App\Http\Request $request): \App\Http\Response {
        $htmlFile = __DIR__ . '/dashboard.html';
        if (!is_file($htmlFile)) {
            return \App\Http\Response::json(404, ['error' => 'dashboard HTML not found']);
        }
        return new \App\Http\Response(200, ['Content-Type' => 'text/html; charset=utf-8'], @file_get_contents($htmlFile) ?: '');
    }
);

// Public simula HTML (no auth — LAN/MVP, dev tool for presence sensor range toggle)
$router->get(
    '/simula',
    function (\App\Http\Request $request): \App\Http\Response {
        $htmlFile = __DIR__ . '/simula.html';
        if (!is_file($htmlFile)) {
            return \App\Http\Response::json(404, ['error' => 'simula HTML not found']);
        }
        return new \App\Http\Response(200, ['Content-Type' => 'text/html; charset=utf-8'], @file_get_contents($htmlFile) ?: '');
    }
);

// Public acceptance checklist HTML (no auth — LAN/MVP dev tool, F55/RF-62)
$router->get(
    '/pruebas',
    function (\App\Http\Request $request): \App\Http\Response {
        $htmlFile = __DIR__ . '/pruebas.html';
        if (!is_file($htmlFile)) {
            return \App\Http\Response::json(404, ['error' => 'pruebas HTML not found']);
        }
        return new \App\Http\Response(200, ['Content-Type' => 'text/html; charset=utf-8'], @file_get_contents($htmlFile) ?: '');
    }
);

// ── Sensor calibration wizard (F94) ──────────────────────────────────────────
// Herramienta de campo: página /sensores + validación server-side de cada paso.
// Solo lectura de BD/MQTT bajo demanda; sin pollers ni llamadas a Tuya.
$router->get(
    '/sensores',
    function (\App\Http\Request $request): \App\Http\Response {
        $htmlFile = __DIR__ . '/sensores.html';
        if (!is_file($htmlFile)) {
            return \App\Http\Response::json(404, ['error' => 'sensores HTML not found']);
        }
        return new \App\Http\Response(200, ['Content-Type' => 'text/html; charset=utf-8'], @file_get_contents($htmlFile) ?: '');
    }
);

$sensorRunsDir = dirname(__DIR__) . '/run/sensores';
$sensorStore = new \App\Infrastructure\Sensors\SensorRunStore($sensorRunsDir);
$sensorCatalog = static function (): array {
    static $catalog = null;
    if ($catalog === null) {
        $raw = @file_get_contents(__DIR__ . '/assets/sensor-tests.json');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $catalog = is_array($decoded) ? $decoded : ['tests' => [], 'blocks' => []];
    }
    return $catalog;
};
$sensorStep = static function (string $stepId) use ($sensorCatalog): ?array {
    foreach ((array) ($sensorCatalog()['tests'] ?? []) as $t) {
        if (is_array($t) && (string) ($t['id'] ?? '') === $stepId) {
            return $t;
        }
    }
    return null;
};
$sensorSummary = static function (array $record) use ($sensorCatalog): array {
    $steps = (array) ($record['steps'] ?? []);
    $pass = $fail = $skip = $pending = 0;
    foreach ((array) ($sensorCatalog()['tests'] ?? []) as $t) {
        $id = (string) ($t['id'] ?? '');
        $status = (string) ($steps[$id]['status'] ?? 'PENDING');
        if ($status === 'PASS') $pass++;
        elseif ($status === 'FAIL') $fail++;
        elseif ($status === 'SKIP') $skip++;
        else $pending++;
    }
    $total = $pass + $fail + $skip + $pending;
    return [
        'pass' => $pass, 'fail' => $fail, 'skip' => $skip, 'pending' => $pending,
        'total' => $total, 'green' => $total > 0 && $pending === 0 && $fail === 0,
    ];
};

$router->get(
    '/dashboard-api/sensores/context',
    function (\App\Http\Request $request) use ($pdoProvider): \App\Http\Response {
        $pdo = $pdoProvider();
        $sensorContext = new \App\Infrastructure\Sensors\SensorContextBuilder($pdo);
        $roomId = (int) ($request->query['room_id'] ?? 0);
        if ($roomId <= 0) {
            return \App\Http\Response::json(400, ['error' => 'room_id required']);
        }
        $rooms = $pdo->prepare(
            'SELECT r.id, r.code, r.room_type_id, r.presence_mode_override,
                    rt.code AS room_type_code, rt.presence_mode
               FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id
              WHERE r.id = :id LIMIT 1'
        );
        $rooms->execute([':id' => $roomId]);
        $room = $rooms->fetch(\PDO::FETCH_ASSOC);
        if ($room === false) {
            return \App\Http\Response::json(404, ['error' => 'room_not_found']);
        }
        $devices = $sensorContext->devices($roomId);
        $latest = $sensorContext->latestSettings($roomId);
        return \App\Http\Response::json(200, [
            'room' => [
                'id' => (int) $room['id'],
                'code' => (string) $room['code'],
                'room_type_id' => (int) $room['room_type_id'],
                'room_type_code' => (string) ($room['room_type_code'] ?? ''),
                'presence_mode' => (string) ($room['presence_mode_override'] ?? $room['presence_mode'] ?? ''),
            ],
            'transport' => $devices['transport'],
            'devices' => $devices,
            'live' => $sensorContext->live($roomId),
            'settings' => $latest['settings'],
            'settings_at' => $latest['settings_at'],
            'settings_age_seconds' => $latest['settings_age_seconds'],
            'effects' => $sensorContext->effects($roomId),
            'events' => $sensorContext->latestEvents($roomId, 20),
        ])->withHeader('Cache-Control', 'no-store');
    }
);

$router->get(
    '/dashboard-api/sensores/events',
    function (\App\Http\Request $request) use ($pdoProvider): \App\Http\Response {
        $sensorContext = new \App\Infrastructure\Sensors\SensorContextBuilder($pdoProvider());
        $roomId = (int) ($request->query['room_id'] ?? 0);
        if ($roomId <= 0) {
            return \App\Http\Response::json(400, ['error' => 'room_id required']);
        }
        $limit = (int) ($request->query['limit'] ?? 25);
        return \App\Http\Response::json(200, [
            'room_id' => $roomId,
            'events' => $sensorContext->latestEvents($roomId, $limit),
            'live' => $sensorContext->live($roomId),
            'settings' => $sensorContext->latestSettings($roomId),
            'server_ts' => gmdate('Y-m-d\TH:i:s\Z'),
        ])->withHeader('Cache-Control', 'no-store');
    }
);

$router->post(
    '/dashboard-api/sensores/run/start',
    function (\App\Http\Request $request) use ($sensorStore, $pdoProvider, $sensorCatalog): \App\Http\Response {
        $pdo = $pdoProvider();
        $sensorContext = new \App\Infrastructure\Sensors\SensorContextBuilder($pdo);
        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        $roomId = (int) ($body['room_id'] ?? 0);
        if ($roomId <= 0) {
            return \App\Http\Response::json(400, ['error' => 'room_id required']);
        }
        try {
            $devices = $sensorContext->devices($roomId);
            $latest = $sensorContext->latestSettings($roomId);
            $infoStmt = $pdo->prepare(
                'SELECT r.code, rt.code AS room_type_code, r.presence_mode_override, rt.presence_mode
                   FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id
                  WHERE r.id = :id LIMIT 1'
            );
            $infoStmt->execute([':id' => $roomId]);
            $info = $infoStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $record = $sensorStore->create([
                'room_id' => $roomId,
                'room' => [
                    'code' => (string) ($info['code'] ?? ''),
                    'room_type_code' => (string) ($info['room_type_code'] ?? ''),
                    'presence_mode' => (string) ($info['presence_mode_override'] ?? $info['presence_mode'] ?? ''),
                ],
                'operator' => (string) ($body['operator'] ?? ''),
                'catalog_version' => (int) ($sensorCatalog()['version'] ?? 1),
                'initial_settings' => $latest['settings'],
                'initial_settings_at' => $latest['settings_at'],
                'devices' => [
                    'presence' => $devices['presence'],
                    'proximity' => $devices['proximity'],
                    'transport' => $devices['transport'],
                ],
            ]);
            return \App\Http\Response::json(201, ['ok' => true, 'run' => $record]);
        } catch (\Throwable $e) {
            return \App\Http\Response::json(500, ['error' => $e->getMessage()]);
        }
    }
);

$router->post(
    '/dashboard-api/sensores/step/start',
    function (\App\Http\Request $request) use ($sensorStore, $pdoProvider, $sensorStep, $sensorSummary): \App\Http\Response {
        $sensorContext = new \App\Infrastructure\Sensors\SensorContextBuilder($pdoProvider());
        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        $runId = (string) ($body['run_id'] ?? '');
        $stepId = (string) ($body['step_id'] ?? '');
        $run = $sensorStore->load($runId);
        if ($run === null) {
            return \App\Http\Response::json(404, ['error' => 'run_not_found']);
        }
        if ($sensorStep($stepId) === null) {
            return \App\Http\Response::json(404, ['error' => 'step_not_found']);
        }
        $existing = (array) ($run['steps'][$stepId] ?? []);
        $done = in_array((string) ($existing['status'] ?? ''), ['PASS', 'FAIL', 'SKIP'], true);
        if ($done && empty($body['reset'])) {
            // No se reinicia un paso ya validado salvo petición explícita.
            return \App\Http\Response::json(200, ['ok' => true, 'step' => $existing]);
        }
        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        $run['steps'][$stepId] = array_merge($existing, [
            'status' => 'PENDING',
            'started_at' => $now,
            'checked_at' => null,
            'verdict' => null,
            'override' => null,
            'evidence' => null,
            'notes' => (string) ($existing['notes'] ?? ''),
            'initial_effects' => $sensorContext->effects((int) $run['room_id']),
        ]);
        $run['summary'] = $sensorSummary($run);
        $sensorStore->save($run);
        $sensorStore->appendLog($runId, ['type' => 'step_start', 'step_id' => $stepId, 'at' => $now]);
        return \App\Http\Response::json(200, ['ok' => true, 'step' => $run['steps'][$stepId]]);
    }
);

$router->post(
    '/dashboard-api/sensores/step/check',
    function (\App\Http\Request $request) use ($sensorStore, $pdoProvider, $sensorStep, $sensorSummary): \App\Http\Response {
        $sensorContext = new \App\Infrastructure\Sensors\SensorContextBuilder($pdoProvider());
        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        $runId = (string) ($body['run_id'] ?? '');
        $stepId = (string) ($body['step_id'] ?? '');
        $override = strtoupper((string) ($body['override'] ?? ''));
        $notes = trim((string) ($body['notes'] ?? ''));
        $run = $sensorStore->load($runId);
        if ($run === null) {
            return \App\Http\Response::json(404, ['error' => 'run_not_found']);
        }
        $step = $sensorStep($stepId);
        if ($step === null) {
            return \App\Http\Response::json(404, ['error' => 'step_not_found']);
        }
        $state = (array) ($run['steps'][$stepId] ?? []);
        $startedAt = (string) ($state['started_at'] ?? gmdate('Y-m-d\TH:i:s.v\Z'));
        $initialEffects = (array) ($state['initial_effects'] ?? $sensorContext->effects((int) $run['room_id']));
        $initialSettings = (array) ($run['calibration']['initial_settings'] ?? []);

        $ctx = $sensorContext->context(
            (int) $run['room_id'],
            $startedAt,
            $initialEffects,
            $initialSettings
        );
        $verdict = \App\Domain\Sensors\SensorCalibrationValidator::validate(
            (string) ($step['rule'] ?? 'manual'),
            (array) ($step['params'] ?? []),
            $ctx
        );
        if (in_array($override, ['PASS', 'FAIL', 'SKIP'], true)) {
            $verdict = [
                'status' => $override,
                'reason' => 'manual_' . strtolower($override),
                'details' => ['auto' => $verdict],
            ];
        }

        $evidence = [
            'elapsed_seconds' => $ctx['elapsed_seconds'],
            'live' => $ctx['live'],
            'settings' => $ctx['settings'],
            'settings_age_seconds' => $ctx['settings_age_seconds'],
            'effects_delta' => $ctx['effects_delta'],
            'event_counts' => [
                'total' => count($ctx['events']),
                'applied' => count(array_filter($ctx['events'], static fn ($e) => ($e['applied'] ?? false) === true)),
                'presence_applied' => count(array_filter($ctx['events'], static fn ($e) => ($e['applied'] ?? false) === true && $e['sensor'] === 'PRESENCE')),
                'door_applied' => count(array_filter($ctx['events'], static fn ($e) => ($e['applied'] ?? false) === true && $e['sensor'] === 'PROXIMITY')),
            ],
        ];
        $run['steps'][$stepId] = array_merge($state, [
            'status' => $verdict['status'],
            'rule' => (string) ($step['rule'] ?? 'manual'),
            'params' => (array) ($step['params'] ?? []),
            'title' => (string) ($step['title'] ?? $stepId),
            'block' => (string) ($step['block'] ?? ''),
            'started_at' => $startedAt,
            'checked_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'elapsed_seconds' => $ctx['elapsed_seconds'],
            'verdict' => $verdict,
            'override' => in_array($override, ['PASS', 'FAIL', 'SKIP'], true) ? $override : null,
            'notes' => $notes,
            'evidence' => $evidence,
        ]);
        $run['summary'] = $sensorSummary($run);
        $sensorStore->save($run);
        // Log completo de la ventana para analizar al final (acotado).
        $events = array_slice($ctx['events'], 0, 2000);
        $sensorStore->appendLog($runId, [
            'type' => 'step_check',
            'step_id' => $stepId,
            'status' => $verdict['status'],
            'reason' => $verdict['reason'],
            'elapsed_seconds' => $ctx['elapsed_seconds'],
            'verdict' => $verdict,
            'effects_delta' => $ctx['effects_delta'],
            'settings' => $ctx['settings'],
            'events' => $events,
        ]);

        return \App\Http\Response::json(200, [
            'ok' => true,
            'step_id' => $stepId,
            'status' => $verdict['status'],
            'verdict' => $verdict,
            'evidence' => $evidence,
            'summary' => $run['summary'],
            'log_file' => basename($sensorStore->logPath($runId)),
        ]);
    }
);

$router->post(
    '/dashboard-api/sensores/run/finish',
    function (\App\Http\Request $request) use ($sensorStore, $sensorSummary): \App\Http\Response {
        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        $run = $sensorStore->load((string) ($body['run_id'] ?? ''));
        if ($run === null) {
            return \App\Http\Response::json(404, ['error' => 'run_not_found']);
        }
        $run['status'] = 'finished';
        $run['finished_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $run['summary'] = $sensorSummary($run);
        $sensorStore->save($run);
        $sensorStore->appendLog($run['run_id'], ['type' => 'run_finish', 'summary' => $run['summary']]);
        return \App\Http\Response::json(200, ['ok' => true, 'run' => $run]);
    }
);

$router->get(
    '/dashboard-api/sensores/runs',
    function (\App\Http\Request $request) use ($sensorStore): \App\Http\Response {
        return \App\Http\Response::json(200, $sensorStore->listRuns(50));
    }
);

$router->get(
    '/dashboard-api/sensores/get',
    function (\App\Http\Request $request) use ($sensorStore): \App\Http\Response {
        $run = $sensorStore->load((string) ($request->query['id'] ?? ''));
        if ($run === null) {
            return \App\Http\Response::json(404, ['error' => 'run_not_found']);
        }
        return \App\Http\Response::json(200, $run);
    }
);

$router->get(
    '/dashboard-api/sensores/export',
    function (\App\Http\Request $request) use ($sensorStore, $sensorCatalog, $sensorSummary): \App\Http\Response {
        $run = $sensorStore->load((string) ($request->query['id'] ?? ''));
        if ($run === null) {
            return \App\Http\Response::json(404, ['error' => 'run_not_found']);
        }
        $run['summary'] = $sensorSummary($run);
        if (($request->query['format'] ?? 'json') === 'json') {
            return new \App\Http\Response(200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="sensores-' . $run['run_id'] . '.json"',
            ], json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
        }

        $lines = [];
        $lines[] = '# Verificación de sensores — ' . $run['run_id'];
        $lines[] = '';
        $lines[] = '- Sala: ' . $run['room_id'] . ' · Operador: ' . ($run['operator'] ?: '—');
        $lines[] = '- Inicio: ' . $run['created_at'] . ' · Fin: ' . ($run['finished_at'] ?? '—');
        $s = $sensorSummary($run);
        $lines[] = '- Resultado: ' . ($s['green'] ? 'VERDE' : 'REVISAR') . " · PASS {$s['pass']} · FAIL {$s['fail']} · SKIP {$s['skip']} · PEND {$s['pending']}";
        $lines[] = '';
        foreach ((array) ($sensorCatalog()['tests'] ?? []) as $t) {
            $id = (string) ($t['id'] ?? '');
            $st = (string) ($run['steps'][$id]['status'] ?? 'PENDING');
            $lines[] = '## ' . $id . ' — ' . ($t['title'] ?? '') . ' [' . $st . ']';
            $lines[] = '';
            $lines[] = 'Acción: ' . ($t['action'] ?? '');
            $lines[] = '';
            $lines[] = 'Esperado: ' . ($t['expected'] ?? '');
            $step = $run['steps'][$id] ?? null;
            if (is_array($step)) {
                $v = (array) ($step['verdict'] ?? []);
                $lines[] = '';
                $lines[] = 'Resultado: ' . ($v['status'] ?? '—') . ' · ' . ($v['reason'] ?? '—');
                $lines[] = 'Detalle: `' . json_encode($v['details'] ?? [], JSON_UNESCAPED_UNICODE) . '`';
                if (!empty($step['notes'])) {
                    $lines[] = 'Nota: ' . $step['notes'];
                }
            }
            $lines[] = '';
        }
        $lines[] = '## Configuración inicial';
        $lines[] = '```json';
        $lines[] = json_encode($run['calibration']['initial_settings'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
        $lines[] = '```';
        return new \App\Http\Response(200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="sensores-' . $run['run_id'] . '.md"',
        ], implode("\n", $lines));
    }
);

// ── Sensor wizard v2 (F95): flujo universal Zigbee ───────────────────────────
$sensorWizardV2 = new \App\Http\Controllers\SensorWizardController(
    $pdoProvider,
    dirname(__DIR__) . '/run/sensores',
    __DIR__ . '/assets/sensor-tests-v2.json'
);
$router->get('/dashboard-api/sensores/v2/rooms', [$sensorWizardV2, 'rooms']);
$router->get('/dashboard-api/sensores/v2/preflight', [$sensorWizardV2, 'preflight']);
$router->get('/dashboard-api/sensores/v2/attempt/events', [$sensorWizardV2, 'attemptEvents']);
$router->post('/dashboard-api/sensores/v2/run/start', [$sensorWizardV2, 'startRun']);
$router->post('/dashboard-api/sensores/v2/attempt/start', [$sensorWizardV2, 'startAttempt']);
$router->post('/dashboard-api/sensores/v2/attempt/check', [$sensorWizardV2, 'checkAttempt']);
$router->post('/dashboard-api/sensores/v2/attempt/cancel', [$sensorWizardV2, 'cancelAttempt']);
$router->post('/dashboard-api/sensores/v2/door/polarity', [$sensorWizardV2, 'polarity']);
$router->post('/dashboard-api/sensores/v2/radar/tune', [$sensorWizardV2, 'tune']);
$router->post('/dashboard-api/sensores/v2/radar/calibrate', [$sensorWizardV2, 'radarCalibrate']);
$router->post('/dashboard-api/sensores/v2/switch/test', [$sensorWizardV2, 'switchTest']);
$router->post('/dashboard-api/sensores/v2/run/finish', [$sensorWizardV2, 'finish']);
$router->post('/dashboard-api/sensores/v2/run/soak', [$sensorWizardV2, 'soak']);
$router->get('/dashboard-api/sensores/v2/runs', [$sensorWizardV2, 'runs']);
$router->get('/dashboard-api/sensores/v2/get', [$sensorWizardV2, 'getRun']);
$router->get('/dashboard-api/sensores/v2/export', [$sensorWizardV2, 'export']);
$router->get('/dashboard-api/sensores/v2/events', [$sensorWizardV2, 'events']);

// ── Acceptance runs store (no auth — LAN/MVP dev tool, F55/RF-62) ─────────────
// Guarda cada corrida de aceptación manual en api/run/acceptance/<run-id>.json.
// run_id saneado por whitelist para impedir path traversal; escritura atómica.
$acceptanceDir    = dirname(__DIR__) . '/run/acceptance';
$acceptanceRunIdOk = static function (string $id): bool {
    return (bool) preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id);
};
$acceptanceSummaryOf = static function (array $tests, array $config): array {
    $naGreen = !isset($config['naCountsAsGreen']) || (bool) $config['naCountsAsGreen'];
    $pass = 0; $fail = 0; $na = 0; $pending = 0;
    foreach ($tests as $t) {
        $st = is_array($t) ? ($t['status'] ?? 'PENDING') : 'PENDING';
        if ($st === 'PASS') $pass++;
        elseif ($st === 'FAIL') $fail++;
        elseif ($st === 'NA') $na++;
        else $pending++;
    }
    $total = count($tests);
    $green = $total > 0 && $pending === 0 && $fail === 0 && ($naGreen || $na === 0);
    return ['pass' => $pass, 'fail' => $fail, 'na' => $na, 'pending' => $pending, 'total' => $total, 'green' => $green];
};

$router->post(
    '/dashboard-api/acceptance/save',
    function (\App\Http\Request $request) use ($acceptanceDir, $acceptanceRunIdOk, $acceptanceSummaryOf): \App\Http\Response {
        $body  = is_array($request->jsonBody) ? $request->jsonBody : [];
        $runId = (string) ($body['run_id'] ?? '');
        if (!$acceptanceRunIdOk($runId)) {
            return \App\Http\Response::json(400, ['error' => 'run_id inválido']);
        }
        if (!isset($body['tests']) || !is_array($body['tests'])) {
            return \App\Http\Response::json(400, ['error' => 'tests requerido']);
        }
        $config = is_array($body['config'] ?? null) ? $body['config'] : [];
        $record = [
            'run_id'     => $runId,
            'operator'   => (string) ($body['operator'] ?? ''),
            'room_id'    => (int) ($body['room_id'] ?? 0),
            'commit'     => (string) ($body['commit'] ?? ''),
            'started_at' => (string) ($body['started_at'] ?? ''),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'revision'   => (int) ($body['revision'] ?? 0),
            'config'     => $config,
            'tests'      => $body['tests'],
            'summary'    => $acceptanceSummaryOf($body['tests'], $config),
        ];
        if (!is_dir($acceptanceDir) && !@mkdir($acceptanceDir, 0775, true) && !is_dir($acceptanceDir)) {
            return \App\Http\Response::json(500, ['error' => 'no se pudo crear el directorio de corridas']);
        }
        $path = $acceptanceDir . '/' . $runId . '.json';
        $tmp  = $path . '.tmp';
        $ok = @file_put_contents(
            $tmp,
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        if ($ok === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return \App\Http\Response::json(500, ['error' => 'no se pudo guardar la corrida']);
        }
        return \App\Http\Response::json(200, ['ok' => true, 'run_id' => $runId, 'saved_at' => $record['updated_at']]);
    }
);

$router->get(
    '/dashboard-api/acceptance/list',
    function () use ($acceptanceDir): \App\Http\Response {
        $out = [];
        if (is_dir($acceptanceDir)) {
            foreach (glob($acceptanceDir . '/*.json') ?: [] as $f) {
                $j = json_decode((string) @file_get_contents($f), true);
                if (!is_array($j) || !isset($j['run_id'])) continue;
                $s = is_array($j['summary'] ?? null) ? $j['summary'] : [];
                $out[] = [
                    'run_id'     => $j['run_id'],
                    'operator'   => $j['operator'] ?? '',
                    'room_id'    => $j['room_id'] ?? null,
                    'commit'     => $j['commit'] ?? '',
                    'started_at' => $j['started_at'] ?? '',
                    'updated_at' => $j['updated_at'] ?? '',
                    'pass'       => $s['pass'] ?? 0,
                    'fail'       => $s['fail'] ?? 0,
                    'na'         => $s['na'] ?? 0,
                    'pending'    => $s['pending'] ?? 0,
                    'total'      => $s['total'] ?? 0,
                    'green'      => (bool) ($s['green'] ?? false),
                ];
            }
        }
        usort($out, static function ($a, $b) {
            return strcmp((string) $b['updated_at'], (string) $a['updated_at']);
        });
        return \App\Http\Response::json(200, $out);
    }
);

$router->get(
    '/dashboard-api/acceptance/get',
    function (\App\Http\Request $request) use ($acceptanceDir, $acceptanceRunIdOk): \App\Http\Response {
        $id = (string) ($request->query('id') ?? '');
        if (!$acceptanceRunIdOk($id)) {
            return \App\Http\Response::json(400, ['error' => 'id inválido']);
        }
        $path = $acceptanceDir . '/' . $id . '.json';
        if (!is_file($path)) {
            return \App\Http\Response::json(404, ['error' => 'corrida no encontrada']);
        }
        $j = json_decode((string) @file_get_contents($path), true);
        if (!is_array($j)) {
            return \App\Http\Response::json(500, ['error' => 'corrida corrupta']);
        }
        return \App\Http\Response::json(200, $j);
    }
);

$router->delete(
    '/dashboard-api/acceptance/delete',
    function (\App\Http\Request $request) use ($acceptanceDir, $acceptanceRunIdOk): \App\Http\Response {
        $id = (string) ($request->query('id') ?? '');
        if (!$acceptanceRunIdOk($id)) {
            return \App\Http\Response::json(400, ['error' => 'id inválido']);
        }
        $path = $acceptanceDir . '/' . $id . '.json';
        if (!is_file($path)) {
            return \App\Http\Response::json(404, ['error' => 'corrida no encontrada']);
        }
        return @unlink($path)
            ? \App\Http\Response::json(200, ['ok' => true, 'deleted' => $id])
            : \App\Http\Response::json(500, ['error' => 'no se pudo borrar']);
    }
);

// --- Dashboard internal API (no auth — LAN/MVP) ---

/**
 * EMERGENCY FALLBACK: Check Tuya PRESENCE device online status via Tuya Cloud API.
 *
 * IMPORTANT – DO NOT call this from automatic dashboard polling.
 * Device liveness is determined by last_seen_at (updated by Pulsar webhooks
 * and device heartbeat). This function exists ONLY for
 * manual debugging or emergency use.
 *
 * Changes from the original (which burned Tuya trial quota on 2 accounts):
 *   - Only checks PRESENCE (the others use Pulsar push or are command-only)
 *   - Stale threshold: 30 min (was 5 min)
 *   - Cache TTL: 5 min + FILE-BASED (shared across PHP built-in server workers)
 *   - Detects quota exhaustion (code 28841004) and backs off for 30 min (F46+)
 *
 * @param \PDO $pdo Database connection
 * @return array [external_id => bool] or [] if no stale devices / quota exhausted
 */
function checkTuyaOnline(\PDO $pdo): array {
    // ── File-based cache (shared across PHP built-in server workers) ──
    $cacheFile = sys_get_temp_dir() . '/tuya_online_cache.json';
    $cacheTTL  = 300; // 5 minutes

    $cached = null;
    if (file_exists($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw !== false) {
            $cached = json_decode($raw, true);
            if (is_array($cached) && isset($cached['_ts']) && (time() - $cached['_ts']) < $cacheTTL) {
                unset($cached['_ts']);
                return $cached;
            }
        }
    }

    // ── Quota-exhaustion backoff ──
    $backoffFile = sys_get_temp_dir() . '/tuya_quota_backoff.txt';
    if (file_exists($backoffFile)) {
        $backoffUntil = (int) @file_get_contents($backoffFile);
        if (time() < $backoffUntil) {
            return []; // Still in backoff — don't waste calls
        }
        @unlink($backoffFile); // Backoff expired, try again
    }

    // ── Credentials ──
    $accessId  = $_ENV['TUYA_ACCESS_ID'] ?? getenv('TUYA_ACCESS_ID') ?: '';
    $accessKey = $_ENV['TUYA_ACCESS_SECRET'] ?? getenv('TUYA_ACCESS_SECRET') ?: '';
    $baseUrl   = $_ENV['TUYA_BASE_URL'] ?? getenv('TUYA_BASE_URL') ?: 'https://openapi.tuyaeu.com';
    if (empty($accessId) || empty($accessKey)) {
        return [];
    }

    // ── Only PRESENCE devices that are VERY stale (30+ minutes) ──
    // PROXIMITY → Pulsar push (real-time, no API needed)
    // LOCK       → command-only (no status polling needed)
    // SWITCH     → command-only (no status polling needed)
    $stale = $pdo->query(
        "SELECT external_id FROM devices
         WHERE external_id LIKE 'bf%'
           AND kind = 'PRESENCE'
           AND (last_seen_at IS NULL OR last_seen_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 30 MINUTE))"
    )->fetchAll(\PDO::FETCH_COLUMN);

    if (empty($stale)) {
        file_put_contents($cacheFile, json_encode(['_ts' => time()]));
        return [];
    }

    // ── Get access token ──
    $ts   = (string)(int)(microtime(true) * 1000);
    $sign = strtoupper(hash_hmac('sha256',
        $accessId . $ts . "GET\n" . hash('sha256', '') . "\n\n/v1.0/token?grant_type=1",
        $accessKey));

    $ch = curl_init($baseUrl . '/v1.0/token?grant_type=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            "client_id: $accessId", "sign: $sign",
            "sign_method: HMAC-SHA256", "t: $ts",
        ],
    ]);
    $tokenResp = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!($tokenResp['success'] ?? false)) {
        $code = $tokenResp['code'] ?? 0;
        if ($code === 28841004 || stripos($tokenResp['msg'] ?? '', 'quota') !== false) {
            file_put_contents($backoffFile, time() + 1800); // 30 min (F46+: recuperar antes)
        }
        return [];
    }
    $token = $tokenResp['result']['access_token'];

    // ── Check each stale PRESENCE device ──
    $result    = [];
    $apiFailed = false;
    $quotaExhausted = false;

    foreach ($stale as $devId) {
        $ts   = (string)(int)(microtime(true) * 1000);
        $sign = strtoupper(hash_hmac('sha256',
            $accessId . $token . $ts . "GET\n" . hash('sha256', '') . "\n\n/v1.0/iot-03/devices/$devId",
            $accessKey));

        $ch = curl_init($baseUrl . "/v1.0/iot-03/devices/$devId");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                "client_id: $accessId", "sign: $sign",
                "sign_method: HMAC-SHA256", "t: $ts",
                "access_token: $token",
            ],
        ]);
        $rawResp  = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $resp = json_decode($rawResp, true);

        // Detect quota exhaustion from device response
        $code = $resp['code'] ?? 0;
        if ($code === 28841004 || stripos($resp['msg'] ?? '', 'quota') !== false) {
            $quotaExhausted = true;
            $apiFailed = true;
            continue;
        }

        if ($httpCode !== 200 || $resp === null || !isset($resp['result']['online'])) {
            $apiFailed = true;
            $result[$devId] = $cached[$devId] ?? false;
        } else {
            $result[$devId] = (bool) ($resp['result']['online']);
        }
    }

    if ($quotaExhausted) {
        file_put_contents($backoffFile, time() + 1800); // 30 min (F46+: recuperar antes) backoff
    }

    // Cache result (5 min), unless all calls failed
    if (!$apiFailed) {
        $result['_ts'] = time();
        file_put_contents($cacheFile, json_encode($result));
        unset($result['_ts']);
    }

    return $result;
}

// ── F46+: presupuesto de cuota Tuya (F78: ya NO hay poller) ─────────────────
// Fichero `api/run/tuya-quota.json`, escrito por la API y por el consumer
// Pulsar. Fuente única de verdad para las sondas REST (bajo demanda) y las
// llamadas del consumer. El 2026-09-17 una puerta atascada agotó la cuota con
// ~1.400 llamadas/hora; hoy no existe ningún bucle de sondeo continuo (F78).
function tuyaQuotaFile(): string
{
    return dirname(__DIR__) . '/run/tuya-quota.json';
}

/** @return array{day:?string,hour:?string,callsDay:int,callsHour:int,backoffUntil:int} */
function tuyaQuotaRead(): array
{
    $default = ['day' => null, 'hour' => null, 'callsDay' => 0, 'callsHour' => 0, 'backoffUntil' => 0];
    $f = tuyaQuotaFile();
    if (!is_file($f)) {
        return $default;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d)) {
        return $default;
    }
    $q = array_merge($default, $d);
    // F46++: normalizar `backoffUntil` a SEGUNDOS (valores legados en ms → /1000).
    if (($q['backoffUntil'] ?? 0) > 1e12) {
        $q['backoffUntil'] = (int) round($q['backoffUntil'] / 1000);
    }
    return $q;
}

function tuyaQuotaWrite(array $q): void
{
    $f = tuyaQuotaFile();
    if (!is_dir(dirname($f))) {
        @mkdir(dirname($f), 0775, true);
    }
    @file_put_contents($f . '.tmp', json_encode($q), LOCK_EX);
    @rename($f . '.tmp', $f);
}

/** @return array{ok:bool,reason:string,q:array<string,mixed>} */
function tuyaQuotaCheck(): array
{
    $now  = time();
    $day  = gmdate('Y-m-d', $now);
    $hour = gmdate('Y-m-d\TH', $now);
    $q = tuyaQuotaRead();
    if (($q['day'] ?? null) !== $day) {
        $q = ['day' => $day, 'hour' => $hour, 'callsDay' => 0, 'callsHour' => 0, 'backoffUntil' => 0];
    }
    if (($q['hour'] ?? null) !== $hour) {
        $q['hour'] = $hour;
        $q['callsHour'] = 0;
    }
    $hourly = (int) ($_ENV['TUYA_HOURLY_BUDGET'] ?? (getenv('TUYA_HOURLY_BUDGET') ?: 150));
    $daily  = (int) ($_ENV['TUYA_DAILY_BUDGET']  ?? (getenv('TUYA_DAILY_BUDGET')  ?: 1000));
    if (($q['backoffUntil'] ?? 0) > $now) {
        return ['ok' => false, 'reason' => 'backoff', 'q' => $q];
    }
    if (($q['callsDay'] ?? 0) >= $daily) {
        return ['ok' => false, 'reason' => 'daily_budget', 'q' => $q];
    }
    if (($q['callsHour'] ?? 0) >= $hourly) {
        return ['ok' => false, 'reason' => 'hourly_budget', 'q' => $q];
    }
    return ['ok' => true, 'reason' => 'ok', 'q' => $q];
}

function tuyaQuotaBump(): array
{
    $now  = time();
    $day  = gmdate('Y-m-d', $now);
    $hour = gmdate('Y-m-d\TH', $now);
    $q = tuyaQuotaRead();
    if (($q['day'] ?? null) !== $day) {
        $q = ['day' => $day, 'hour' => $hour, 'callsDay' => 0, 'callsHour' => 0, 'backoffUntil' => 0];
    }
    if (($q['hour'] ?? null) !== $hour) {
        $q['hour'] = $hour;
        $q['callsHour'] = 0;
    }
    $q['callsDay']  = (int) ($q['callsDay'] ?? 0) + 1;
    $q['callsHour'] = (int) ($q['callsHour'] ?? 0) + 1;
    tuyaQuotaWrite($q);
    return $q;
}

function tuyaQuotaBackoff(int $seconds): void
{
    $q = tuyaQuotaRead();
    $q['backoffUntil'] = time() + $seconds;
    tuyaQuotaWrite($q);
}

/**
 * Tuya Cloud API helper for presence sensor DP read/write.
 * Shared credentials with TuyaSwitchGateway / TuyaLockGateway.
 * Caches the access token in-process (static).
 *
 * @param string $method  'GET' or 'POST'
 * @param string $path    e.g. "/v1.0/iot-03/devices/{id}/status"
 * @param string|null $body  JSON body for POST (null for GET)
 * @param bool $ignoreLocalBudget  When true, bypass the LOCAL backoff/budget
 *        guards so a read-only credit probe can run while the local backoff is
 *        active. It never bypasses Tuya: the call is still counted and a quota
 *        answer still refreshes the shared backoff. Default false keeps every
 *        existing caller's behaviour unchanged.
 * @return array{http:int, data:array, error:string|null, elapsed_ms:int}
 */
function tuyaPresenceApi(string $method, string $path, ?string $body, bool $ignoreLocalBudget = false): array {
    // ── Shared quota-exhaustion backoff (same file as checkTuyaOnline) ──
    $backoffFile = sys_get_temp_dir() . '/tuya_quota_backoff.txt';
    if (!$ignoreLocalBudget) {
        if (file_exists($backoffFile)) {
            $backoffUntil = (int) @file_get_contents($backoffFile);
            if (time() < $backoffUntil) {
                return ['http' => 429, 'data' => [], 'error' => 'Tuya quota exhausted — backoff active', 'elapsed_ms' => 0];
            }
            @unlink($backoffFile);
        }

        // F46+: presupuesto compartido (mismo fichero tuya-quota.json).
        $qchk = tuyaQuotaCheck();
        if (!$qchk['ok']) {
            return ['http' => 429, 'data' => [], 'error' => 'Tuya quota budget (' . $qchk['reason'] . ')', 'elapsed_ms' => 0];
        }
    }

    $accessId  = $_ENV['TUYA_ACCESS_ID'] ?? getenv('TUYA_ACCESS_ID') ?: '';
    $accessKey = $_ENV['TUYA_ACCESS_SECRET'] ?? getenv('TUYA_ACCESS_SECRET') ?: '';
    $baseUrl   = $_ENV['TUYA_BASE_URL'] ?? getenv('TUYA_BASE_URL') ?: 'https://openapi.tuyaeu.com';

    // ── Token caching ──
    static $token = null, $tokenExpiry = 0;
    $now = time();
    if ($token === null || $now >= $tokenExpiry) {
        $t = (string)(int)(microtime(true) * 1000);
        $sign = strtoupper(hash_hmac('sha256',
            $accessId . $t . 'GET' . "\n" . hash('sha256', '') . "\n" . "\n" . '/v1.0/token?grant_type=1',
            $accessKey));
        $ch = curl_init($baseUrl . '/v1.0/token?grant_type=1');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                "client_id: $accessId", "sign: $sign",
                "sign_method: HMAC-SHA256", "t: $t",
            ],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($resp, true);
        if (($data['success'] ?? false)) {
            $token = $data['result']['access_token'];
            $tokenExpiry = $now + (int)($data['result']['expire_time'] ?? 7200) - 60;
        } else {
            return ['http' => 502, 'data' => [], 'error' => 'Tuya token failed', 'elapsed_ms' => 0];
        }
    }

    // ── Sign & call ──
    $bodyStr = $body ?? '';
    $contentSha = hash('sha256', $bodyStr);
    $strToSign = $method . "\n" . $contentSha . "\n" . "\n" . $path;
    $t = (string)(int)(microtime(true) * 1000);
    $sign = strtoupper(hash_hmac('sha256', $accessId . $token . $t . $strToSign, $accessKey));

    $headers = [
        "client_id: $accessId", "sign: $sign",
        "sign_method: HMAC-SHA256", "t: $t",
        "access_token: $token",
    ];
    if ($method === 'POST') {
        $headers[] = "Content-Type: application/json";
        $headers[] = "Content-SHA256: $contentSha";
    }

    tuyaQuotaBump(); // F46+: contar la llamada en el presupuesto compartido

    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyStr);
    }

    $start = microtime(true);
    $resp = curl_exec($ch);
    $elapsed = (int)round((microtime(true) - $start) * 1000);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['http' => 502, 'data' => [], 'error' => "curl: $err", 'elapsed_ms' => $elapsed];
    }

    $data = json_decode($resp, true);
    if (is_array($data)) {
        // Detect quota exhaustion (shared pure classifier, also used by
        // /dashboard-api/tuya-quota) and set the local + shared backoff.
        $cls = \App\Domain\Devices\TuyaCreditsClassifier::classifyTuyaCredits($httpCode, $data);
        if ($cls['category'] === 'quota') {
            file_put_contents($backoffFile, time() + 1800); // 30 min (F46+: recuperar antes)
            tuyaQuotaBackoff(1800);                          // F46+: backoff compartido (30 min)
        }
    }
    return ['http' => $httpCode, 'data' => is_array($data) ? $data : [], 'error' => null, 'elapsed_ms' => $elapsed];
}

// ─────────────────────────────────────────────────────────────────────
// Estado verídico de conectividad por dispositivo.
//
// Cada dispositivo se clasifica por SU ORIGEN de verificación:
//   - ESP32 / local (gratis, verificación periódica vía heartbeat/pull):
//       RPI (heartbeat), SCANNER y LOCK (command-queue 'check' o relé).
//       Su estado se deriva de last_seen_at (se mantiene fresco sin cuota).
//   - Tuya cloud (consumen cuota de la API Tuya → SOLO se sondean al cargar
//       el panel o al pulsar "Comprobar dispositivos", NUNCA en el poll):
//       PRESENCE, PROXIMITY, SWITCH.
//       Su estado se deriva de la última SONDA real (result.online) persistida
//       en devices.online_state + devices.online_probed_at.
//
// IMPORTANTE: no añadir nunca un poll periódico que sondee Tuya cloud.
// ─────────────────────────────────────────────────────────────────────
/** @var list<string> Dispositivos que comparten hardware ESP32 (sin cuota Tuya). */
const DEVICE_KIND_ESP32 = ['RPI', 'SCANNER', 'LOCK'];
/** @var list<string> Dispositivos Tuya cloud (cuota → sondear solo bajo demanda). */
const DEVICE_KIND_TUYA  = ['PRESENCE', 'PROXIMITY', 'SWITCH'];
/** Cuántos segundos conserva una verificación de conectividad antes de caducar. */
const TUYA_PROBE_TTL_SECONDS = 600;

/** Política pura de liveness (F92/RF-152..RF-155). */
function deviceLivenessService(): DeviceLivenessService {
    static $service = null;
    return $service ??= new DeviceLivenessService();
}

function deviceCategory(array $dev): string {
    return deviceLivenessService()->category($dev);
}

function deviceIsEsp32(array $dev): bool {
    return deviceCategory($dev) === DeviceLivenessService::CATEGORY_LOCAL
        && in_array((string) ($dev['kind'] ?? ''), DEVICE_KIND_ESP32, true);
}

function deviceIsTuyaCloud(array $dev): bool {
    return deviceLivenessService()->isTuyaCloud($dev);
}

/** Persiste el resultado de una verificación de conectividad (Tuya, Zigbee availability o cámara). */
function persistDeviceOnlineProbe(\PDO $pdo, int $deviceId, ?bool $online): void {
    if ($online === null) {
        return;
    }
    $stmt = $pdo->prepare(
        'UPDATE devices SET online_state = :st, online_probed_at = UTC_TIMESTAMP(3) WHERE id = :did'
    );
    $stmt->execute([':st' => $online ? 1 : 0, ':did' => $deviceId]);
}

/**
 * Sonda real de conectividad de un dispositivo Tuya cloud (GET /devices/{id}).
 * Devuelve true/false si la API respondió con un result.online válido, o null
 * si Tuya no pudo confirmar (error HTTP/red/cuota). NO debe llamarse en polls.
 * F92: los dispositivos con transporte declarado distinto de TUYA nunca llegan
 * a la red (defensa en profundidad contra gasto de cuota).
 */
function probeTuyaOnlineOnce(\PDO $pdo, int $deviceId, string $externalId): ?bool {
    if ($externalId === '') {
        return null;
    }
    // F92: no sondear Zigbee/local/cámara contra Tuya. F46+: disabled tampoco.
    try {
        $m = $pdo->prepare('SELECT meta_json, transport FROM devices WHERE id = :id');
        $m->execute([':id' => $deviceId]);
        $row = $m->fetch(\PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $transport = strtoupper(trim((string) ($row['transport'] ?? '')));
            if ($transport !== '' && $transport !== \App\Domain\Devices\Device::TRANSPORT_TUYA) {
                return null;
            }
            $meta = is_string($row['meta_json'] ?? null) && $row['meta_json'] !== ''
                ? json_decode((string) $row['meta_json'], true)
                : null;
            if (is_array($meta) && (($meta['presence_source'] ?? null) === 'disabled')) {
                return null;
            }
        }
    } catch (\Throwable $e) {
        /* best-effort: ante la duda, no bloquear */
    }
    $res = tuyaPresenceApi('GET', '/v1.0/iot-03/devices/' . rawurlencode($externalId), null);
    if (($res['error'] ?? null) !== null || (int) ($res['http'] ?? 0) >= 500) {
        return null; // Tuya no disponible: no concluir online/offline
    }
    if (($res['data']['success'] ?? false) !== true) {
        return null;
    }
    $online = isset($res['data']['result']['online']) ? (bool) $res['data']['result']['online'] : null;
    if ($online === null) {
        return null;
    }
    persistDeviceOnlineProbe($pdo, $deviceId, $online);
    return $online;
}

/**
 * Carga la fila mínima de liveness de un dispositivo. Usada por ping-all-devices
 * para no depender de la forma reducida de Device.
 *
 * @return array<string,mixed>|null
 */
function loadDeviceLivenessRow(\PDO $pdo, int $deviceId): ?array {
    $stmt = $pdo->prepare(
        'SELECT id, pack_id, kind, transport, external_id, label, last_seen_at,
                online_state, online_probed_at, meta_json
           FROM devices WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $deviceId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    $row['meta'] = is_string($row['meta_json'] ?? null) && $row['meta_json'] !== ''
        ? json_decode((string) $row['meta_json'], true)
        : null;
    unset($row['meta_json']);
    return $row;
}

/**
 * @return array{state:string, online:bool|null, source:string, reason:?string}
 *   estado verídico de un dispositivo (usado por /dashboard-api/device-status).
 *   Nunca devuelve online=true sin una señal real: push/heartbeat/sonda frescos.
 */
function resolveDeviceOnlineState(array $dev, int $probeTtlSeconds): array {
    return deviceLivenessService()->resolve($dev, time(), $probeTtlSeconds);
}

/**
 * Resolve the PRESENCE sensor device assigned to a room (canonical room→pack→device chain).
 * Returns null when the room has no presence sensor (e.g. room without a pack, or a pack
 * without a PRESENCE device). Mirrors the discovery used by /dashboard-api/device-status.
 */
function resolvePresenceDeviceForRoom(\PDO $pdo, int $roomId): ?array
{
    // F93/RF-156: el calibrador atiende cualquier transporte. La exclusión de
    // Zigbee se retira; el handler decide cómo leer/escribir según `transport`.
    $stmt = $pdo->prepare(
        "SELECT d.id, d.kind, d.external_id, d.label, d.meta_json, d.last_seen_at, d.battery_pct,
                d.transport,
                (d.last_seen_at IS NOT NULL
                 AND d.last_seen_at >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 10 MINUTE)) AS online
           FROM devices d
           JOIN rooms r ON r.pack_id = d.pack_id
          WHERE r.id = :rid AND d.kind = 'PRESENCE'
            AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(d.meta_json,'\$.presence_source')),'') <> 'disabled'
          LIMIT 1"
    );
    $stmt->execute([':rid' => $roomId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['online']    = (bool) ($row['online'] ?? false);
    $row['transport'] = $row['transport'] !== null ? strtoupper((string) $row['transport']) : 'TUYA';
    $row['meta']      = !empty($row['meta_json']) ? json_decode($row['meta_json'], true) : null;
    unset($row['meta_json']);
    return $row;
}

/**
 * DP capability ranges for a PRESENCE device (F43).
 *
 * The 24G-Presence Sensor V3 and the ZY-M100 expose different Tuya DP ranges
 * (far 75-900 step 75 / sensitivity 1-10 vs far 0-1000 step 10 / sensitivity 0-9).
 * Caps are read from meta_json.dp_caps, falling back to ZY-M100-compatible defaults.
 *
 * @param array<string,mixed>|null $meta
 * @return array{far_min:int,far_max:int,far_step:int,sens_min:int,sens_max:int,off_far:?int,target_scale:int,near_min:int}
 */
function presenceDpCaps(?array $meta): array
{
    $c = (is_array($meta) && isset($meta['dp_caps']) && is_array($meta['dp_caps'])) ? $meta['dp_caps'] : [];
    $farMin  = isset($c['far_min'])  ? (int) $c['far_min']  : 0;
    $farMax  = isset($c['far_max'])  ? (int) $c['far_max']  : 1000;
    $farStep = isset($c['far_step']) ? (int) $c['far_step'] : 10;
    $sensMin = isset($c['sens_min']) ? (int) $c['sens_min'] : 0;
    $sensMax = isset($c['sens_max']) ? (int) $c['sens_max'] : 9;
    // Divisor de target_dis_closest para obtener metros: 100 (cm, ZY-M100) o 10
    // (decímetros, 24G V3). Declarado en meta_json.dp_caps.target_scale.
    $targetScale = isset($c['target_scale']) ? (int) $c['target_scale'] : 100;
    // Distancia mínima (zona muerta). El calibrador la fuerza siempre al mínimo
    // para que el sensor detecte desde pegado a él; solo se gestiona el máximo.
    $nearMin = isset($c['near_min']) ? (int) $c['near_min'] : 0;
    // "Radio 0 = OFF" only exists when the sensor admits far_detection 0 (ZY-M100).
    // The 24G V3 has far_min 75, so there is no off-by-radio sentinel for it.
    $offFar  = array_key_exists('off_far', $c)
        ? ($c['off_far'] === null ? null : (int) $c['off_far'])
        : ($farMin === 0 ? 0 : null);
    return [
        'far_min'      => $farMin,
        'far_max'      => $farMax,
        'far_step'     => $farStep,
        'sens_min'     => $sensMin,
        'sens_max'     => $sensMax,
        'off_far'      => $offFar,
        'target_scale' => $targetScale,
        'near_min'     => $nearMin,
    ];
}

/**
 * Normalize a raw Tuya DP map for a PRESENCE device into the shape returned by
 * both the status and set endpoints (F43).
 *
 * @param array<string,mixed> $dps  code => value
 * @param array<string,mixed> $caps presenceDpCaps()
 * @return array<string,mixed>
 */
function normalizePresenceDps(array $dps, array $caps): array
{
    $far   = isset($dps['far_detection']) ? (int) $dps['far_detection'] : null;
    $sens  = isset($dps['sensitivity']) ? (int) $dps['sensitivity'] : null;
    $near  = isset($dps['near_detection']) ? (int) $dps['near_detection'] : null;
    $state = $dps['presence_state'] ?? null;
    // OFF-by-radio only when the sensor admits far_detection 0 (ZY-M100).
    $isOff = ($caps['off_far'] !== null && $far !== null && $far <= 1);
    $targetScale = (int) ($caps['target_scale'] ?? 100);
    return [
        'far_detection'      => $far,
        'near_detection'     => $near,
        'sensitivity'        => $sens,
        'presence_state'     => $state,
        'target_dis_closest' => $dps['target_dis_closest'] ?? null,
        'target_distance_m'  => (isset($dps['target_dis_closest']) && $dps['target_dis_closest'] !== null && $targetScale > 0)
            ? round(((int) $dps['target_dis_closest']) / $targetScale, 1)
            : null,
        'illuminance_value'  => $dps['illuminance_value'] ?? null,
        'mode'               => $isOff ? 'OFF' : 'ON',
        // Accept both ZY-M100 ("presence") and 24G V3 ("move") as PRESENT.
        'effective_presence' => $isOff
            ? 'ABSENT'
            : (in_array($state, ['presence', 'move'], true) ? 'PRESENT' : 'ABSENT'),
    ];
}

/**
 * Persist the calibrated radio/sensitivity snapshot on the PRESENCE device of a room.
 * Stored under devices.meta_json.calibration so each room keeps its own configuration
 * (each sensor is assigned to a different room). Never clobbers other meta keys.
 * Returns the written snapshot array.
 */
function persistPresenceCalibration(\PDO $pdo, int $deviceId, ?array $meta, int $farCm, int $sensitivity, ?int $nearCm = null): array
{
    $meta = is_array($meta) ? $meta : [];
    $snap = [
        'far_detection'  => $farCm,
        'sensitivity'    => $sensitivity,
        'calibrated_at'  => gmdate('Y-m-d\TH:i:s\Z'),
        'source'         => 'dashboard-calib',
    ];
    if ($nearCm !== null) {
        $snap['near_detection'] = $nearCm;
    }
    $meta['calibration'] = $snap;
    $upd = $pdo->prepare('UPDATE devices SET meta_json = :m WHERE id = :id');
    $upd->execute([':m' => json_encode($meta, JSON_UNESCAPED_SLASHES), ':id' => $deviceId]);
    return $snap;
}

// GET /dashboard-api/rooms — list all rooms with code + status + pack info
$router->get(
    '/dashboard-api/rooms',
    function (\App\Http\Request $request) use ($pdoProvider): \App\Http\Response {
        $pdo = $pdoProvider();
        $rows = $pdo->query(
            "SELECT r.id, r.code, r.status, r.pack_id, r.room_type_id,
                    rt.code AS room_type_code, rt.name AS room_type_name,
                    dp.name AS pack_name
             FROM rooms r
             LEFT JOIN device_packs dp ON dp.id = r.pack_id
             LEFT JOIN room_types rt ON rt.id = r.room_type_id
             ORDER BY r.code ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);

        return \App\Http\Response::json(200, $rows ?: []);
    }
);

// GET /dashboard-api/mirame — list RPI devices with is_identified=1 (auto-expire 15s)
$router->get(
    '/dashboard-api/mirame',
    function (\App\Http\Request $request) use ($pdoProvider): \App\Http\Response {
        $pdo = $pdoProvider();
        // Auto-clean stale identified flags (>30s old, e.g. WiFi lost before sending state:false)
        $pdo->exec(
            "UPDATE devices SET is_identified = 0 WHERE kind = 'RPI' AND is_identified = 1 AND (identified_at IS NULL OR identified_at <= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 30 SECOND))"
        );
        $rows = $pdo->query(
            "SELECT d.id, d.external_id, d.label, d.pack_id, d.meta_json, d.is_identified, d.identified_at,
                    r.id as room_id, r.code as room_code,
                    dp.name as pack_name, dp.code as pack_code
             FROM devices d
             LEFT JOIN rooms r ON r.pack_id = d.pack_id
             LEFT JOIN device_packs dp ON dp.id = d.pack_id
             WHERE d.kind = 'RPI' AND d.is_identified = 1
               AND d.identified_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 15 SECOND)
             ORDER BY d.identified_at DESC"
        )->fetchAll(\PDO::FETCH_ASSOC);
        // Decode meta_json for clients
        foreach ($rows as &$row) {
            $row['meta'] = !empty($row['meta_json']) ? json_decode($row['meta_json'], true) : null;
            unset($row['meta_json']);
        }
        return \App\Http\Response::json(200, $rows);
    }
);

// GET /dashboard-api/system-status — health check of background processes
// F41 (contracts.md §5): 5 workers with expected/instances/pids/healthy/degraded.
// F78: el worker `presence-poller-manager` fue eliminado (no hay poller de Tuya).
// label/online/pid are kept for the existing renderSystemStatus() frontend.
$router->get(
    '/dashboard-api/system-status',
    function (\App\Http\Request $request): \App\Http\Response {
        $processes = [
            // F46: estos workers viven bajo systemd (Restart=always). La detección
            // usa `systemctl is-active` + MainPID; como hay MainPID, NO se cae al
            // recuento por patrón (que con workers one-shot daría falsos 0).
            'exit-scan'              => ['label' => 'Regla de Salida (Exit)',   'run_key' => 'exit-scan',               'pattern' => 'bin/exit-scan.php',              'systemd_unit' => 'cerraduras-worker@exit-scan'],
            'overstay-scan'          => ['label' => 'Overstay Scanner',         'run_key' => 'overstay-scan',           'pattern' => 'bin/overstay-scan.php',          'systemd_unit' => 'cerraduras-worker@overstay-scan'],
            'outbox-worker'          => ['label' => 'Outbox Worker',            'run_key' => 'outbox-worker',           'pattern' => 'bin/outbox-worker.php',          'systemd_unit' => 'cerraduras-worker@outbox-worker'],
            'anomaly-scanner'        => ['label' => 'Anomaly Scanner',          'run_key' => 'anomaly-scanner',         'pattern' => 'bin/anomaly-scanner.php',        'systemd_unit' => 'cerraduras-worker@anomaly-scanner'],
            // F78/RF-101: SIN poller de presencia. Fue eliminado por completo
            // (el sistema usa push por Pulsar + sondas bajo demanda).
            // F44 (RF-50.2.4): el consumer Pulsar es dueño único de systemd.
            'pulsar-consumer'        => ['label' => 'Eventos Tuya (Pulsar)',    'run_key' => 'tuya-pulsar-consumer',    'pattern' => 'tuya-pulsar-consumer', 'systemd_unit' => 'cerraduras-pulsar-consumer'],
        ];

        // F41: each supervised worker is a `bash -c "while true; do <cmd>; ..."`
        // wrapper that writes its own PID to api/run/<run_key>.pid. The wrapper
        // cmdline carries the marker "run/<run_key>.pid"; its short-lived php/node
        // children do not. Counting the marker therefore counts supervisors (1 per
        // wrapper), independent of whether a one-shot child is running right now.
        $runDir = dirname(__DIR__) . '/run';

        // Discard command-line noise: pgrep itself and the `sh -c pgrep ...`
        // helper spawned by exec(). The real wrapper is a `bash -c` and must be
        // kept, so POSIX shell helpers are filtered by argv[0], not by "-c".
        $isNoiseProcess = static function (string $cmdline): bool {
            if (str_contains($cmdline, 'pgrep')) {
                return true;
            }
            $argv  = explode("\0", $cmdline);
            $shell = basename($argv[0] ?? '');
            return ($argv[1] ?? '') === '-c' && in_array($shell, ['sh', 'dash'], true);
        };

        $result = [];
        foreach ($processes as $key => $cfg) {
            $runKey  = $cfg['run_key'];
            $pidFile = $runDir . '/' . $runKey . '.pid';
            $marker  = 'run/' . $runKey . '.pid';
            $pids    = [];

            // F44 (RF-50.2): worker gestionado por systemd (consumer Pulsar).
            // Se cuenta su MainPID y se une con la detección por marcador para
            // que un wrapper LEGADO duplicado se siga viendo como `degraded`.
            if (!empty($cfg['systemd_unit'])) {
                $unit   = (string) $cfg['systemd_unit'];
                $active = trim((string) @shell_exec('systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null'));
                $mainPid = (int) trim((string) @shell_exec('systemctl show -p MainPID --value ' . escapeshellarg($unit) . ' 2>/dev/null'));
                if ($active === 'active' && $mainPid > 0) {
                    $pids[] = $mainPid;
                }
            }

            // Primary source of truth: live supervisors carry the PID-file marker.
            // exec() only returns the LAST output line, so it must be called with
            // the $output array to collect every PID (F41-17 instance counting).
            $pgrepOut = [];
            @exec('pgrep -f -- ' . escapeshellarg($marker), $pgrepOut);
            foreach ($pgrepOut as $candidate) {
                $candidate = trim((string) $candidate);
                if ($candidate === '' || !ctype_digit($candidate)) {
                    continue;
                }
                $pid = (int) $candidate;
                $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
                if ($cmdline === false
                    || !str_contains($cmdline, $marker)
                    || $isNoiseProcess($cmdline)
                ) {
                    continue;
                }
                $pids[] = $pid;
            }

            // The PID file corroborates the supervisor when pgrep misses it and
            // validates liveness (stale PID file after a crash must not count).
            if (is_file($pidFile)) {
                $raw = trim((string) @file_get_contents($pidFile));
                if ($raw !== '' && ctype_digit($raw)) {
                    $pid = (int) $raw;
                    $alive = function_exists('posix_kill')
                        ? @posix_kill($pid, 0)
                        : file_exists('/proc/' . $pid);
                    $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
                    if ($alive
                        && $cmdline !== false
                        && str_contains($cmdline, $marker)
                        && !$isNoiseProcess($cmdline)
                        && !in_array($pid, $pids, true)
                    ) {
                        $pids[] = $pid;
                    }
                }
            }

            // Fallback (legacy manual startup / no PID files yet): keep the robust
            // per-pattern count that has always worked for ad-hoc runs.
            if (count($pids) === 0) {
                $patternOut = [];
                @exec('pgrep -f -- ' . escapeshellarg($cfg['pattern']), $patternOut);
                foreach ($patternOut as $candidate) {
                    $candidate = trim((string) $candidate);
                    if ($candidate === '' || !ctype_digit($candidate)) {
                        continue;
                    }
                    $pid = (int) $candidate;
                    // Drop the helper shell/pgrep whose own cmdline carries the pattern.
                    $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
                    if ($cmdline === false
                        || str_contains($cmdline, 'pgrep')
                        || str_contains($cmdline, '-c')
                    ) {
                        continue;
                    }
                    $pids[] = $pid;
                }
            }

            sort($pids);
            $instances = count($pids);
            $expected  = 1;

            $result[$key] = [
                'label'     => $cfg['label'],
                'online'    => $instances >= 1,
                'pid'       => $instances > 0 ? $pids[0] : null,
                'expected'  => $expected,
                'instances' => $instances,
                'pids'      => $pids,
                'healthy'   => $instances === $expected,
                'degraded'  => $instances > $expected,
            ];
        }

        // F47 (RF-54.4.2): salud real del consumer Pulsar desde su fichero de
        // estado (proceso vivo pero WS caído o mudo). El fichero lo escribe
        // `bin/tuya-pulsar-consumer/index.js`; si aún no existe, no se añade nada.
        //
        // IMPORTANTE (contrato F41 §5): estos datos se exponen como campos
        // ADITIVOS (`ws_connected`, `ws_silent`, `status_reason`) y NO alteran
        // `healthy`/`degraded`, que siguen rigiéndose por el recuento de
        // instancias (`healthy === (instances === expected)`).
        $pcStatusFile = $runDir . '/pulsar-consumer-status.json';
        if (isset($result['pulsar-consumer']) && is_file($pcStatusFile)) {
            $pcRaw = @file_get_contents($pcStatusFile);
            $pcSt  = $pcRaw !== false ? json_decode($pcRaw, true) : null;
            if (is_array($pcSt) && array_key_exists('connected', $pcSt)) {
                $pcLast   = isset($pcSt['last_msg_at']) ? strtotime((string) $pcSt['last_msg_at']) : false;
                $pcSilent = $pcLast !== false && (time() - $pcLast) > 90;
                $result['pulsar-consumer']['ws_connected'] = ($pcSt['connected'] === true);
                $result['pulsar-consumer']['ws_silent']    = $pcSilent;
                $result['pulsar-consumer']['status_reason'] = ($pcSt['connected'] !== true)
                    ? 'ws_disconnected'
                    : ($pcSilent ? 'ws_silent' : null);
            }
        }

        return \App\Http\Response::json(200, $result);
    }
);

// POST /dashboard-api/mirame/{device_id}/clear — clear mírame flag
$router->post(
    '/dashboard-api/mirame/{device_id}/clear',
    function (\App\Http\Request $request) use ($pdoProvider): \App\Http\Response {
        $deviceId = (int) $request->routeParam('device_id');
        $pdo = $pdoProvider();
        $stmt = $pdo->prepare("UPDATE devices SET is_identified = 0, identified_at = NULL WHERE id = :id AND kind = 'RPI'");
        $stmt->execute([':id' => $deviceId]);
        if ($stmt->rowCount() === 0) {
            return \App\Http\Response::json(404, ['error' => 'Device not found or not an RPI']);
        }
        return \App\Http\Response::json(200, ['ok' => true, 'device_id' => $deviceId]);
    }
);

// GET /dashboard-api/pack-detail?pack_id=N — devices in a pack with online status
$router->get(
    '/dashboard-api/pack-detail',
    function (\App\Http\Request $request) use ($pdoProvider, $deviceStatusFactory): \App\Http\Response {
        $packId = (int) ($request->query['pack_id'] ?? 0);
        if ($packId <= 0) {
            return \App\Http\Response::json(400, ['error' => 'pack_id required']);
        }
        $pdo = $pdoProvider();
        // Get pack info
        $pack = $pdo->prepare("SELECT id, code, name FROM device_packs WHERE id = :pid LIMIT 1");
        $pack->execute([':pid' => $packId]);
        $packRow = $pack->fetch(\PDO::FETCH_ASSOC);
        if (!$packRow) {
            return \App\Http\Response::json(404, ['error' => 'Pack not found']);
        }
        // Devices of the pack with online status (canonical F30: device → pack).
        $devStmt = $pdo->prepare(
            "SELECT d.id, d.pack_id, d.kind, d.external_id, d.label, d.transport, d.meta_json, d.last_seen_at, d.battery_pct,
                      (d.last_seen_at IS NOT NULL AND d.last_seen_at >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 10 MINUTE)) AS online
              FROM devices d
              WHERE d.pack_id = :pid
             ORDER BY d.kind"
        );
        $devStmt->execute([':pid' => $packId]);
        $devices = $devStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($devices as &$d) {
            $d['meta'] = !empty($d['meta_json']) ? json_decode($d['meta_json'], true) : null;
            unset($d['meta_json']);
            $d['transport'] = $d['transport'] ?? 'UNKNOWN';
            $d['integration_status'] = $deviceStatusFactory()->forRow($d);
        }
        unset($d);
        return \App\Http\Response::json(200, $devices);
    }
);

// (POST /dashboard-api/apply-pack movido después de inicialización de $applyPackService — línea ~660)

// --- Authenticated routes ---
// We build auth middlewares lazily using a helper so handlers without DB
// (like /health) don't incur a connection.
$authFactory = static function (array $requiredScopes) use ($pdoProvider): array {
    static $apiKey = null;
    static $pdo = null;
    if ($apiKey === null) {
        $pdo = $pdoProvider();
        $crmSessionRepo = new CrmSessionRepository($pdo);
        $crmUserRepo    = new CrmUserRepository($pdo);
        $apiKey = new AuthApiKeyMiddleware(
            new ApiClientRepository($pdo),
            $crmSessionRepo,
            $crmUserRepo
        );
    }
    return [$apiKey, new AuthScopeMiddleware($requiredScopes)];
};

$idempotencyFactory = static function (string $scope, bool $required = true, int $ttl = 86400) use ($pdoProvider): Middleware {
    static $store = null;
    static $pdo = null;
    if ($store === null) {
        $pdo = $pdoProvider();
        $store = new IdempotencyStore($pdo);
    }
    return new IdempotencyMiddleware($store, $scope, $required, $ttl);
};

// --- Wire domain services & controllers. Services/repos are lazily built;
//     we instantiate them only when at least one route that uses them is
//     reachable. For simplicity in MVP we build them once per request here. ---
$pdo = $pdoProvider();

$roomTypeRepo = new RoomTypeRepository($pdo);
$roomTypeService = new RoomTypeService($roomTypeRepo);
$roomTypeController = new RoomTypeController($roomTypeService);

$timeSlotRepo = new TimeSlotRepository($pdo);
$timeSlotService = new TimeSlotService($timeSlotRepo);
$timeSlotController = new TimeSlotController($roomTypeService, $timeSlotService);

$roomRepo = new RoomRepository($pdo);
$roomService = new RoomService($roomRepo, $roomTypeRepo);
$roomStateService = new RoomStateService($roomRepo);

$deviceRepo = new DeviceRepository($pdo);
$deviceService = new DeviceService($deviceRepo, $roomRepo);
$switchService = new SwitchService($deviceRepo, $roomRepo);
$deviceController = new DeviceController($deviceService, $deviceStatusFactory());
$qrRejectionController = new QrRejectionController($pdo);
$commandQueueRepo = new \App\Domain\Devices\CommandQueueRepository($pdo);

// Device Packs (F25)
$devicePackRepo     = new DevicePackRepository($pdo);
$qrTokenizer        = new QrTokenizer(Config::getRequired('QR_SIGNING_SECRET'));
$applyPackService   = new ApplyPackService($devicePackRepo, $deviceRepo, $roomRepo, $pdo);
$devicePackController = new DevicePackController($devicePackRepo, $applyPackService, $deviceStatusFactory());

// POST /dashboard-api/apply-pack — assign a pack to a room via ApplyPackService
// (movido aquí tras init de $applyPackService — corrección de scope)
$router->post(
    '/dashboard-api/apply-pack',
    function (\App\Http\Request $request) use ($applyPackService): \App\Http\Response {
        $body = $request->jsonBody ?? [];
        $roomId = (int) ($body['room_id'] ?? 0);
        $packId = (int) ($body['pack_id'] ?? 0);
        if ($roomId <= 0 || $packId <= 0) {
            return \App\Http\Response::json(400, ['error' => 'room_id and pack_id required']);
        }
        try {
            $result = $applyPackService->apply($roomId, $packId);
            return \App\Http\Response::json(200, $result);
        } catch (\App\Support\Errors\NotFoundException $e) {
            return \App\Http\Response::json(404, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return \App\Http\Response::json(500, ['error' => $e->getMessage()]);
        }
    }
);

// F59 (RF-66): limpieza del ciclo anterior (cooldown + estado IoT) compartida
// por el panel de pruebas y la emisión real de QR.
$roomCycleResetter  = new \App\Domain\Rooms\RoomCycleResetter($pdo);
$qrTestCtrl         = new QrTestController($pdo, $qrTokenizer, $switchService, $roomCycleResetter);

$switchController = new SwitchController($switchService);

// --- F38: Worker Roles ---
$workerRoleRepo      = new WorkerRoleRepository($pdo);
$workerRepoForRoles  = new WorkerRepository($pdo);
$workerRoleService   = new WorkerRoleService($workerRoleRepo, $workerRepoForRoles);
$workerRoleController = new WorkerRoleController($workerRoleService);

// --- F38: Workers ---
$workerSessionRepo   = new WorkerSessionRepository($pdo);
$workerService       = new WorkerService(
    $workerRepoForRoles,
    $workerRoleRepo,
    $workerSessionRepo,
    $qrTokenizer,
    $roomRepo
);
$workerController    = new WorkerController($workerService);

// --- F39: Factory identification (isolated from operational devices) ---
$factoryDeviceService = new \App\Domain\FactoryDevices\FactoryDeviceService(new FactoryDeviceRepository($pdo));
$factoryDeviceController = new FactoryDeviceController($factoryDeviceService);

// --- F38: Worker QR Validate ---

// ═══════════════════════════════════════════════════════════════
// Dashboard API — endpoints avanzados (usan servicios ya cableados)
// ═══════════════════════════════════════════════════════════════

// POST /dashboard-api/blink-light — parpadeo de arranque (3x ON/OFF, 1s interval)
// Recibe {external_id: "d443229fc114"} (chip ID del RPI)
// Pack-based: busca el SWITCH en el mismo pack que el RPI, sin pasar por rooms.
$router->post(
    '/dashboard-api/blink-light',
    function (\App\Http\Request $request) use ($deviceRepo, $switchService): \App\Http\Response {
        $body = $request->jsonBody ?? [];
        $extId = (string) ($body['external_id'] ?? '');
        if ($extId === '') {
            return \App\Http\Response::json(400, ['error' => 'external_id required']);
        }

        // 1. Find RPI device → get pack_id
        $rpi = $deviceRepo->findByKindAndExternalId(\App\Domain\Devices\Device::KIND_RPI, $extId);
        if ($rpi === null || $rpi->packId === null) {
            return \App\Http\Response::json(404, ['error' => 'RPI device not found or not in a pack']);
        }
        $packId = $rpi->packId;

        // 2. Find SWITCH device in the same pack (pack-based, no room involved)
        $sw = $deviceRepo->findOneByPackAndKind($packId, \App\Domain\Devices\Device::KIND_SWITCH);
        if ($sw === null) {
            return \App\Http\Response::json(404, ['error' => 'No SWITCH device in pack']);
        }

        // 3. Fase 1: encender 5 segundos
        $results = [];
        try {
            $r = $switchService->turnOnByPack($packId);
            $results[] = ['seq' => 1, 'action' => 'on-5s', 'ok' => $r['ok']];
        } catch (\Throwable $e) {
            $results[] = ['seq' => 1, 'action' => 'on-5s', 'ok' => false, 'error' => $e->getMessage()];
        }
        sleep(5);

        // 4. Fase 2: parpadear 3x (ON 1s / OFF 1s), termina OFF
        for ($i = 0; $i < 3; $i++) {
            try {
                $r = $switchService->turnOffByPack($packId);
                $results[] = ['seq' => 2 + ($i * 2), 'action' => 'off', 'ok' => $r['ok']];
            } catch (\Throwable $e) {
                $results[] = ['seq' => 2 + ($i * 2), 'action' => 'off', 'ok' => false, 'error' => $e->getMessage()];
            }
            sleep(1);
            try {
                $r = $switchService->turnOnByPack($packId);
                $results[] = ['seq' => 3 + ($i * 2), 'action' => 'on', 'ok' => $r['ok']];
            } catch (\Throwable $e) {
                $results[] = ['seq' => 3 + ($i * 2), 'action' => 'on', 'ok' => false, 'error' => $e->getMessage()];
            }
            sleep(1);
        }
        // Apagar al final
        try {
            $r = $switchService->turnOffByPack($packId);
            $results[] = ['seq' => 99, 'action' => 'final-off', 'ok' => $r['ok']];
        } catch (\Throwable $e) {
            $results[] = ['seq' => 99, 'action' => 'final-off', 'ok' => false, 'error' => $e->getMessage()];
        }

        $allOk = count(array_filter($results, fn($r) => $r['ok'])) === count($results);
        return \App\Http\Response::json($allOk ? 200 : 207, [
            'ok' => $allOk,
            'blinked' => true,
            'final_state' => 'off',
            'results' => $results,
        ]);
    }
);

// POST /dashboard-api/assign-and-reset — asigna todos los dispositivos del pack a la room y resetea estado
// Recibe {device_id: N, room_id: N}
$router->post(
    '/dashboard-api/assign-and-reset',
    function (\App\Http\Request $request) use ($pdo, $deviceRepo, $deviceStatusFactory): \App\Http\Response {
        $body = $request->jsonBody ?? [];
        $deviceId = (int) ($body['device_id'] ?? 0);
        $roomId   = (int) ($body['room_id'] ?? 0);
        if ($deviceId <= 0 || $roomId <= 0) {
            return \App\Http\Response::json(400, ['error' => 'device_id and room_id required']);
        }

        // Find the RPI device
        $rpi = $deviceRepo->findById($deviceId);
        if ($rpi === null || $rpi->kind !== \App\Domain\Devices\Device::KIND_RPI) {
            return \App\Http\Response::json(404, ['error' => 'RPI device not found']);
        }
        if ($rpi->packId === null) {
            return \App\Http\Response::json(400, ['error' => 'RPI device is not in a pack']);
        }
        $packId = $rpi->packId;

        $pdo->beginTransaction();
        try {
            // 0. Capture old room before unassigning, then clean its IoT session
            $oldRoom = $pdo->prepare("SELECT id FROM rooms WHERE pack_id = :pid AND id != :rid LIMIT 1");
            $oldRoom->execute([':pid' => $packId, ':rid' => $roomId]);
            $oldId = $oldRoom->fetchColumn();

            // 0a. Unassign this pack from any other room (one pack, one room)
            $pdo->prepare("UPDATE rooms SET pack_id = NULL WHERE pack_id = :pid AND id != :rid")
                ->execute([':pid' => $packId, ':rid' => $roomId]);

            // 0b. Clear old room's IoT session (prevents stale ANOMALIA_RP alerts)
            if ($oldId) {
                $pdo->prepare("DELETE FROM iot_sessions WHERE room_id = :oid")
                    ->execute([':oid' => (int)$oldId]);
            }

            // 1. Assign pack to room (canonical: rooms.pack_id, no devices.room_id)
            $pdo->prepare("UPDATE rooms SET pack_id = :pid, status = 'FREE', cooldown_until = NULL WHERE id = :rid")
                ->execute([':pid' => $packId, ':rid' => $roomId]);

            // 2. Close any active stays for this room
            $pdo->prepare("UPDATE stays SET status = 'CLOSED', closed_at = UTC_TIMESTAMP(3) WHERE room_id = :rid AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')")
                ->execute([':rid' => $roomId]);

            // 5. Reset IoT session — estado UNKNOWN (honesto): el reset no puede
            //    afirmar CLOSED sin consultar el sensor real.
            $pdo->prepare("INSERT INTO iot_sessions (room_id, door_state, presence_state, updated_at)
                           VALUES (:rid, 'UNKNOWN', 'UNKNOWN', UTC_TIMESTAMP(3))
                           ON DUPLICATE KEY UPDATE door_state = 'UNKNOWN', presence_state = 'UNKNOWN', last_open_at = NULL, last_absent_since = NULL, exit_evaluated_at = NULL, updated_at = UTC_TIMESTAMP(3)")
                ->execute([':rid' => $roomId]);

            // 5. Track liveness on RPI
            $pdo->prepare("UPDATE devices SET last_seen_at = UTC_TIMESTAMP(3) WHERE id = :did")
                ->execute([':did' => $deviceId]);

            $pdo->commit();

            // Count assigned devices
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM devices WHERE pack_id = :pid");
            $countStmt->execute([':pid' => $packId]);
            $assignedCount = (int) $countStmt->fetchColumn();

            // Ping all devices in the pack to verify liveness (one-shot, user-triggered)
            $devsStmt = $pdo->prepare(
                "SELECT id, kind, transport, external_id, last_seen_at, online_state, online_probed_at, meta_json
                   FROM devices WHERE pack_id = :pid ORDER BY kind"
            );
            $devsStmt->execute([':pid' => $packId]);
            $packDevices = $devsStmt->fetchAll(\PDO::FETCH_ASSOC);

            $deviceChecks = [];
            $first = true;
            // F33: get RPI external_id from the pack for command queuing
            $rpiExtId = $rpi->externalId;
            foreach ($packDevices as $dev) {
                // F92: categoría por transporte; cámara y Zigbee no tocan Tuya.
                $dev['meta'] = is_string($dev['meta_json'] ?? null) && $dev['meta_json'] !== ''
                    ? json_decode((string) $dev['meta_json'], true)
                    : null;
                unset($dev['meta_json']);
                $dev['integration_status'] = $deviceStatusFactory()->forRow($dev);
                $category = deviceCategory($dev);

                // F33: Skip RPI, SCANNER, and LOCK — all ESP32-connected, no Tuya
                $esp32Kinds = ['RPI', 'SCANNER', 'LOCK'];
                if ($category === DeviceLivenessService::CATEGORY_LOCAL && in_array($dev['kind'], $esp32Kinds, true)) {
                    if ($dev['kind'] === 'RPI') {
                        $deviceChecks[] = [
                            'device_id' => (int)$dev['id'],
                            'kind' => $dev['kind'],
                            'external_id' => $dev['external_id'],
                            'online' => true,
                            'last_seen_at' => $dev['last_seen_at'],
                            'checked_via' => 'HEARTBEAT',
                        ];
                        continue;
                    }

                    // SCANNER or LOCK: queue a 'check' command for this RPI
                    $dupCheck = $pdo->prepare("SELECT 1 FROM device_commands WHERE external_id = :x AND command = 'check' AND status IN ('pending','picked_up') LIMIT 1");
                    $dupCheck->execute([':x' => $rpiExtId]);
                    if (!$dupCheck->fetchColumn()) {
                        $pdo->prepare("INSERT INTO device_commands (external_id, command, payload_json, status) VALUES (:x, 'check', NULL, 'pending')")
                            ->execute([':x' => $rpiExtId]);
                    }

                    $deviceChecks[] = [
                        'device_id' => (int)$dev['id'],
                        'kind' => $dev['kind'],
                        'external_id' => $dev['external_id'],
                        'online' => null,
                        '_pending_check' => true,
                        'checked_via' => 'COMMAND_QUEUED',
                        'last_seen_at' => $dev['last_seen_at'],
                    ];
                    continue;
                }

                if (!$first) usleep(200_000);
                $first = false;

                if ($category === DeviceLivenessService::CATEGORY_CAMERA) {
                    $deviceChecks[] = [
                        'device_id' => (int) $dev['id'],
                        'kind' => $dev['kind'],
                        'external_id' => $dev['external_id'],
                        'online' => null,
                        'state' => 'unknown',
                        'error' => 'sonda de cámara omitida en reset',
                        'last_seen_at' => $dev['last_seen_at'],
                        'checked_via' => 'CAMERA',
                    ];
                    continue;
                }

                if ($category === DeviceLivenessService::CATEGORY_ZIGBEE) {
                    $st = resolveDeviceOnlineState($dev, TUYA_PROBE_TTL_SECONDS);
                    $deviceChecks[] = [
                        'device_id' => (int) $dev['id'],
                        'kind' => $dev['kind'],
                        'external_id' => $dev['external_id'],
                        'online' => $st['online'],
                        'state' => $st['state'],
                        'error' => $st['reason'],
                        'last_seen_at' => $dev['last_seen_at'],
                        'checked_via' => 'ZIGBEE',
                    ];
                    continue;
                }

                if ($category !== DeviceLivenessService::CATEGORY_TUYA) {
                    $deviceChecks[] = [
                        'device_id' => (int) $dev['id'],
                        'kind' => $dev['kind'],
                        'external_id' => $dev['external_id'],
                        'online' => null,
                        'state' => 'unknown',
                        'error' => 'transporte sin sonda',
                        'last_seen_at' => $dev['last_seen_at'],
                        'checked_via' => 'NONE',
                    ];
                    continue;
                }

                // Tuya cloud (PRESENCE/PROXIMITY/SWITCH): sonda real de conectividad.
                $online = probeTuyaOnlineOnce($pdo, (int)$dev['id'], (string)$dev['external_id']);
                if ($online === true) {
                    // Comandable alcanzable: refrescar actividad también.
                    $pdo->prepare("UPDATE devices SET last_seen_at = UTC_TIMESTAMP(3) WHERE id = :did")
                        ->execute([':did' => $dev['id']]);
                    $deviceChecks[] = [
                        'device_id' => (int)$dev['id'],
                        'kind' => $dev['kind'],
                        'external_id' => $dev['external_id'],
                        'online' => true,
                        'state' => 'online',
                        'error' => null,
                        'last_seen_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
                        'checked_via' => 'TUYA',
                    ];
                } elseif ($online === false) {
                    $deviceChecks[] = [
                        'device_id' => (int)$dev['id'],
                        'kind' => $dev['kind'],
                        'external_id' => $dev['external_id'],
                        'online' => false,
                        'state' => 'offline',
                        'error' => null,
                        'last_seen_at' => $dev['last_seen_at'],
                        'checked_via' => 'TUYA',
                    ];
                } else {
                    $deviceChecks[] = [
                        'device_id' => (int)$dev['id'],
                        'kind' => $dev['kind'],
                        'external_id' => $dev['external_id'],
                        'online' => null,
                        'state' => 'unknown',
                        'error' => 'Tuya no pudo confirmar conectividad',
                        'last_seen_at' => $dev['last_seen_at'],
                        'checked_via' => 'TUYA',
                    ];
                }
            }

            return \App\Http\Response::json(200, [
                'ok' => true,
                'room_id' => $roomId,
                'pack_id' => $packId,
                'devices_assigned' => $assignedCount,
                'room_reset' => true,
                'device_checks' => $deviceChecks,
            ]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return \App\Http\Response::json(500, ['error' => $e->getMessage()]);
        }
    }
);

// GET /dashboard-api/device-status?room_id=N — device liveness (online/offline)
$router->get(
    '/dashboard-api/device-status',
    function (\App\Http\Request $request) use ($pdo, $deviceStatusFactory): \App\Http\Response {
        $roomId = (int) ($request->query['room_id'] ?? 0);
        if ($roomId <= 0) {
            return \App\Http\Response::json(400, ['error' => 'room_id required']);
        }
        // Fetch room's assigned pack (real pack, not hardcoded detection)
        $packStmt = $pdo->prepare(
            "SELECT dp.id AS pack_id, dp.code AS pack_code, dp.name AS pack_name
             FROM device_packs dp
             INNER JOIN rooms r ON r.pack_id = dp.id
             WHERE r.id = :rid
             LIMIT 1"
        );
        $packStmt->execute([':rid' => $roomId]);
        $packRow = $packStmt->fetch(\PDO::FETCH_ASSOC);
        $pack = $packRow ?: ['pack_id' => null, 'pack_code' => null, 'pack_name' => null];

        // Resolve devices via pack chain (canonical model: device → pack → room).
        // Use MySQL DATE_SUB for consistent timezone. The SQL `online` column is a
        // freshness hint used ONLY for ESP32/local devices (last_seen heartbeat).
        $rows = $pdo->prepare(
            "SELECT d.id, d.pack_id, d.kind, d.external_id, d.label, d.transport, d.meta_json, d.last_seen_at,
                    d.online_state, d.online_probed_at, d.battery_pct,
                    (d.last_seen_at IS NOT NULL AND d.last_seen_at >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 10 MINUTE)) AS online
              FROM devices d
              JOIN rooms r ON r.pack_id = d.pack_id
              WHERE r.id = :rid AND r.pack_id IS NOT NULL
              ORDER BY d.kind"
        );
        $rows->execute([':rid' => $roomId]);
        $devices = $rows->fetchAll(\PDO::FETCH_ASSOC);

        $inheritOnlineKinds = DEVICE_KIND_ESP32; // LOCK/SCANNER/RPI comparten hardware ESP32
        $rpiOnline = false;
        foreach ($devices as $d) {
            if ($d['kind'] === 'RPI' && deviceIsEsp32($d) && (bool) ($d['online'] ?? false)) {
                $rpiOnline = true;
                break;
            }
        }

        foreach ($devices as &$d) {
            $d['meta'] = !empty($d['meta_json']) ? json_decode($d['meta_json'], true) : null;
            unset($d['meta_json']);

            $d['transport'] = $d['transport'] ?? 'UNKNOWN';
            $d['integration_status'] = $deviceStatusFactory()->forRow($d);
            $d['tuya_cloud'] = deviceIsTuyaCloud($d);

            // F92: la política resuelve cámara, Zigbee, Tuya y ESP32 por transporte.
            // No hay ninguna sonda de red aquí: solo se leen señales/probes persistidos.
            $st = resolveDeviceOnlineState($d, TUYA_PROBE_TTL_SECONDS);
            $d['state']  = $st['state'];
            $d['online'] = $st['online'];
            $d['liveness_source'] = $st['source'];
            $d['liveness_reason'] = $st['reason'];

            // Heredar liveness del RPI a sub-dispositivos ESP32 del mismo hardware
            // (solo marcador informativo; LOCK/SCANNER además se confirman por pull).
            if ($rpiOnline && in_array($d['kind'], $inheritOnlineKinds, true) && !$d['online'] && $d['kind'] !== 'RPI') {
                $d['online'] = true;
                $d['state'] = 'online';
                $d['_inherited'] = true;
                $d['liveness_source'] = 'esp32_heartbeat';
                $d['liveness_reason'] = null;
            }
        }
        unset($d);

        // NOTA: para Tuya cloud (PRESENCE/PROXIMITY/SWITCH) el punto del panel ya NO se
        // calcula por last_seen_at reactivo (eso producía falsos "online" cuando un evento
        // o un comando cloud aceptado refrescaba last_seen sin que el dispositivo físico
        // estuviera realmente conectado). Solo una sonda real (al cargar o "Comprobar")
        // marca online/offline; si no hay sonda fresca el estado es 'unknown' (ámbar).

        $onlineCount  = 0;
        $offlineCount = 0;
        $unknownCount = 0;
        foreach ($devices as $d) {
            if ($d['state'] === 'online')   { $onlineCount++; }
            elseif ($d['state'] === 'offline') { $offlineCount++; }
            else { $unknownCount++; }
        }

        return \App\Http\Response::json(200, [
            'room_id' => $roomId,
            'pack_id' => $pack['pack_id'],
            'pack_code' => $pack['pack_code'],
            'pack_name' => $pack['pack_name'],
            'online' => $onlineCount,
            'offline' => $offlineCount,
            'unknown' => $unknownCount,
            'total' => count($devices),
            'devices' => $devices,
        ]);
    }
);

// --- Command queue ESP32 (F32 ping + F33 pull queue) ------------------------
// Pull-based model: the ESP32 chip only knows its own chipId. Every command is
// keyed by the owner RPI external_id of the pack; sub-devices (SCANNER/LOCK) are
// resolved within that pack by kind — never by hardcoded ids.

/**
 * Resolve the owner chip of a device pack.
 * @return string|null external_id (chipId) of the pack's RPI
 */
$resolveOwnerChip = static function (\App\Domain\Devices\DeviceRepository $repo, ?int $packId, string $fallback): ?string {
    if ($packId !== null) {
        $rpis = $repo->findByPackAndKind($packId, \App\Domain\Devices\Device::KIND_RPI);
        foreach ($rpis as $rpi) {
            if ($rpi->externalId !== '') {
                return $rpi->externalId;
            }
        }
    }
    return $fallback !== '' ? $fallback : null;
};

// POST /dashboard-api/device-heartbeat — liveness beacon from the ESP32 chip.
// Refreshes last_seen for the RPI owner and for every sub-kind within its own
// pack; reports whether a command is pending for the chip.
// Body: {"external_id":"<chipId>","sub_kinds":["RPI","SCANNER","LOCK"]}  (legacy: {"external_id","sub_kind"})
$router->post(
    '/dashboard-api/device-heartbeat',
    function (\App\Http\Request $request) use ($deviceRepo, $commandQueueRepo): \App\Http\Response {
        $body = $request->jsonBody ?? [];
        $extId = (string) ($body['external_id'] ?? '');
        if ($extId === '') {
            return \App\Http\Response::json(400, ['error' => 'external_id required']);
        }

        // Normalise sub-kinds: accept batch `sub_kinds` or legacy single `sub_kind`.
        $subKinds = [];
        if (isset($body['sub_kinds']) && is_array($body['sub_kinds'])) {
            foreach ($body['sub_kinds'] as $k) {
                if (is_string($k) && $k !== '') {
                    $subKinds[] = strtoupper($k);
                }
            }
        }
        $legacy = $body['sub_kind'] ?? null;
        if (is_string($legacy) && $legacy !== '' && !in_array(strtoupper($legacy), $subKinds, true)) {
            $subKinds[] = strtoupper($legacy);
        }
        if ($subKinds === []) {
            $subKinds[] = \App\Domain\Devices\Device::KIND_RPI;
        }

        $rpi = $deviceRepo->findByKindAndExternalId(\App\Domain\Devices\Device::KIND_RPI, $extId);

        // F97/RF-175: the sketch declares its actuator variant and version.
        // Informational: never changes subtype/behaviour by itself.
        $firmwareVariant = isset($body['firmware_variant']) && is_string($body['firmware_variant'])
            ? strtoupper(trim($body['firmware_variant'])) : null;
        $firmwareVersion = isset($body['firmware_version']) && is_string($body['firmware_version'])
            ? trim($body['firmware_version']) : null;
        $variantMismatch = false;

        // RPI: precise liveness update (external_id match).
        $ownerPack = null;
        if ($rpi !== null) {
            $deviceRepo->updateLastSeen($rpi->id);
            if ($firmwareVariant !== null || $firmwareVersion !== null) {
                $deviceRepo->updateFirmwareInfo($rpi->id, $firmwareVariant, $firmwareVersion);
            }
            $expectedVariant = $rpi->subtype !== null && $rpi->subtype !== ''
                ? strtoupper($rpi->subtype) : \App\Domain\Devices\Device::SUBTYPE_ESP_CERRADURA;
            $variantMismatch = $firmwareVariant !== null && $firmwareVariant !== $expectedVariant;
            $ownerPack = $rpi->packId;
        }

        // Sub-devices: resolve by pack + kind (robust even if their own
        // external_id differs from the chip, e.g. legacy SCANNER rows).
        foreach ($subKinds as $kind) {
            if ($kind === \App\Domain\Devices\Device::KIND_RPI) {
                // RPI already handled precisely above; no need to repeat.
                continue;
            }
            if ($ownerPack !== null) {
                $deviceRepo->touchPackKind($ownerPack, $kind);
            }
        }

        $hasPending = $commandQueueRepo->hasPending($extId);

        // Opportunistic cleanup: bound the queue while the chip is active.
        // Only runs when there is a backlog, so the DELETE is infrequent.
        if ($hasPending) {
            $commandQueueRepo->purgeStale(24);
        }

        return \App\Http\Response::json(200, [
            'ok' => true,
            'has_pending_commands' => $hasPending,
            'firmware_variant' => $firmwareVariant,
            'firmware_version' => $firmwareVersion,
            'variant_mismatch' => $variantMismatch,
        ]);
    }
);

// GET /dashboard-api/pending-command?external_id=<chipId>
// Returns the oldest pending command for the chip and marks it picked_up.
$router->get(
    '/dashboard-api/pending-command',
    function (\App\Http\Request $request) use ($commandQueueRepo): \App\Http\Response {
        $extId = (string) ($request->query['external_id'] ?? '');
        if ($extId === '') {
            return \App\Http\Response::json(400, ['error' => 'external_id required']);
        }
        $cmd = $commandQueueRepo->pickUp($extId);
        if ($cmd === null) {
            return \App\Http\Response::json(200, ['ok' => true, 'command' => null]);
        }
        return \App\Http\Response::json(200, [
            'ok' => true,
            'command' => $cmd,
            'id' => $cmd['id'],
            'command_name' => $cmd['command'],
        ]);
    }
);

// POST /dashboard-api/command-result — ESP32 reports execution result for a command.
// Body: {"command_id":<int>,"external_id":"<chipId>","results":{...}}
$router->post(
    '/dashboard-api/command-result',
    function (\App\Http\Request $request) use ($commandQueueRepo): \App\Http\Response {
        $body = $request->jsonBody ?? [];
        $commandId = isset($body['command_id']) ? (int) $body['command_id'] : 0;
        $extId = (string) ($body['external_id'] ?? '');
        $results = isset($body['results']) && is_array($body['results']) ? $body['results'] : [];

        if ($commandId <= 0 || $extId === '') {
            return \App\Http\Response::json(400, ['error' => 'command_id and external_id are required']);
        }
        $updated = $commandQueueRepo->finish($commandId, $extId, $results);
        if ($updated === 0) {
            return \App\Http\Response::json(404, ['error' => 'Command not found or not owned by this device']);
        }
        return \App\Http\Response::json(200, ['ok' => true]);
    }
);

// POST /dashboard-api/ping-all-devices — on-demand verification of a room's devices.
// F92: la rama se decide por transporte. Zigbee nunca toca Tuya; la cámara usa
// sonda RTSP solo si el cliente envía verify_cameras=true.
// Body: {"device_ids":[<int>,...],"verify_tuya":true,"verify_cameras":true}
$router->post(
    '/dashboard-api/ping-all-devices',
    function (\App\Http\Request $request) use ($pdo, $deviceRepo, $deviceStatusFactory, $commandQueueRepo, $resolveOwnerChip): \App\Http\Response {
        $body = $request->jsonBody ?? [];
        $ids = $body['device_ids'] ?? null;
        // Solo se sondea Tuya cloud (consume cuota) cuando el cliente lo pide
        // explícitamente (carga del panel / botón "Comprobar"). Sin la flag no se
        // quema cuota: los dispositivos Tuya se devuelven como 'unknown' (sin verificar).
        $verifyTuya = !empty($body['verify_tuya']);
        // La sonda de cámara también es bajo demanda y con timeout acotado.
        $verifyCameras = !empty($body['verify_cameras']);
        if (!is_array($ids) || $ids === []) {
            return \App\Http\Response::json(400, ['error' => 'device_ids array required']);
        }

        $results = [];
        foreach ($ids as $rawId) {
            $deviceId = (int) $rawId;
            $row = loadDeviceLivenessRow($pdo, $deviceId);
            if ($row === null) {
                $results[] = [
                    'device_id' => $deviceId,
                    'online' => null,
                    'state' => 'unknown',
                    'found' => false,
                    '_pending_check' => false,
                ];
                continue;
            }

            $row['integration_status'] = $deviceStatusFactory()->forRow($row);
            $category = deviceCategory($row);
            $kind = (string) $row['kind'];

            if ($category === DeviceLivenessService::CATEGORY_CAMERA) {
                $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
                $enabled = ($meta['enabled'] ?? true) !== false;
                $rtsp = is_string($meta['rtsp_url'] ?? null) ? trim((string) $meta['rtsp_url']) : '';

                if (!$verifyCameras) {
                    $results[] = [
                        'device_id' => $deviceId,
                        'found' => true,
                        'online' => null,
                        'state' => 'unknown',
                        'checked_via' => 'CAMERA',
                        '_pending_check' => false,
                        'error' => 'no verificado (sonda de cámara omitida)',
                        'liveness_source' => DeviceLivenessService::SOURCE_CAMERA_PROBE,
                        'liveness_reason' => 'not_probed',
                    ];
                    continue;
                }

                $probe = null;
                if ($enabled && $rtsp !== '') {
                    $probe = (new CameraReachabilityProbe())->probe($rtsp);
                    if ($probe !== null) {
                        persistDeviceOnlineProbe($pdo, $deviceId, $probe);
                    }
                }

                $state = $probe === null ? 'unknown' : ($probe ? 'online' : 'offline');
                $error = null;
                if (!$enabled) {
                    $error = 'cámara deshabilitada';
                } elseif ($rtsp === '') {
                    $error = 'cámara sin rtsp_url';
                } elseif ($probe === null) {
                    $error = 'sonda de cámara no concluyente';
                }
                $results[] = [
                    'device_id' => $deviceId,
                    'found' => true,
                    'online' => $probe,
                    'state' => $state,
                    'checked_via' => 'CAMERA',
                    '_pending_check' => false,
                    'error' => $error,
                    'liveness_source' => DeviceLivenessService::SOURCE_CAMERA_PROBE,
                    'liveness_reason' => $state === 'unknown' ? ($error ?? 'not_probed') : null,
                ];
                continue;
            }

            if ($category === DeviceLivenessService::CATEGORY_ZIGBEE) {
                // Zigbee: estado por push/availability persistida; sin red.
                $st = resolveDeviceOnlineState($row, TUYA_PROBE_TTL_SECONDS);
                $results[] = [
                    'device_id' => $deviceId,
                    'found' => true,
                    'online' => $st['online'],
                    'state' => $st['state'],
                    'tuya_cloud' => false,
                    'checked_via' => 'ZIGBEE',
                    '_pending_check' => false,
                    'error' => $st['reason'],
                    'liveness_source' => $st['source'],
                    'liveness_reason' => $st['reason'],
                ];
                continue;
            }

            if ($category === DeviceLivenessService::CATEGORY_TUYA) {
                if (!$verifyTuya) {
                    // Sin verificación explícita → no quemar cuota: estado 'unknown'.
                    $results[] = [
                        'device_id' => $deviceId,
                        'found' => true,
                        'online' => null,
                        'state' => 'unknown',
                        'tuya_cloud' => true,
                        '_pending_check' => false,
                        'checked_via' => 'TUYA',
                        'error' => 'no verificado (sonda Tuya omitida)',
                        'liveness_source' => DeviceLivenessService::SOURCE_TUYA_PROBE,
                        'liveness_reason' => 'not_probed',
                    ];
                    continue;
                }
                // Tuya cloud (PRESENCE/PROXIMITY/SWITCH): sonda REAL de conectividad.
                // Consume cuota Tuya → este endpoint solo lo invocan la carga del panel
                // y el botón "Comprobar dispositivos", nunca el poll automático.
                $probe = probeTuyaOnlineOnce($pdo, $deviceId, (string) $row['external_id']);
                if ($probe === null) {
                    $results[] = [
                        'device_id' => $deviceId,
                        'found' => true,
                        'online' => null,
                        'state' => 'unknown',
                        'tuya_cloud' => true,
                        '_pending_check' => false,
                        'checked_via' => 'TUYA',
                        'error' => 'Tuya no pudo confirmar conectividad',
                        'liveness_source' => DeviceLivenessService::SOURCE_TUYA_PROBE,
                        'liveness_reason' => 'no_signal',
                    ];
                } else {
                    $results[] = [
                        'device_id' => $deviceId,
                        'found' => true,
                        'online' => $probe,
                        'state' => $probe ? 'online' : 'offline',
                        'tuya_cloud' => true,
                        '_pending_check' => false,
                        'checked_via' => 'TUYA',
                        'liveness_source' => DeviceLivenessService::SOURCE_TUYA_PROBE,
                        'liveness_reason' => null,
                    ];
                }
                continue;
            }

            $isPullKind = in_array($kind, [
                \App\Domain\Devices\Device::KIND_SCANNER,
                \App\Domain\Devices\Device::KIND_LOCK,
            ], true) && $category === DeviceLivenessService::CATEGORY_LOCAL;

            if ($isPullKind) {
                // Enqueue a check for the owner chip (fallback: this row's external_id).
                $owner = $resolveOwnerChip($deviceRepo, $row['pack_id'] !== null ? (int) $row['pack_id'] : null, (string) $row['external_id']);
                $queuedId = null;
                if ($owner !== null) {
                    $queuedId = $commandQueueRepo->enqueue($owner, 'check');
                }
                $results[] = [
                    'device_id' => $deviceId,
                    'online' => false,          // pending live confirmation
                    'state' => 'unknown',
                    'found' => true,
                    '_pending_check' => $queuedId !== null,
                    'checked_via' => 'PULL',
                    'external_id' => $owner,
                    'liveness_source' => DeviceLivenessService::SOURCE_ESP32_HEARTBEAT,
                    'liveness_reason' => 'pending_check',
                ];
                continue;
            }

            if ($category === DeviceLivenessService::CATEGORY_LOCAL) {
                // RPI/ESP32: estado desde el heartbeat persistido (sin red).
                $st = resolveDeviceOnlineState($row, TUYA_PROBE_TTL_SECONDS);
                $results[] = [
                    'device_id' => $deviceId,
                    'online' => $st['online'],
                    'state' => $st['state'],
                    'found' => true,
                    '_pending_check' => false,
                    'checked_via' => 'HEARTBEAT',
                    'liveness_source' => $st['source'],
                    'liveness_reason' => $st['reason'],
                ];
                continue;
            }

            $results[] = [
                'device_id' => $deviceId,
                'online' => null,
                'state' => 'unknown',
                'found' => true,
                '_pending_check' => false,
                'checked_via' => 'NONE',
                'error' => 'transporte sin sonda',
                'liveness_source' => DeviceLivenessService::SOURCE_NONE,
                'liveness_reason' => 'unknown_transport',
            ];
        }

        return \App\Http\Response::json(200, ['ok' => true, 'results' => $results]);
    }
);

// POST /dashboard-api/check-device — single-device guard. SCANNER/LOCK must use
// ping-all-devices (pull model), so they are rejected here with a clear 400.
$router->post(
    '/dashboard-api/check-device',
    function (\App\Http\Request $request) use ($pdo, $deviceRepo): \App\Http\Response {
        $body = $request->jsonBody ?? [];
        $deviceId = isset($body['device_id']) ? (int) $body['device_id'] : 0;
        if ($deviceId <= 0) {
            return \App\Http\Response::json(400, ['error' => 'device_id required']);
        }
        $dev = $deviceRepo->findById($deviceId);
        if ($dev === null) {
            return \App\Http\Response::json(404, ['error' => 'Device not found']);
        }
        if (in_array($dev->kind, [
            \App\Domain\Devices\Device::KIND_SCANNER,
            \App\Domain\Devices\Device::KIND_LOCK,
        ], true)) {
            return \App\Http\Response::json(400, ['error' => $dev->kind . ' is pull-based; use ping-all-devices']);
        }
        return \App\Http\Response::json(200, [
            'ok' => true,
            'device_id' => $deviceId,
            'online' => true,
            'checked_via' => 'PULL',
        ]);
    }
);

// POST /dashboard-api/battery-refresh — ask Tuya for the device's battery and
// persist it (F36). Body: {"device_id":<int>}
// Tuya connectivity errors map to 502 (dashboard/tests treat it as "unavailable").
$router->post(
    '/dashboard-api/battery-refresh',
    function (\App\Http\Request $request) use ($pdo, $deviceRepo): \App\Http\Response {
        $body = $request->jsonBody ?? [];
        $deviceId = isset($body['device_id']) ? (int) $body['device_id'] : 0;
        if ($deviceId <= 0) {
            return \App\Http\Response::json(400, ['error' => 'device_id required']);
        }
        $dev = $deviceRepo->findById($deviceId);
        if ($dev === null) {
            return \App\Http\Response::json(404, ['error' => 'Device not found']);
        }
        // F92: la batería solo se consulta a Tuya para dispositivos Tuya.
        if (!deviceIsTuyaCloud(['kind' => $dev->kind, 'transport' => $dev->transport])) {
            return \App\Http\Response::json(400, ['error' => 'not a Tuya cloud device']);
        }

        $res = tuyaPresenceApi('GET', '/v1.0/iot-03/devices/' . rawurlencode($dev->externalId) . '/status', null);
        if (($res['error'] ?? null) !== null || (int) ($res['http'] ?? 0) >= 500) {
            return \App\Http\Response::json(502, ['error' => $res['error'] ?? 'Tuya API unavailable']);
        }
        if (($res['data']['success'] ?? false) !== true) {
            return \App\Http\Response::json(502, ['error' => $res['data']['msg'] ?? 'Tuya status API failed']);
        }

        $batteryPct = null;
        foreach (($res['data']['result'] ?? []) as $dp) {
            if (!is_array($dp)) {
                continue;
            }
            $code  = strtolower((string) ($dp['code'] ?? ''));
            $value = $dp['value'] ?? null;
            if ($code === 'battery_percentage' && is_numeric($value)) {
                $pct = (int) $value;
                if ($pct >= 0 && $pct <= 100) {
                    $batteryPct = $pct;
                    break;
                }
            }
        }

        if ($batteryPct !== null) {
            $deviceRepo->updateBattery($deviceId, $batteryPct);
        }

        $state = $batteryPct === null ? 'unknown' : ($batteryPct <= 10 ? 'critical' : ($batteryPct <= 20 ? 'low' : 'normal'));
        return \App\Http\Response::json(200, [
            'ok' => true,
            'device_id' => $deviceId,
            'kind' => $dev->kind,
            'external_id' => $dev->externalId,
            'battery_pct' => $batteryPct,
            'state' => $state,
        ]);
    }
);

// GET /dashboard-api/tuya-quota — read-only IoT Core credit probe (diagnóstico).
// Makes exactly ONE read-only call (GET /iot-03/devices/{id}); it NEVER uses the
// /commands endpoint, so the relay is not touched. Query: optional
// ?external_id=<tuya_device_id>. Without it, picks the first SWITCH device,
// else the first PRESENCE/PROXIMITY; 404 when there is no Tuya device at all.
// 200 for any valid request (even when Tuya is unreachable → credits=unknown);
// 400 for invalid params; 404 when the requested/no candidate device is missing.
$router->get(
    '/dashboard-api/tuya-quota',
    function (\App\Http\Request $request) use ($deviceRepo): \App\Http\Response {
        $requested = $request->query('external_id');
        if ($requested !== null && trim($requested) === '') {
            return \App\Http\Response::json(400, ['error' => 'external_id must not be empty']);
        }

        if ($requested !== null) {
            $dev = $deviceRepo->findByExternalId(trim($requested));
            if ($dev === null) {
                return \App\Http\Response::json(404, ['error' => 'Device not found']);
            }
            if (!deviceIsTuyaCloud(['kind' => $dev->kind, 'transport' => $dev->transport])) {
                return \App\Http\Response::json(400, ['error' => 'external_id is not a Tuya cloud device']);
            }
        } else {
            $dev      = null;
            $fallback = null;
            foreach ($deviceRepo->findAll() as $candidate) {
                if (!deviceIsTuyaCloud(['kind' => $candidate->kind, 'transport' => $candidate->transport])) {
                    continue;
                }
                if ($candidate->kind === \App\Domain\Devices\Device::KIND_SWITCH) {
                    $dev = $candidate;
                    break;
                }
                if ($fallback === null && in_array($candidate->kind, [
                    \App\Domain\Devices\Device::KIND_PRESENCE,
                    \App\Domain\Devices\Device::KIND_PROXIMITY,
                ], true)) {
                    $fallback = $candidate;
                }
            }
            $dev = $dev ?? $fallback;
            if ($dev === null) {
                return \App\Http\Response::json(404, ['error' => 'No Tuya device available to probe']);
            }
        }

        $externalId = (string) $dev->externalId;
        if ($externalId === '') {
            return \App\Http\Response::json(400, ['error' => 'Device has no external_id']);
        }

        // Única llamada, SOLO LECTURA (device details). `ignoreLocalBudget=true`
        // permite diagnosticar aunque el backoff local esté activo; la llamada se
        // cuenta igual y una respuesta de cuota refresca el backoff compartido.
        $res  = tuyaPresenceApi('GET', '/v1.0/iot-03/devices/' . rawurlencode($externalId), null, true);
        $http = (int) ($res['http'] ?? 0);
        $data = is_array($res['data'] ?? null) ? $res['data'] : [];

        $classification = \App\Domain\Devices\TuyaCreditsClassifier::classifyTuyaCredits($http, $data);

        $deviceOnline = null;
        if (($data['success'] ?? false) === true
            && array_key_exists('online', (array) ($data['result'] ?? []))) {
            $deviceOnline = (bool) $data['result']['online'];
        }

        $q            = tuyaQuotaRead();
        $backoffUntil = (int) ($q['backoffUntil'] ?? 0);
        $meta         = is_array($dev->meta) ? $dev->meta : [];
        $lastError    = isset($meta['last_error_category']) ? [
            'category' => (string) $meta['last_error_category'],
            'at'       => isset($meta['last_error_at']) ? (string) $meta['last_error_at'] : null,
        ] : null;

        $payload = [
            'credits'            => $classification['credits'],
            'category'           => $classification['category'],
            'http'               => $http,
            'code'               => $data['code'] ?? null,
            'msg'                => $data['msg'] ?? null,
            'device_external_id' => $externalId,
            'device_kind'        => (string) $dev->kind,
            'device_online'      => $deviceOnline,
            'elapsed_ms'         => (int) ($res['elapsed_ms'] ?? 0),
            'checked_at'         => gmdate('Y-m-d\TH:i:s\Z'),
            'local_budget'       => [
                'calls_day'      => (int) ($q['callsDay'] ?? 0),
                'calls_hour'     => (int) ($q['callsHour'] ?? 0),
                'backoff_until'  => $backoffUntil,
                'backoff_active' => $backoffUntil > time(),
            ],
            'last_known_error'   => $lastError,
        ];
        // Only present when the probe itself failed (curl/token): keeps the
        // documented happy-path/quota payload shape unchanged.
        if (($res['error'] ?? null) !== null) {
            $payload['probe_error'] = (string) $res['error'];
        }

        return \App\Http\Response::json(200, $payload);
    }
);

$roomController = new RoomController($roomService, $roomStateService, $deviceService, $deviceStatusFactory());

$stayRepo = new StayRepository($pdo);
$stayService = new StayService($stayRepo);
$stayStateMachine = new StayStateMachine($stayRepo);
// Note: $stayController is wired after $outboxRepo is available (see below)

$qrCredRepo = new QrCredentialRepository($pdo);
$qrIssueService = new QrIssueService(
    $roomRepo,
    $roomTypeRepo,
    $stayRepo,
    $timeSlotService,
    $qrCredRepo,
    $qrTokenizer,
    $roomCycleResetter
);

$accessEventRepo  = new AccessEventRepository($pdo);

// --- F38: Worker QR Validate ---
// F62/RF-69: per-worker ALLOW/DENY exception wins over the role grant.
$workerRoomOverrideRepo = new WorkerRoomOverrideRepository($pdo);
$warehouseAccessPolicy  = new WarehouseAccessPolicy($workerRoomOverrideRepo, $workerRoleRepo);
$warehouseRecordingService = new \App\Domain\Warehouse\WarehouseRecordingService($pdo);
$accessKeyRepo       = new \App\Domain\AccessKeys\AccessKeyRepository($pdo);
$nfcCardRepo         = new \App\Domain\AccessKeys\NfcCardRepository($pdo);
$nfcCardService      = new \App\Domain\AccessKeys\NfcCardService($nfcCardRepo);
$nfcCardController   = new NfcCardController($nfcCardService);
$keyDeskController   = new KeyDeskController($nfcCardRepo);
$accessKeyService    = new \App\Domain\AccessKeys\AccessKeyService(
    $accessKeyRepo,
    $nfcCardRepo,
    $workerRepoForRoles,
    $workerRoleRepo,
    $workerSessionRepo,
    $roomRepo,
    $roomTypeRepo,
    $deviceRepo,
    $accessEventRepo,
    $qrTokenizer,
    $pdo,
    $warehouseRecordingService
);
$accessKeyController = new AccessKeyController($accessKeyService);
$workerQrService     = new \App\Domain\Workers\WorkerQrService(
    $qrTokenizer,
    $workerRepoForRoles,
    $workerRoleRepo,
    $workerSessionRepo,
    $roomRepo,
    $roomTypeRepo,
    $accessEventRepo,
    $warehouseAccessPolicy,
    $warehouseRecordingService
);
$workerQrController  = new WorkerQrController($workerQrService, $accessKeyService, $qrTokenizer);

$lockGateway      = LockGatewayFactory::make(null, $deviceRepo); // global SIMULATED_MODE; per-room resolved inside service
$qrValidateService = new QrValidateService(
    $qrTokenizer,
    $qrCredRepo,
    $roomRepo,
    $deviceRepo,
    $stayRepo,
    $stayStateMachine,
    $lockGateway,
    $accessEventRepo,
    $iotSessionRepo = new IotSessionRepository($pdo),   // F21: sync IoT state on QR open
    $switchService                                       // RF-16: turn on light on QR open
);
$qrController = new QrController($qrIssueService, $qrValidateService, $qrCredRepo, $accessKeyService, $qrTokenizer);

$lockService    = new LockService($roomRepo, $accessEventRepo);
$lockController = new LockController($lockService);

$presenceEventRepo = new PresenceEventRepository($pdo);
// $iotSessionRepo already created above (needed by QrValidateService for dashboard)

// --- F35: Anomaly detection wiring ---
$anomalyRepo       = new AnomalyRepository($pdo);

// Read enabled anomaly types from system_settings (default: all enabled)
$anomalyEnabledTypes = ['A1','A2','A3','A4','A5','A6','A7','A8'];
$settingRow = $pdo->query("SELECT value FROM system_settings WHERE service='api' AND setting_key='anomaly.enabled_types'")->fetch(PDO::FETCH_ASSOC);
if ($settingRow && !empty($settingRow['value'])) {
    $decoded = json_decode($settingRow['value'], true);
    if (is_array($decoded)) $anomalyEnabledTypes = $decoded;
}
$enabled = function(string $type) use ($anomalyEnabledTypes): bool { return in_array($type, $anomalyEnabledTypes, true); };

$a5Detector        = new ExitWithoutDoorOpen();
$a1Detector        = new PresenceWithoutDoorOpen($pdo);
$a2Detector        = new PresenceWithoutStay();
$a3Detector        = new DoorOpenWithoutQr($pdo);
$a6Detector        = new PresenceAfterExit();
$a8Detector        = new SensorFlapping($pdo);

$eventDetectors = [];
if ($enabled('A1')) $eventDetectors[] = $a1Detector;
if ($enabled('A2')) $eventDetectors[] = $a2Detector;
if ($enabled('A3')) $eventDetectors[] = $a3Detector;
if ($enabled('A6')) $eventDetectors[] = $a6Detector;
if ($enabled('A8')) $eventDetectors[] = $a8Detector;

$anomalyPipeline   = new AnomalyPipeline($eventDetectors);
$anomalyService    = new AnomalyService($anomalyPipeline, $anomalyRepo, $a5Detector);
$anomalyController = new AnomalyController($anomalyService);

$iotSessionService = IotSessionServiceFactory::create(
    $presenceEventRepo,
    $iotSessionRepo,
    $roomRepo,
    $roomTypeRepo,
    $stayRepo,
    $stayStateMachine,
    $accessEventRepo,
    $switchService,
    $anomalyService,
    $workerSessionRepo,
    $warehouseRecordingService
);
// POST /presence/events always uses SimulatedSensorIngress (canonical format:
// room_id, sensor, value). Tuya push comes via POST /api/v1/tuya/webhook.
$simulatedIngress     = new SimulatedSensorIngress();
$presenceController   = new PresenceController($iotSessionService, $simulatedIngress);
$simController        = new SimController($iotSessionService, $lockService, $roomRepo, $simulatedIngress);

$debtRepo        = new DebtRepository($pdo);
$outboxRepo      = new OutboxVb6Repository($pdo);
$overstayCalc    = new OverstayCalculator();
$debtsService    = new DebtsService($debtRepo, $stayRepo, $stayStateMachine, $overstayCalc, $outboxRepo, $pdo);
$overstayCtrl    = new OverstayController($stayRepo, $roomRepo, $roomTypeRepo, $overstayCalc);
$debtCtrl        = new DebtController($debtRepo, $debtsService);

// StayController wired here to inject outboxRepo (TSK-153) + switchService (RF-16)
$stayController  = new StayController($stayService, $stayStateMachine, $outboxRepo, $switchService);

// RoomLiveController for public dashboard (F21, TSK-230)
$roomLiveController = new \App\Http\Controllers\RoomLiveController(
    $roomRepo, $iotSessionRepo, $presenceEventRepo, $stayRepo, $pdo, $deviceRepo, $roomTypeRepo, $anomalyRepo
);
// Replace the dummy /live route placeholder with the real handler.
// Wrap to add Cache-Control headers preventing browser/proxy caching,
// which was the main cause of stale dashboard data despite fast polling.
$router->get('/api/v1/rooms/{id}/live', function (\App\Http\Request $request) use ($roomLiveController): \App\Http\Response {
    return $roomLiveController->show($request)
        ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
        ->withHeader('Pragma', 'no-cache')
        ->withHeader('Expires', '0');
});

// --- Routes: Room Types ---
$router->get(
    '/api/v1/room-types',
    [$roomTypeController, 'index'],
    $authFactory(['rooms:read'])
);
$router->get(
    '/api/v1/room-types/{id}',
    [$roomTypeController, 'show'],
    $authFactory(['rooms:read'])
);
$router->post(
    '/api/v1/room-types',
    [$roomTypeController, 'create'],
    $authFactory(['rooms:write'])
);
$router->patch(
    '/api/v1/room-types/{id}',
    [$roomTypeController, 'update'],
    $authFactory(['rooms:write'])
);
$router->delete(
    '/api/v1/room-types/{id}',
    [$roomTypeController, 'delete'],
    $authFactory(['rooms:write'])
);

// --- Routes: Time Slots ---
$router->get(
    '/api/v1/room-types/{id}/time-slots',
    [$timeSlotController, 'index'],
    $authFactory(['time-slots:read'])
);
$router->put(
    '/api/v1/room-types/{id}/time-slots',
    [$timeSlotController, 'put'],
    $authFactory(['time-slots:write'])
);

// --- Routes: Rooms ---
// Specific routes MUST come before parameterized /rooms/{id}
// Identified rooms ("mírame") — for UI panel
$router->get(
    '/api/v1/rooms/identified',
    [$roomController, 'identified'],
    $authFactory(['rooms:read'])
);
$router->get(
    '/api/v1/rooms',
    [$roomController, 'index'],
    $authFactory(['rooms:read'])
);
$router->get(
    '/api/v1/rooms/{id}',
    [$roomController, 'show'],
    $authFactory(['rooms:read'])
);
$router->post(
    '/api/v1/rooms',
    [$roomController, 'create'],
    $authFactory(['rooms:write'])
);
$router->patch(
    '/api/v1/rooms/{id}',
    [$roomController, 'update'],
    $authFactory(['rooms:write'])
);
$router->patch(
    '/api/v1/rooms/{id}/state',
    [$roomController, 'updateState'],
    $authFactory(['rooms:state'])
);
$router->delete(
    '/api/v1/rooms/{id}',
    [$roomController, 'delete'],
    $authFactory(['rooms:write'])
);

// --- Routes: Devices ---
$router->get(
    '/api/v1/devices',
    [$deviceController, 'index'],
    $authFactory(['rooms:read'])
);
$router->get(
    '/api/v1/rooms/{id}/devices',
    [$deviceController, 'listForRoom'],
    $authFactory(['rooms:read'])
);
$router->post(
    '/api/v1/rooms/{id}/devices',
    [$deviceController, 'create'],
    $authFactory(['rooms:write'])
);
$router->patch(
    '/api/v1/devices/{id}',
    [$deviceController, 'update'],
    $authFactory(['rooms:write'])
);
$router->delete(
    '/api/v1/devices/{id}',
    [$deviceController, 'delete'],
    $authFactory(['rooms:write'])
);
// Convenience route: POST /api/v1/devices/register (room_id in body)
$router->post(
    '/api/v1/devices/register',
    [$deviceController, 'register'],
    $authFactory(['rooms:write'])
);

// Identify button ("mírame") — called by ESP32
// Uses qr:validate scope (same as /qr/validate — RPI/ESP32 devices already have this)
$router->post(
    '/api/v1/devices/identify',
    [$deviceController, 'identify'],
    $authFactory(['qr:validate'])
);

// Telemetría de lecturas QR que el firmware descartó antes de validar (FIX #1).
// El ESP32 manda len/dots/hex de la lectura rechazada para diagnóstico.
$router->post(
    '/api/v1/devices/qr-rejected',
    [$qrRejectionController, 'store'],
    $authFactory(['qr:validate'])
);

// Admin force-unidentify
$router->delete(
    '/api/v1/devices/{id}/identify',
    [$deviceController, 'unidentify'],
    $authFactory(['rooms:write'])
);

// --- Routes: Device Packs (F25, RF-17) ---
$router->get(
    '/api/v1/device-packs',
    [$devicePackController, 'index'],
    $authFactory(['rooms:read'])
);
$router->get(
    '/api/v1/device-packs/{id}',
    [$devicePackController, 'show'],
    $authFactory(['rooms:read'])
);
$router->post(
    '/api/v1/device-packs',
    [$devicePackController, 'create'],
    $authFactory(['rooms:write'])
);
$router->put(
    '/api/v1/device-packs/{id}',
    [$devicePackController, 'update'],
    $authFactory(['rooms:write'])
);
$router->delete(
    '/api/v1/device-packs/{id}',
    [$devicePackController, 'delete'],
    $authFactory(['rooms:write'])
);
$router->post(
    '/api/v1/rooms/{id}/apply-pack/{pack_id}',
    [$devicePackController, 'apply'],
    $authFactory(['rooms:write'])
);

// --- Routes: Switches (Smart Electricity Protector EAWCBT-J, RF-16) ---
$router->get(
    '/api/v1/rooms/{room_id}/switches',
    [$switchController, 'getSwitches'],
    $authFactory(['rooms:read'])
);
$router->post(
    '/api/v1/switches/{room_id}/on',
    [$switchController, 'turnOn'],
    $authFactory(['switches:write'])
);
$router->post(
    '/api/v1/switches/{room_id}/off',
    [$switchController, 'turnOff'],
    $authFactory(['switches:write'])
);

// --- Routes: QR ---
$router->post(
    '/api/v1/qr',
    [$qrController, 'issue'],
    array_merge(
        $authFactory(['qr:issue']),
        [$idempotencyFactory('qr.issue')]
    )
);
$router->post(
    '/api/v1/qr/validate',
    [$qrController, 'validate'],
    $authFactory(['qr:validate'])
    // Idempotency-Key is optional (contracts §2.4: "opcional pero recomendado")
);
$router->post(
    '/api/v1/qr/{jti}/revoke',
    [$qrController, 'revoke'],
    array_merge(
        $authFactory(['qr:revoke']),
        [$idempotencyFactory('qr.revoke')]
    )
);

// --- Routes: Overstay + Debts ---
$router->get(
    '/api/v1/stays/{id}/overstay',
    [$overstayCtrl, 'show'],
    $authFactory(['stays:read'])
);
$router->get(
    '/api/v1/debts',
    [$debtCtrl, 'index'],
    $authFactory(['debts:read'])
);
$router->get(
    '/api/v1/debts/{id}',
    [$debtCtrl, 'show'],
    $authFactory(['debts:read'])
);
$router->post(
    '/api/v1/debts/{id}/resync',
    [$debtCtrl, 'resync'],
    $authFactory(['debts:sync'])
);

// --- Routes: Presence ---
$router->post(
    '/api/v1/presence/events',
    [$presenceController, 'ingest'],
    array_merge(
        $authFactory(['presence:write']),
        [$idempotencyFactory('presence.events')]
    )
);
$router->get(
    '/api/v1/rooms/{id}/presence',
    [$presenceController, 'getPresence'],
    $authFactory(['presence:read'])
);

// --- Routes: Anomalies (F35) ---
$router->get(
    '/api/v1/anomalies',
    [$anomalyController, 'index'],
    $authFactory(['audit:read'])
);
$router->post(
    '/api/v1/anomalies/{id}/acknowledge',
    [$anomalyController, 'acknowledge'],
    $authFactory(['audit:read'])
);
$router->post(
    '/api/v1/anomalies/{id}/dismiss',
    [$anomalyController, 'dismiss'],
    $authFactory(['audit:read'])
);

// --- Routes: Locks ---
$router->post(
    '/api/v1/locks/{room_id}/open',
    [$lockController, 'open'],
    array_merge(
        $authFactory(['locks:open']),
        [$idempotencyFactory('locks.open')]
    )
);
$router->post(
    '/api/v1/locks/{room_id}/lock',
    [$lockController, 'lock'],
    array_merge(
        $authFactory(['locks:lock']),
        [$idempotencyFactory('locks.lock')]
    )
);

// --- Routes: Stays ---
$router->get(
    '/api/v1/stays',
    [$stayController, 'index'],
    $authFactory(['stays:read'])
);
$router->get(
    '/api/v1/stays/{id}',
    [$stayController, 'show'],
    $authFactory(['stays:read'])
);
$router->patch(
    '/api/v1/stays/{id}/vb6-refs',
    [$stayController, 'patchVb6Refs'],
    $authFactory(['stays:write'])
);
$router->post(
    '/api/v1/stays/{id}/close',
    [$stayController, 'close'],
    array_merge(
        $authFactory(['stays:write']),
        [$idempotencyFactory('stays.close')]
    )
);

// --- Routes: Sim (F15 — /sim/*) — scope: sim:* ---
$router->post(
    '/sim/rooms/{id}/presence',
    [$simController, 'presence'],
    array_merge(
        $authFactory(['sim:*']),
        [$idempotencyFactory('sim.presence', false)]
    )
);
$router->post(
    '/sim/rooms/{id}/door',
    [$simController, 'door'],
    array_merge(
        $authFactory(['sim:*']),
        [$idempotencyFactory('sim.door', false)]
    )
);
$router->post(
    '/sim/rooms/{id}/lock/ack',
    [$simController, 'lockAck'],
    array_merge(
        $authFactory(['sim:*']),
        [$idempotencyFactory('sim.lock.ack', false)]
    )
);

// --- Dev-only smoke endpoint (kept for TSK-015 verification) ---
if (Config::getBool('APP_DEBUG', false)) {
    $echo = new DevEchoController();
    $router->post(
        '/api/v1/_dev/echo',
        [$echo, 'handle'],
        array_merge(
            $authFactory(['sim:*']),
            [$idempotencyFactory('dev.echo')]
        )
    );
}

// --- Route: Tuya Webhook (F19 — Tuya IoT push notifications) ---
// Uses TuyaSensorIngress directly (always real, regardless of SIMULATED_MODE).
// No auth middleware — Tuya Cloud doesn't use our API key scheme.
$tuyaIngress          = new TuyaSensorIngress($deviceRepo);
$tuyaWebhookController = new TuyaWebhookController($iotSessionService, $tuyaIngress);
$router->post('/api/v1/tuya/webhook', [$tuyaWebhookController, 'ingest']);

// --- Routes: QR List (CRM/F27) ---
$router->get('/api/v1/qr', [$qrController, 'list'], $authFactory(['rooms:read']));

// --- Routes: Admin (access events, presence events, outbox) ---
$adminController = new AdminController($pdo);
$router->get('/api/v1/admin/access-events',    [$adminController, 'listAccessEvents'],    $authFactory(['rooms:read']));
$router->get('/api/v1/admin/presence-events',  [$adminController, 'listPresenceEvents'],  $authFactory(['rooms:read']));
$router->get('/api/v1/admin/outbox',           [$adminController, 'listOutbox'],          $authFactory(['audit:read']));
$router->post('/api/v1/admin/outbox/{id}/retry', [$adminController, 'retryOutbox'],       $authFactory(['audit:read']));

// --- Routes: Admin API Clients ---
$adminApiClientController = new AdminApiClientController(new ApiClientRepository($pdo), $pdo);
$router->get('/api/v1/admin/api-clients',              [$adminApiClientController, 'index'],      $authFactory(['audit:read']));
$router->get('/api/v1/admin/api-clients/{id}',         [$adminApiClientController, 'show'],       $authFactory(['audit:read']));
$router->post('/api/v1/admin/api-clients',             [$adminApiClientController, 'create'],     $authFactory(['audit:read']));
$router->patch('/api/v1/admin/api-clients/{id}',       [$adminApiClientController, 'update'],     $authFactory(['audit:read']));
$router->delete('/api/v1/admin/api-clients/{id}',      [$adminApiClientController, 'delete'],     $authFactory(['audit:read']));
$router->post('/api/v1/admin/api-clients/{id}/rotate-key', [$adminApiClientController, 'rotateKey'], $authFactory(['audit:read']));

// --- Routes: Admin Settings ---
$adminSettingsController = new \App\Http\Controllers\AdminSettingsController($pdo);
$router->get('/api/v1/admin/settings',  [$adminSettingsController, 'show'],    $authFactory(['audit:read']));
$router->put('/api/v1/admin/settings',  [$adminSettingsController, 'update'],  $authFactory(['audit:read']));
$router->post('/api/v1/admin/settings/sync-env', [$adminSettingsController, 'syncEnv'], $authFactory(['audit:read']));

// --- Routes: Worker Roles (F38) ---
$router->get('/api/v1/worker-roles',       [$workerRoleController, 'list'],   $authFactory(['worker-roles:read']));
$router->post('/api/v1/worker-roles',      [$workerRoleController, 'create'], $authFactory(['worker-roles:write']));
$router->get('/api/v1/worker-roles/{id}',  [$workerRoleController, 'show'],   $authFactory(['worker-roles:read']));
$router->patch('/api/v1/worker-roles/{id}', [$workerRoleController, 'update'], $authFactory(['worker-roles:write']));
$router->delete('/api/v1/worker-roles/{id}', [$workerRoleController, 'delete'], $authFactory(['worker-roles:write']));

// --- Routes: Workers (F38) ---
// GET /workers/inside MUST come before /workers/{id} to avoid route collision
$router->get('/api/v1/workers/inside',       [$workerController, 'inside'],        $authFactory(['workers:read']));
$router->get('/api/v1/workers',              [$workerController, 'list'],          $authFactory(['workers:read']));
$router->post('/api/v1/workers',             [$workerController, 'create'],        $authFactory(['workers:write']));
$router->get('/api/v1/workers/{id}',         [$workerController, 'show'],          $authFactory(['workers:read']));
$router->patch('/api/v1/workers/{id}',        [$workerController, 'update'],        $authFactory(['workers:write']));
$router->delete('/api/v1/workers/{id}',       [$workerController, 'deactivate'],    $authFactory(['workers:write']));
$router->post('/api/v1/workers/{id}/qr',    [$workerController, 'regenerateQr'],  $authFactory(['workers:write']));
$router->get('/api/v1/workers/{id}/sessions', [$workerController, 'sessions'],      $authFactory(['workers:read']));
$router->post('/api/v1/workers/qr/validate',  [$workerQrController, 'validate'],   $authFactory(['qr:validate']));

// --- Routes: Llaves de acceso y fichas NFC (F96) ---
// Static/specific paths MUST come before /{id}.
$router->get('/api/v1/keys/defaults',              [$accessKeyController, 'defaults'],      $authFactory(['keys:read']));
$router->get('/api/v1/keys/accesses',              [$accessKeyController, 'accesses'],      $authFactory(['audit:read']));
$router->post('/api/v1/keys/validate',             [$accessKeyController, 'validate'],      $authFactory(['qr:validate']));
$router->get('/api/v1/keys',                       [$accessKeyController, 'list'],          $authFactory(['keys:read']));
$router->post('/api/v1/keys',                      [$accessKeyController, 'create'],        $authFactory(['keys:write']));
$router->get('/api/v1/keys/{id}',                  [$accessKeyController, 'show'],          $authFactory(['keys:read']));
$router->patch('/api/v1/keys/{id}',                [$accessKeyController, 'update'],        $authFactory(['keys:write']));
$router->delete('/api/v1/keys/{id}',               [$accessKeyController, 'revoke'],        $authFactory(['keys:write']));
$router->post('/api/v1/keys/{id}/revoke',          [$accessKeyController, 'revoke'],        $authFactory(['keys:write']));
$router->post('/api/v1/keys/{id}/reactivate',      [$accessKeyController, 'reactivate'],    $authFactory(['keys:write']));
$router->post('/api/v1/keys/{id}/qr',              [$accessKeyController, 'regenerateQr'],  $authFactory(['keys:write']));
$router->get('/api/v1/keys/{id}/accesses',         [$accessKeyController, 'keyAccesses'],   $authFactory(['audit:read']));

// F98/RF-182.1: fichas disponibles para el asistente de /llaves (solo id/number).
$router->get('/api/v1/llaves/nfc-available',       [$keyDeskController, 'nfcAvailable'],    $authFactory(['keys:read']));

$router->get('/api/v1/nfc-cards',                  [$nfcCardController, 'list'],            $authFactory(['nfc-cards:read']));
$router->post('/api/v1/nfc-cards',                 [$nfcCardController, 'create'],          $authFactory(['nfc-cards:write']));
$router->get('/api/v1/nfc-cards/{id}',             [$nfcCardController, 'show'],            $authFactory(['nfc-cards:read']));
$router->patch('/api/v1/nfc-cards/{id}',           [$nfcCardController, 'update'],          $authFactory(['nfc-cards:write']));
$router->delete('/api/v1/nfc-cards/{id}',          [$nfcCardController, 'disable'],         $authFactory(['nfc-cards:write']));

// --- Routes: Permisos del almacén (F62/RF-69.5, público LAN) ---
$warehouseAccessController = new WarehouseAccessController($pdo, $warehouseAccessPolicy);
$router->get('/almacen-api/access',            [$warehouseAccessController, 'index']);
$router->put('/almacen-api/access/role/{id}',  [$warehouseAccessController, 'setRole']);
$router->put('/almacen-api/access/worker/{id}',[$warehouseAccessController, 'setWorker']);

// --- Routes: Visitas del almacén (F63/RF-70.3, público LAN) ---
$warehouseVisitController = new WarehouseVisitController($pdo);
$router->get('/almacen-api/visits',      [$warehouseVisitController, 'index']);
$router->get('/almacen-api/visits/{id}', [$warehouseVisitController, 'show']);

// --- Routes: servido de clips (F64/RF-73.4, público LAN) ---
$warehouseRecordingController = new WarehouseRecordingController($pdo);
$router->get('/almacen-api/recordings/{id}/video',  [$warehouseRecordingController, 'video']);
$router->get('/almacen-api/recordings/{id}/poster', [$warehouseRecordingController, 'poster']);

// --- Routes: cámaras del almacén (F61/RF-72, público LAN) ---
$go2rtcClient = new \App\Domain\Warehouse\Go2rtcClient((string) (Config::get('GO2RTC_BASE_URL', '') ?? ''));
$warehouseCameraController = new WarehouseCameraController($pdo, $deviceService, $go2rtcClient);
$router->get('/almacen-api/cameras',            [$warehouseCameraController, 'index']);
$router->post('/almacen-api/cameras',           [$warehouseCameraController, 'create']);
$router->patch('/almacen-api/cameras/{id}',     [$warehouseCameraController, 'update']);
$router->delete('/almacen-api/cameras/{id}',    [$warehouseCameraController, 'delete']);
$router->post('/almacen-api/cameras/sync',      [$warehouseCameraController, 'sync']);

// POST /almacen-api/motion — F88/RF-123.2: el worker de movimiento de cámaras
// publica que una cámara del almacén ha detectado movimiento. Uso interno
// (loopback/LAN, sin auth en el MVP). Solo BD local; sin cuota Tuya.
$router->post(
    '/almacen-api/motion',
    function (\App\Http\Request $request) use ($pdo): \App\Http\Response {
        // F91/RF-151.3: no aceptar movimiento arbitrario desde la LAN.
        // - Con ALMACEN_MOTION_TOKEN configurado, se exige el header secreto.
        // - Sin token, solo se admite una petición directa desde loopback y sin
        //   cabeceras de proxy (si hay reverse proxy, configura el token).
        $motionToken = (string) (\App\Support\Config::get('ALMACEN_MOTION_TOKEN', '') ?? '');
        if ($motionToken !== '') {
            $given = (string) ($request->header('x-almacen-motion-token') ?? '');
            if ($given === '' || !hash_equals($motionToken, $given)) {
                return \App\Http\Response::json(403, ['ok' => false, 'error' => 'motion_forbidden']);
            }
        } else {
            $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $proxied = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')
                . (string) ($_SERVER['HTTP_X_REAL_IP'] ?? '');
            if (!in_array($remote, ['127.0.0.1', '::1'], true) || $proxied !== '') {
                return \App\Http\Response::json(403, ['ok' => false, 'error' => 'motion_auth_required']);
            }
        }

        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        $deviceId = (int) ($body['device_id'] ?? ($request->query['device_id'] ?? 0));
        if ($deviceId <= 0) {
            return \App\Http\Response::json(400, ['ok' => false, 'error' => 'device_id_required']);
        }
        $chk = $pdo->prepare(
            "SELECT d.id FROM devices d
               JOIN rooms r ON r.pack_id = d.pack_id
               JOIN room_types rt ON rt.id = r.room_type_id
              WHERE d.id = :id AND d.kind = 'CAMERA' AND rt.code = 'ALMACEN_BEBIDAS'
              LIMIT 1"
        );
        $chk->execute([':id' => $deviceId]);
        if ($chk->fetchColumn() === false) {
            return \App\Http\Response::json(404, ['ok' => false, 'error' => 'camera_not_found']);
        }
        $pdo->prepare('UPDATE devices SET last_motion_at = UTC_TIMESTAMP(3) WHERE id = :id')
            ->execute([':id' => $deviceId]);
        $sel = $pdo->prepare('SELECT last_motion_at FROM devices WHERE id = :id LIMIT 1');
        $sel->execute([':id' => $deviceId]);
        return \App\Http\Response::json(200, [
            'ok' => true,
            'device_id' => $deviceId,
            'at' => (string) ($sel->fetchColumn() ?: ''),
        ]);
    }
);

// --- Routes: estado del almacén (F65/RF-74.2, público LAN) ---
$warehouseStateController = new WarehouseStateController($pdo, $go2rtcClient);
$router->get('/almacen-api/state', [$warehouseStateController, 'show']);

// POST /almacen-api/door/open — apertura manual desde el panel (F65/RF-74).
$router->post(
    '/almacen-api/door/open',
    function (\App\Http\Request $request) use ($lockService, $warehouseRecordingService, $pdo): \App\Http\Response {
        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        $roomId = (int) ($body['room_id'] ?? 0);
        if ($roomId <= 0) {
            return \App\Http\Response::json(400, ['error' => 'room_id_required']);
        }
        $correlationId = (string) ($request->attr('correlation_id', '') ?? '');
        try {
            $result = $lockService->open($roomId, 'manual_almacen', true, $correlationId);

            // F85/RF-111.4: la apertura manual alimenta el motor del almacén (no
            // depende solo del push del sensor). `panel` no es SIMULATED/resync.
            $visitStarted = false;
            try {
                $before = null;
                if ($warehouseRecordingService->isWarehouseRoom($roomId)) {
                    $q = $pdo->prepare('SELECT current_visit_id FROM warehouse_state WHERE room_id = :r LIMIT 1');
                    $q->execute([':r' => $roomId]);
                    $before = $q->fetchColumn();
                }
                $warehouseRecordingService->onSignal(
                    $roomId,
                    \App\Domain\Warehouse\WarehouseRecordingDecision::EV_DOOR_OPEN,
                    ['provider' => 'PANEL', 'source' => 'panel']
                );
                if ($before !== null && $before !== false) {
                    $q2 = $pdo->prepare('SELECT current_visit_id FROM warehouse_state WHERE room_id = :r LIMIT 1');
                    $q2->execute([':r' => $roomId]);
                    $after = $q2->fetchColumn();
                    $visitStarted = ($after !== false && $after !== null && (string) $after !== (string) $before);
                }
            } catch (\Throwable $e) {
                error_log('[almacen door/open] warehouse signal failed: ' . $e->getMessage());
            }

            return \App\Http\Response::json(200, array_merge(
                ['ok' => true, 'visit_started' => $visitStarted],
                is_array($result) ? $result : []
            ));
        } catch (\Throwable $e) {
            return \App\Http\Response::json(409, ['error' => 'door_error', 'message' => $e->getMessage()]);
        }
    }
);

// POST /almacen-api/switch — control manual de la luz Zigbee (F91/RF-147).
// Misma autorización que door/open (panel LAN). Solo transporte ZIGBEE2MQTT:
// no se usa como puerta trasera hacia Tuya. La confirmación física llega por
// push; aquí solo se confirma la entrega al broker.
$router->post(
    '/almacen-api/switch',
    function (\App\Http\Request $request) use ($deviceRepo, $switchService, $pdo): \App\Http\Response {
        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        $roomId = (int) ($body['room_id'] ?? 0);
        $state = strtoupper(trim((string) ($body['state'] ?? '')));
        if ($roomId <= 0) {
            return \App\Http\Response::json(400, ['error' => 'room_id_required']);
        }
        if (!in_array($state, ['ON', 'OFF'], true)) {
            return \App\Http\Response::json(400, ['error' => 'invalid_state', 'allowed' => ['ON', 'OFF']]);
        }

        $wh = $pdo->prepare(
            "SELECT 1 FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
             WHERE r.id = :id AND rt.code = 'ALMACEN_BEBIDAS' LIMIT 1"
        );
        $wh->execute([':id' => $roomId]);
        if ($wh->fetchColumn() === false) {
            return \App\Http\Response::json(404, ['error' => 'warehouse_room_not_found']);
        }

        $device = $deviceRepo->findForRoomKind($roomId, \App\Domain\Devices\Device::KIND_SWITCH);
        if ($device === null) {
            return \App\Http\Response::json(404, ['error' => 'switch_not_found']);
        }
        if ($device->transport !== \App\Domain\Devices\Device::TRANSPORT_ZIGBEE2MQTT) {
            return \App\Http\Response::json(409, ['error' => 'transport_unsupported']);
        }

        $result = $state === 'ON' ? $switchService->turnOn($roomId) : $switchService->turnOff($roomId);
        if (($result['ok'] ?? false) !== true) {
            return \App\Http\Response::json(503, [
                'ok' => false,
                'state' => $state,
                'error' => (string) ($result['error'] ?? 'switch_error'),
            ]);
        }

        $fresh = $deviceRepo->findById($device->id);
        $meta = $fresh !== null && is_array($fresh->meta) ? $fresh->meta : [];
        $confirmed = (($meta['switch_state'] ?? null) === $state);
        $commandId = bin2hex(random_bytes(8));
        $meta['last_command_id'] = $commandId;
        $meta['last_command_source'] = 'panel';
        $deviceRepo->update($device->id, [
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return \App\Http\Response::json($confirmed ? 200 : 202, [
            'ok' => true,
            'state' => $state,
            'confirmed' => $confirmed,
            'command_id' => $commandId,
        ]);
    }
);

// POST /almacen-api/sensors/refresh — relectura puntual bajo demanda (F75/RF-88).
// Hace UNA lectura REST del sensor de puerta de la sala (respeta el presupuesto y
// el backoff compartido de Tuya) y la aplica por la ingesta existente. NUNCA se
// invoca por temporizador: solo el panel (apertura o botón "Actualizar estado").
$router->post(
    '/almacen-api/sensors/refresh',
    function (\App\Http\Request $request) use ($pdo, $tuyaIngress, $iotSessionService, $warehouseStateController): \App\Http\Response {
        $body   = is_array($request->jsonBody) ? $request->jsonBody : [];
        $roomId = (int) ($body['room_id'] ?? ($request->query['room_id'] ?? 0));

        if ($roomId > 0) {
            $rs = $pdo->prepare(
                "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
                 WHERE r.id = :id AND rt.code = 'ALMACEN_BEBIDAS' LIMIT 1"
            );
            $rs->execute([':id' => $roomId]);
            $roomId = (int) ($rs->fetchColumn() ?: 0);
        } else {
            $roomId = (int) ($pdo->query(
                "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
                 WHERE rt.code = 'ALMACEN_BEBIDAS' ORDER BY r.id ASC LIMIT 1"
            )->fetchColumn() ?: 0);
        }
        if ($roomId <= 0) {
            return \App\Http\Response::json(404, ['ok' => false, 'probed' => false, 'reason' => 'no_warehouse_room']);
        }

        $ds = $pdo->prepare(
            "SELECT d.id, d.external_id, d.meta_json FROM devices d
             JOIN rooms r ON r.pack_id = d.pack_id
             WHERE r.id = :rid AND d.kind = 'PROXIMITY' LIMIT 1"
        );
        $ds->execute([':rid' => $roomId]);
        $door = $ds->fetch(\PDO::FETCH_ASSOC);
        if ($door === false || (string) ($door['external_id'] ?? '') === '') {
            return \App\Http\Response::json(200, [
                'ok' => false, 'probed' => false, 'reason' => 'no_door_sensor',
                'state' => $warehouseStateController->stateArray($roomId),
            ]);
        }
        $deviceId   = (int) $door['id'];
        $externalId = (string) $door['external_id'];

        // Cooldown por dispositivo (persistido en devices.meta_json.status_probed_at).
        $cooldown = \App\Support\Config::getInt('ALMACEN_SENSOR_REFRESH_COOLDOWN_SECONDS', 1800);
        $meta = json_decode((string) ($door['meta_json'] ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        $lastAt = $meta['status_probed_at'] ?? null;
        $lastProbedTs = (is_string($lastAt) && $lastAt !== '') ? strtotime($lastAt . ' UTC') : false;
        $cooldownActive = ($cooldown > 0 && $lastProbedTs !== false && (time() - $lastProbedTs) < $cooldown);

        // F85/RF-111.2: una transición de puerta sin resolver (door_stale + evento
        // aplicado posterior a la última sonda) justifica UNA lectura REST aunque el
        // cooldown esté vigente. La sonda sigue respetando el presupuesto compartido.
        $unresolvedTransition = false;
        if ($cooldownActive) {
            $ls = $pdo->prepare('SELECT door_state, last_door_event_at FROM iot_sessions WHERE room_id = :r LIMIT 1');
            $ls->execute([':r' => $roomId]);
            $lsRow = $ls->fetch(\PDO::FETCH_ASSOC) ?: [];
            $staleSeconds = \App\Support\Config::getInt('DOOR_STALE_SECONDS', 300);
            $staleSeconds = $staleSeconds > 0 ? $staleSeconds : 300;
            $doorState = (string) ($lsRow['door_state'] ?? 'UNKNOWN');
            $lastDoor = $lsRow['last_door_event_at'] ?? null;
            $lastDoorTs = ($lastDoor !== null && $lastDoor !== '') ? strtotime((string) $lastDoor . ' UTC') : false;
            $doorStale = ($doorState === 'UNKNOWN' || $lastDoorTs === false
                || (time() - $lastDoorTs) > $staleSeconds);
            $unresolvedTransition = $doorStale && $lastDoorTs !== false && $lastProbedTs !== false
                && $lastDoorTs > $lastProbedTs;
        }
        if ($cooldownActive && !$unresolvedTransition) {
            return \App\Http\Response::json(200, [
                'ok' => true, 'probed' => false, 'reason' => 'throttled',
                'retry_in_seconds' => max(0, $cooldown - (int) $lastProbedTs),
                'state' => $warehouseStateController->stateArray($roomId),
            ]);
        }

        $res = tuyaPresenceApi('GET', '/v1.0/iot-03/devices/' . rawurlencode($externalId) . '/status', null);
        $http = (int) ($res['http'] ?? 0);
        if ($http === 429) {
            return \App\Http\Response::json(429, [
                'ok' => false, 'probed' => false, 'reason' => 'quota',
                'error' => $res['error'] ?? 'tuya_quota',
                'state' => $warehouseStateController->stateArray($roomId),
            ]);
        }
        if (($res['error'] ?? null) !== null || $http >= 500 || ($res['data']['success'] ?? false) !== true) {
            return \App\Http\Response::json(502, [
                'ok' => false, 'probed' => false, 'reason' => 'unavailable',
                'error' => $res['error'] ?? ($res['data']['msg'] ?? 'tuya_status_failed'),
                'state' => $warehouseStateController->stateArray($roomId),
            ]);
        }

        // Sellar la sonda aunque la aplicación falle (best-effort).
        $meta['status_probed_at'] = gmdate('Y-m-d\TH:i:s\Z');
        try {
            $pdo->prepare('UPDATE devices SET meta_json = :m WHERE id = :id')
                ->execute([':m' => json_encode($meta, JSON_UNESCAPED_UNICODE), ':id' => $deviceId]);
        } catch (\Throwable $e) { /* best-effort */ }

        $result = ['ok' => true, 'probed' => true, 'reason' => $unresolvedTransition ? 'unresolved_transition' : 'ok'];
        try {
            $status = [];
            foreach (($res['data']['result'] ?? []) as $dp) {
                if (is_array($dp) && isset($dp['code'])) {
                    $status[] = [
                        'code'  => (string) $dp['code'],
                        'value' => $dp['value'] ?? null,
                        't'     => $dp['t'] ?? null,
                    ];
                }
            }
            $event = $tuyaIngress->normalize(['devId' => $externalId, 'status' => $status]);
            if (($event['meta']['_noop'] ?? false) === true) {
                $result['applied'] = false;
            } else {
                $iotSessionService->processEvent($event, 'sensor-refresh-' . $roomId);
                $result['applied'] = true;
            }
        } catch (\Throwable $e) {
            $result['applied'] = false;
            $result['error']   = $e->getMessage();
        }
        $result['state'] = $warehouseStateController->stateArray($roomId);
        return \App\Http\Response::json(200, $result);
    }
);

// --- Página única del almacén (F65/RF-74.1, público LAN) ---
$router->get(
    '/almacen',
    function (\App\Http\Request $request): \App\Http\Response {
        $htmlFile = __DIR__ . '/almacen.html';
        if (!is_file($htmlFile)) {
            return \App\Http\Response::json(404, ['error' => 'almacen HTML not found']);
        }
        return new \App\Http\Response(200, ['Content-Type' => 'text/html; charset=utf-8'], @file_get_contents($htmlFile) ?: '');
    }
);

// Factory firmware announces only its eFuse identity; no room or operational action.
$router->post('/api/v1/factory-devices/announce', [$factoryDeviceController, 'announce']);
$router->get('/api/v1/factory-devices', [$factoryDeviceController, 'list'], $authFactory(['audit:read']));
$router->post('/api/v1/factory-devices/{id}/claim', [$factoryDeviceController, 'claim'], $authFactory(['factory:claim']));

// --- CRM Panel Controller ---
$crmUserRepo    = new CrmUserRepository($pdo);
$crmSessionRepo = new CrmSessionRepository($pdo);
$crmUserService = new CrmUserService($crmUserRepo, $crmSessionRepo);
$crmController  = new CrmController($crmUserService);
$crmSessionMw   = new CrmSessionMiddleware($crmSessionRepo, $crmUserRepo);
$panelAccessMw  = new PanelAccessMiddleware();
$keyDeskAccessMw = new KeyDeskAccessMiddleware();


// ── Dashboard API: QR de pruebas (RF-20) ──

// POST /dashboard-api/qr-test/create — Crea un QR real de pruebas
$router->post(
    '/dashboard-api/qr-test/create',
    function (\App\Http\Request $request) use ($qrTestCtrl): \App\Http\Response {
        return $qrTestCtrl->create($request);
    }
);

// POST /dashboard-api/qr-test/reset — Método A: revoca QR actual + limpia + crea nuevo QR
$router->post(
    '/dashboard-api/qr-test/reset',
    function (\App\Http\Request $request) use ($qrTestCtrl): \App\Http\Response {
        return $qrTestCtrl->reset($request);
    }
);

// POST /dashboard-api/rooms/reset — Método B: hard reset, sin crear nuevo QR
$router->post(
    '/dashboard-api/rooms/reset',
    function (\App\Http\Request $request) use ($qrTestCtrl): \App\Http\Response {
        $resp = $qrTestCtrl->roomsReset($request);
        // F47 TEMP (pruebas físicas): el reset de habitación vacía las marcas de
        // latencia para empezar una prueba limpia. RETIRAR al acabar las pruebas.
        @file_put_contents(dirname(__DIR__) . '/run/latency-marker', '');
        return $resp;
    }
);

// ── F47 TEMP: botonera de marcas de latencia (dev tool, sin auth) ─────────────
// Añade una marca física a api/run/latency-marker para que la sonda
// `bin/presence-latency-probe.js` cruce el instante real con el pipeline.
// Whitelist estricta (no hay escritura arbitraria). RETIRAR al acabar.
$router->post(
    '/dashboard-api/latency-mark',
    function (\App\Http\Request $request): \App\Http\Response {
        $allowed = ['PUERTA_ABRE', 'DELANTE_SENSOR', 'QUIETO', 'ALEJO'];
        $body    = is_array($request->jsonBody) ? $request->jsonBody : [];
        $label   = (string) ($body['label'] ?? '');
        $file    = dirname(__DIR__) . '/run/latency-marker';

        if ($label === 'CLEAR') {
            @file_put_contents($file, '');
            return \App\Http\Response::json(200, ['ok' => true, 'cleared' => true]);
        }
        if (!in_array($label, $allowed, true)) {
            return \App\Http\Response::json(400, ['error' => 'invalid_label']);
        }
        @file_put_contents($file, $label . "\n", FILE_APPEND | LOCK_EX);
        return \App\Http\Response::json(200, [
            'ok'        => true,
            'label'     => $label,
            'server_ts' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }
);

// ── Simula: presence sensor range toggle (dev tool, no auth) ──
// Configurable so the dev tool can target any sensor; defaults to the ZY-M100
// bench sensor (ranges hardcoded in simula.html). Use the per-room
// /dashboard-api/presence-calibrate endpoint for the 24G V3.
define('SIMULA_DEVICE_ID', Config::get('SIMULA_DEVICE_ID', 'bf98d27d79685e38a2wbda'));

// GET /dashboard-api/simula/status — read current far_detection & sensitivity from Tuya
$router->get(
    '/dashboard-api/simula/status',
    function (\App\Http\Request $request): \App\Http\Response {
        $res = tuyaPresenceApi('GET', '/v1.0/iot-03/devices/' . SIMULA_DEVICE_ID . '/status', null);
        if ($res['error']) {
            return \App\Http\Response::json(502, ['error' => $res['error']]);
        }
        if (($res['data']['success'] ?? false) !== true) {
            return \App\Http\Response::json(502, ['error' => $res['data']['msg'] ?? 'Tuya status API failed']);
        }

        // Parse DPs from the status array
        $dps = [];
        foreach (($res['data']['result'] ?? []) as $dp) {
            $dps[$dp['code'] ?? ''] = $dp['value'] ?? null;
        }
        return \App\Http\Response::json(200, [
            'far_detection'       => $dps['far_detection'] ?? null,
            'sensitivity'         => $dps['sensitivity'] ?? null,
            'presence_state'      => $dps['presence_state'] ?? null,
            'target_dis_closest'  => $dps['target_dis_closest'] ?? null,
            'mode'                => ($dps['far_detection'] ?? 0) <= 1 ? 'OFF' : 'ON',
            // RF-30: effective presence respects /simula OFF → radius 0 ⇒ ABSENT
            'effective_presence'  => ($dps['far_detection'] ?? 0) <= 1
                ? 'ABSENT (radio 0)'
                : (in_array($dps['presence_state'] ?? 'none', ['presence', 'move'], true) ? 'PRESENT' : 'ABSENT'),
        ]);
    }
);

// POST /dashboard-api/simula/set — set far_detection and/or sensitivity
$router->post(
    '/dashboard-api/simula/set',
    function (\App\Http\Request $request): \App\Http\Response {
        $body = $request->jsonBody ?? [];
        $far = $body['far_detection'] ?? null;
        $sens = $body['sensitivity'] ?? null;

        if ($far === null && $sens === null) {
            return \App\Http\Response::json(400, ['error' => 'far_detection or sensitivity required']);
        }

        $commands = [];
        if ($far !== null) {
            $commands[] = ['code' => 'far_detection', 'value' => (int) $far];
        }
        if ($sens !== null) {
            $commands[] = ['code' => 'sensitivity', 'value' => (int) $sens];
        }

        $res = tuyaPresenceApi(
            'POST',
            '/v1.0/iot-03/devices/' . SIMULA_DEVICE_ID . '/commands',
            json_encode(['commands' => $commands])
        );

        if ($res['error']) {
            return \App\Http\Response::json(502, ['error' => $res['error']]);
        }
        if (($res['data']['success'] ?? false) !== true) {
            return \App\Http\Response::json(502, ['error' => $res['data']['msg'] ?? 'Tuya command failed']);
        }

        $mode = ($far !== null ? ($far <= 1 ? 'OFF' : 'ON') : 'UNKNOWN');
        return \App\Http\Response::json(200, [
            'ok'   => true,
            'mode' => $mode,
            'elapsed_ms' => $res['elapsed_ms'] ?? 0,
        ]);
    }
);

// POST /dashboard-api/simula/inject-presence — bypass directo (sin esperar webhook Tuya)
// F35: cuando el slider de /simula cambia, además de configurar el sensor vía Tuya,
// inyecta el evento de presencia directamente para que el dashboard lo refleje en <1s.
$router->post(
    '/dashboard-api/simula/inject-presence',
    function (\App\Http\Request $request) use ($simulatedIngress, $iotSessionService): \App\Http\Response {
        $body = $request->jsonBody ?? [];
        $far  = (int)($body['far_detection'] ?? -1);
        if ($far < 0) {
            return \App\Http\Response::json(400, ['error' => 'far_detection required']);
        }
        $roomId   = (int)($body['room_id'] ?? 1);
        $presence = $far > 1 ? 'PRESENT' : 'ABSENT';

        try {
            $event = $simulatedIngress->normalize([
                'room_id'         => $roomId,
                'sensor'          => 'PRESENCE',
                'value'           => $presence,
                'source_event_id' => 'simula-inject-' . $roomId . '-' . time(),
            ]);
            $result = $iotSessionService->processEvent($event, '');

            return \App\Http\Response::json(202, array_merge($result, [
                'simulated' => true,
                'injected'  => $presence,
            ]));
        } catch (\Throwable $e) {
            return \App\Http\Response::json(500, [
                'error'   => 'inject failed: ' . $e->getMessage(),
                'injected' => $presence,
            ]);
        }
    }
);

// ── Presence-sensor calibration (per-room, LAN dashboard) ─────────────────
// Generalizes the /simula dev tool to the PRESENCE device assigned to any room
// (each sensor lives in its own room/pack). The live badge below reads the raw
// Tuya DP directly (~2s polling only while the calibration modal is open) because
// the domain presence_state only flips via webhook/push and can lag several
// seconds. We intentionally DO NOT inject events into iot_session here to avoid
// polluting anomalies while the technician walks in/out.

// GET /dashboard-api/presence-calibrate/status?room_id=N
// Reads far_detection, sensitivity and raw presence_state from the Tuya device.
$router->get(
    '/dashboard-api/presence-calibrate/status',
    function (\App\Http\Request $request) use ($pdo): \App\Http\Response {
        $roomId = (int) ($request->query['room_id'] ?? 0);
        if ($roomId <= 0) {
            return \App\Http\Response::json(400, ['error' => 'room_id required']);
        }
        $dev = resolvePresenceDeviceForRoom($pdo, $roomId);
        if ($dev === null) {
            return \App\Http\Response::json(404, ['error' => 'La habitación no tiene sensor de presencia']);
        }

        // F93/RF-156: radar Zigbee → lectura real por push (sin /get ni Tuya).
        if (($dev['transport'] ?? '') === 'ZIGBEE2MQTT') {
            $cal = new \App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService($pdo);
            return \App\Http\Response::json(200, $cal->status($roomId, $dev));
        }

        $res = tuyaPresenceApi('GET', '/v1.0/iot-03/devices/' . $dev['external_id'] . '/status', null);
        if ($res['error']) {
            return \App\Http\Response::json(502, [
                'error'   => $res['error'],
                'device'  => ['id' => $dev['id'], 'external_id' => $dev['external_id'], 'online' => $dev['online']],
            ]);
        }
        if (($res['data']['success'] ?? false) !== true) {
            return \App\Http\Response::json(502, [
                'error'  => $res['data']['msg'] ?? 'Tuya status API failed',
                'device' => ['id' => $dev['id'], 'external_id' => $dev['external_id'], 'online' => $dev['online']],
            ]);
        }

        $dps = [];
        foreach (($res['data']['result'] ?? []) as $dp) {
            $dps[$dp['code'] ?? ''] = $dp['value'] ?? null;
        }
        $caps  = presenceDpCaps($dev['meta'] ?? null);
        $state = normalizePresenceDps($dps, $caps);
        $saved = is_array($dev['meta'] ?? null) && isset($dev['meta']['calibration'])
            ? $dev['meta']['calibration']
            : null;

        return \App\Http\Response::json(200, array_merge([
            'room_id' => $roomId,
            'device'  => [
                'id'          => $dev['id'],
                'external_id' => $dev['external_id'],
                'label'       => $dev['label'],
                'online'      => $dev['online'],
            ],
            'dp_caps' => $caps,
            'saved'   => $saved,      // last persisted calibration snapshot
        ], $state));
    }
);

// POST /dashboard-api/presence-calibrate/set — write DP(s) and persist per-room snapshot
$router->post(
    '/dashboard-api/presence-calibrate/set',
    function (\App\Http\Request $request) use ($pdo): \App\Http\Response {
        $body = $request->jsonBody ?? [];
        $roomId = (int) ($body['room_id'] ?? 0);
        if ($roomId <= 0) {
            return \App\Http\Response::json(400, ['error' => 'room_id required']);
        }
        $dev = resolvePresenceDeviceForRoom($pdo, $roomId);
        if ($dev === null) {
            return \App\Http\Response::json(404, ['error' => 'La habitación no tiene sensor de presencia']);
        }

        // F93/RF-156: radar Zigbee. El panel reutiliza `far_detection` (cm) y
        // `sensitivity`; los ajustes avanzados viajan en metros/segundos.
        if (($dev['transport'] ?? '') === 'ZIGBEE2MQTT') {
            $persist = !array_key_exists('persist', $body) || (bool) $body['persist'];
            $params = [];
            if (isset($body['far_detection']) && is_numeric($body['far_detection'])) {
                $params['maximum_range'] = ((int) $body['far_detection']) / 100;
            }
            if (isset($body['sensitivity']) && is_numeric($body['sensitivity'])) {
                $params['radar_sensitivity'] = (int) $body['sensitivity'];
            }
            foreach ([
                'minimum_range_m' => 'minimum_range',
                'detection_delay_s' => 'detection_delay',
                'fading_time_s' => 'fading_time',
                'minimum_range' => 'minimum_range',
                'detection_delay' => 'detection_delay',
                'fading_time' => 'fading_time',
            ] as $inputKey => $param) {
                if (isset($body[$inputKey]) && is_numeric($body[$inputKey]) && !isset($params[$param])) {
                    $params[$param] = (float) $body[$inputKey];
                }
            }
            if ($params === []) {
                return \App\Http\Response::json(400, ['error' => 'far_detection o sensitivity requeridos']);
            }
            try {
                $cal = new \App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService($pdo);
                return \App\Http\Response::json(200, $cal->apply($roomId, $dev, $params, $persist));
            } catch (\Throwable $e) {
                return \App\Http\Response::json(400, [
                    'error' => $e->getMessage(),
                    'device' => ['id' => $dev['id'], 'external_id' => $dev['external_id']],
                ]);
            }
        }

        $far = isset($body['far_detection']) ? (int) $body['far_detection'] : null;
        $sens = isset($body['sensitivity']) ? (int) $body['sensitivity'] : null;
        // Live adjust (persist=false) writes the DPs but keeps the last saved
        // snapshot untouched; "Guardar" sends persist=true to persist it.
        $persist = !array_key_exists('persist', $body) || (bool) $body['persist'];
        if ($far === null && $sens === null) {
            return \App\Http\Response::json(400, ['error' => 'far_detection o sensitivity requeridos']);
        }

        // Validate/patch against the device's own DP capabilities (F43): the ZY-M100
        // and the 24G V3 have different ranges (0-1000/step 10 vs 75-900/step 75).
        $caps = presenceDpCaps($dev['meta'] ?? null);
        if ($far !== null) {
            if ($far < $caps['far_min'] || $far > $caps['far_max']) {
                return \App\Http\Response::json(400, [
                    'error' => sprintf('far_detection fuera de rango (%d-%d cm)', $caps['far_min'], $caps['far_max']),
                ]);
            }
            $step = max(1, $caps['far_step']);
            $far  = (int) (round(($far - $caps['far_min']) / $step) * $step + $caps['far_min']);
        }
        if ($sens !== null && ($sens < $caps['sens_min'] || $sens > $caps['sens_max'])) {
            return \App\Http\Response::json(400, [
                'error' => sprintf('sensitivity fuera de rango (%d-%d)', $caps['sens_min'], $caps['sens_max']),
            ]);
        }

        // The UI only manages the MAXIMUM range; the minimum (dead zone) is always
        // pushed to its lowest possible value so the sensor detects from right next
        // to it (F43).
        $nearMin = (int) $caps['near_min'];

        $commands = [];
        if ($far !== null) {
            $commands[] = ['code' => 'far_detection', 'value' => $far];
        }
        if ($sens !== null) {
            $commands[] = ['code' => 'sensitivity', 'value' => $sens];
        }
        // Force the dead zone to its minimum (skip on the ZY-M100 OFF case far=0).
        if ($far === null || $far > 0) {
            $commands[] = ['code' => 'near_detection', 'value' => $nearMin];
        }

        $res = tuyaPresenceApi(
            'POST',
            '/v1.0/iot-03/devices/' . $dev['external_id'] . '/commands',
            json_encode(['commands' => $commands])
        );
        if ($res['error']) {
            return \App\Http\Response::json(502, [
                'error'  => $res['error'],
                'device' => ['id' => $dev['id'], 'external_id' => $dev['external_id']],
            ]);
        }
        if (($res['data']['success'] ?? false) !== true) {
            return \App\Http\Response::json(502, [
                'error'  => $res['data']['msg'] ?? 'Tuya command failed',
                'device' => ['id' => $dev['id'], 'external_id' => $dev['external_id']],
            ]);
        }

        // Verify by read-back: Tuya ACKs the command even when the device ignores it
        // (e.g. far <= near), and the device needs a few seconds to apply the DP.
        // Retry the read a couple of times before concluding it was not applied.
        $applied  = false;
        $warning  = null;
        $status   = null;
        $sentNear = in_array('near_detection', array_column($commands, 'code'), true);
        $maxAttempts = 4;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                usleep(1400000); // ~1.4 s settle between reads (device takes ~4 s)
            }
            $rb = tuyaPresenceApi('GET', '/v1.0/iot-03/devices/' . $dev['external_id'] . '/status', null);
            if ($rb['error'] || (($rb['data']['success'] ?? false) !== true)) {
                $warning = 'No se pudo verificar la aplicación del valor en el sensor';
                continue;
            }
            $dps = [];
            foreach (($rb['data']['result'] ?? []) as $dp) {
                $dps[$dp['code'] ?? ''] = $dp['value'] ?? null;
            }
            $status  = normalizePresenceDps($dps, $caps);
            $applied = ($far === null  || $status['far_detection'] === $far)
                    && ($sens === null || $status['sensitivity'] === $sens)
                    && (!$sentNear    || $status['near_detection'] === $nearMin);
            if ($applied) {
                $warning = null;
                break;
            }
            $warning = 'El sensor no aplicó el valor solicitado (revisa el rango máximo o el estado del dispositivo)';
        }

        // Persist the calibration snapshot only on explicit save AND only when the
        // sensor really applied it (never claim "guardado" for an unapplied value).
        $snap = null;
        if ($persist && $applied && $status !== null
            && $status['far_detection'] !== null && $status['sensitivity'] !== null) {
            $snap = persistPresenceCalibration(
                $pdo,
                $dev['id'],
                $dev['meta'],
                (int) $status['far_detection'],
                (int) $status['sensitivity'],
                $status['near_detection'] !== null ? (int) $status['near_detection'] : null
            );
        }

        return \App\Http\Response::json(200, [
            'ok'         => true,
            'applied'    => $applied,
            'persisted'  => $snap !== null,
            'saved'      => $snap,
            'warning'    => $warning,
            'status'     => $status,
            'elapsed_ms' => $res['elapsed_ms'] ?? 0,
        ]);
    }
);

// --- Public CRM routes (no auth) ---
$router->post('/api/v1/crm/login', [$crmController, 'login']);
$router->get('/panel/login', [$crmController, 'loginPage']);

// --- Protected CRM API routes (session cookie) ---
$router->post('/api/v1/crm/logout', [$crmController, 'logout'], [$crmSessionMw]);
$router->get('/api/v1/crm/me',    [$crmController, 'me'],    [$crmSessionMw]);

// Panel user CRUD (admin scope)
$router->get('/api/v1/crm/users',         [$crmController, 'listUsers'],  $authFactory(['audit:read']));
$router->get('/api/v1/crm/users/{id}',    [$crmController, 'showUser'],   $authFactory(['audit:read']));
$router->post('/api/v1/crm/users',        [$crmController, 'createUser'], $authFactory(['audit:read']));
$router->patch('/api/v1/crm/users/{id}',  [$crmController, 'updateUser'], $authFactory(['audit:read']));
$router->delete('/api/v1/crm/users/{id}', [$crmController, 'deleteUser'], $authFactory(['audit:read']));

// --- Protected CRM panel HTML pages (session cookie) ---
$router->get('/panel',            [$crmController, 'panel'],      [$crmSessionMw, $panelAccessMw]);
$router->get('/panel/{path}',     [$crmController, 'panelAsset'], [$crmSessionMw, $panelAccessMw]);

// --- F98: Key desk pages (/llaves) ---
// Login and static assets are public; everything else reuses the CRM session
// but admits only key_manager|admin. Assets/login are declared before the SPA
// catch-all so /llaves/{path} cannot swallow them.
$router->get('/llaves/login',        [$keyDeskController, 'loginPage']);
$router->get('/llaves/assets/{path}', [$keyDeskController, 'asset']);
$router->get('/llaves',              [$keyDeskController, 'index'], [$crmSessionMw, $keyDeskAccessMw]);
$router->get('/llaves/{path}',       [$keyDeskController, 'index'], [$crmSessionMw, $keyDeskAccessMw]);

// --- SSE event stream (F33x) — bypass middleware for direct streaming ---
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($requestPath === '/dashboard-api/event-stream' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $roomId = (int) ($_GET['room_id'] ?? 1);
    if ($roomId > 0) {
        (new EventStreamController($pdo))->stream($roomId);
        // stream() calls exit() — execution ends here for SSE clients
    }
}

// --- SSE del ESP-imán (F97/RF-176) — bypass middleware ---
if ($requestPath === '/dashboard-api/door-events' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    (new \App\Http\Controllers\ImanEventStreamController($pdoProvider()))->stream();
    // stream() calls exit()
}

// --- SSE del almacén (F65/RF-74.3) — bypass middleware ---
if ($requestPath === '/almacen-api/event-stream' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $roomId = (int) ($_GET['room_id'] ?? 0);
    (new AlmacenEventStreamController($warehouseStateController))->stream($roomId);
    // stream() calls exit()
}

// --- Dispatch ---
$request = Request::fromGlobals();
$response = $router->dispatch($request);
(new ResponseEmitter())->emit($response);

/**
 * Resolve the log file path relative to the project root if not absolute.
 */
function self_resolveLogPath(string $projectRoot, ?string $configured): ?string
{
    if ($configured === null || $configured === '') {
        return null;
    }
    if ($configured[0] === '/') {
        return $configured;
    }
    return rtrim($projectRoot, '/') . '/' . $configured;
}
