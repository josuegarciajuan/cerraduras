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
use App\Domain\Presence\RoomSensorStateRepository;
use App\Domain\Qr\QrCredentialRepository;
use App\Domain\Qr\QrIssueService;
use App\Domain\Qr\QrValidateService;
use App\Infrastructure\Gateways\Lock\LockGatewayFactory;
use App\Infrastructure\Gateways\Sensor\SimulatedSensorIngress;
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
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\KeyDeskController;
use App\Http\Controllers\DevEchoController;
use App\Http\Controllers\EventStreamController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\FirmwareOtaController;
use App\Domain\Firmware\FirmwareOtaService;
use App\Domain\Firmware\FirmwareOperationRepository;
use App\Domain\Firmware\FirmwareReleaseRepository;
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
// Solo lectura de BD/MQTT bajo demanda; sin pollers ni llamadas a la nube.
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
$router->get('/dashboard-api/sensores/v2/radar/state', [$sensorWizardV2, 'radarState']);
$router->post('/dashboard-api/sensores/v2/radar/operation', [$sensorWizardV2, 'radarOperation']);
$router->post('/dashboard-api/sensores/v2/run/start', [$sensorWizardV2, 'startRun']);
$router->post('/dashboard-api/sensores/v2/run/reset', [$sensorWizardV2, 'resetRun']);
$router->post('/dashboard-api/sensores/v2/attempt/start', [$sensorWizardV2, 'startAttempt']);
$router->post('/dashboard-api/sensores/v2/attempt/check', [$sensorWizardV2, 'checkAttempt']);
$router->post('/dashboard-api/sensores/v2/attempt/cancel', [$sensorWizardV2, 'cancelAttempt']);
$router->post('/dashboard-api/sensores/v2/door/polarity', [$sensorWizardV2, 'polarity']);
$router->post('/dashboard-api/sensores/v2/door/state', [$sensorWizardV2, 'doorState']);
$router->post('/dashboard-api/sensores/v2/radar/tune', [$sensorWizardV2, 'tune']);
$router->post('/dashboard-api/sensores/v2/radar/calibrate', [$sensorWizardV2, 'radarCalibrate']);
$router->post('/dashboard-api/sensores/v2/switch/test', [$sensorWizardV2, 'switchTest']);
$router->post('/dashboard-api/sensores/v2/light/phase', [$sensorWizardV2, 'lightPhase']);
$router->get('/dashboard-api/sensores/v2/switch/diagnostics', [$sensorWizardV2, 'switchDiagnostics']);
$router->get('/dashboard-api/sensores/v2/switch/diagnostics/export', [$sensorWizardV2, 'switchDiagnosticsExport']);
$router->post('/dashboard-api/sensores/v2/automation/pause', [$sensorWizardV2, 'automationPause']);
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

/** @var list<string> Dispositivos que comparten hardware ESP32 (sin nube). */
const DEVICE_KIND_ESP32 = ['RPI', 'SCANNER', 'LOCK'];
/** Cuántos segundos conserva una verificación de conectividad antes de caducar. */
const DEVICE_PROBE_TTL_SECONDS = 600;

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

/** Persiste el resultado de una verificación de conectividad (Zigbee availability o cámara). */
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
    $row['transport'] = $row['transport'] !== null ? strtoupper((string) $row['transport']) : 'UNKNOWN';
    $row['meta']      = !empty($row['meta_json']) ? json_decode($row['meta_json'], true) : null;
    unset($row['meta_json']);
    return $row;
}

// GET /dashboard-api/system-status — health check of background processes
// F41/F122 (contracts.md §5): 4 workers with expected/instances/pids/healthy/degraded.
// F78/F122: no hay poller ni consumer de nube.
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

            // Workers gestionados por systemd: se cuenta su MainPID y se une
            // con la detección por marcador para ver duplicados como `degraded`.
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
            $d['is_retired'] = is_array($d['meta'] ?? null) && !empty($d['meta']['retired_at']);
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

// F100: catálogo de firmware y operaciones OTA (panel + dispositivo).
$firmwareReleaseRepo = new FirmwareReleaseRepository($pdo);
$firmwareOperationRepo = new FirmwareOperationRepository($pdo);
$firmwareOtaService = new FirmwareOtaService($pdo, $deviceRepo, $firmwareReleaseRepo, $firmwareOperationRepo);
$firmwareOtaController = new FirmwareOtaController($firmwareOtaService, $firmwareReleaseRepo);

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
                // F122: categoría por transporte; solo Zigbee/cámara/ESP32.
                $dev['meta'] = is_string($dev['meta_json'] ?? null) && $dev['meta_json'] !== ''
                    ? json_decode((string) $dev['meta_json'], true)
                    : null;
                unset($dev['meta_json']);
                $dev['integration_status'] = $deviceStatusFactory()->forRow($dev);
                $category = deviceCategory($dev);

                // F33: Skip RPI, SCANNER, and LOCK — all ESP32-connected, no cloud
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
                    $st = resolveDeviceOnlineState($dev, DEVICE_PROBE_TTL_SECONDS);
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

                if ($category === DeviceLivenessService::CATEGORY_RETIRED) {
                    $deviceChecks[] = [
                        'device_id' => (int) $dev['id'],
                        'kind' => $dev['kind'],
                        'external_id' => $dev['external_id'],
                        'online' => null,
                        'state' => 'retired',
                        'error' => 'device_retired',
                        'last_seen_at' => $dev['last_seen_at'],
                        'checked_via' => 'RETIRED',
                    ];
                    continue;
                }

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
            "SELECT d.id, d.pack_id, d.kind, d.subtype, d.external_id, d.label, d.transport,
                    d.firmware_variant, d.firmware_version, d.meta_json, d.last_seen_at,
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
            $d['is_retired'] = is_array($d['meta'] ?? null) && !empty($d['meta']['retired_at']);
            $d['integration_status'] = $deviceStatusFactory()->forRow($d);

            // F122: la política resuelve cámara, Zigbee, ESP32 y retirados por transporte.
            // No hay ninguna sonda de red aquí: solo se leen señales/probes persistidos.
            $st = resolveDeviceOnlineState($d, DEVICE_PROBE_TTL_SECONDS);
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

        // NOTA: el estado se calcula solo con señales/push persistidos. Los
        // dispositivos retirados quedan 'retired' y no se sondean.

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
    function (\App\Http\Request $request) use ($deviceRepo, $commandQueueRepo, $firmwareOperationRepo): \App\Http\Response {
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

        // F97.3: telemetría del actuador y del canal de puerta. Es informativa:
        // no cambia el subtipo ni la lógica eléctrica.
        $actuatorState = isset($body['actuator_state']) && is_string($body['actuator_state'])
            ? strtoupper(substr(trim($body['actuator_state']), 0, 16)) : null;
        $actuatorReason = isset($body['actuator_reason']) && is_string($body['actuator_reason'])
            ? substr(trim($body['actuator_reason']), 0, 32) : null;
        $reportedDoorState = isset($body['door_state']) && is_string($body['door_state'])
            ? strtoupper(substr(trim($body['door_state']), 0, 8)) : null;
        $outputOn = null;
        if (array_key_exists('output_on', $body)) {
            $rawOutput = $body['output_on'];
            $outputOn = is_bool($rawOutput) ? $rawOutput
                : (is_string($rawOutput) ? filter_var($rawOutput, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null);
        }
        // F100: OTA capability is announced by the firmware. It is
        // informational for routing: a device that does not announce it cannot
        // be updated remotely and the panel says so explicitly.
        $otaCapable = null;
        if (array_key_exists('ota_capable', $body)) {
            $rawOta = $body['ota_capable'];
            $otaCapable = is_bool($rawOta) ? $rawOta
                : (is_string($rawOta) ? filter_var($rawOta, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null);
        }
        $otaProtocol = isset($body['ota_protocol']) && is_string($body['ota_protocol'])
            ? substr(trim($body['ota_protocol']), 0, 16) : null;
        $otaState = isset($body['ota_state']) && is_string($body['ota_state'])
            ? strtoupper(substr(trim($body['ota_state']), 0, 24)) : null;
        $otaVersion = isset($body['ota_version']) && is_string($body['ota_version'])
            ? substr(trim($body['ota_version']), 0, 48) : null;

        // F116/RF-293: el firmware nuevo anuncia que entiende el comando `open`.
        // Si un heartbeat con versión NO lo anuncia (p. ej. tras un rollback),
        // se desactiva para no encolar comandos que el ESP32 ignoraría.
        $remoteOpenCapable = null;
        if (array_key_exists('remote_open_capable', $body)) {
            $rawRemoteOpen = $body['remote_open_capable'];
            $remoteOpenCapable = is_bool($rawRemoteOpen) ? $rawRemoteOpen
                : (is_string($rawRemoteOpen) ? filter_var($rawRemoteOpen, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null);
        }

        $doorLink = null;
        if (array_key_exists('door_link', $body)) {
            $rawLink = $body['door_link'];
            $doorLink = is_bool($rawLink) ? $rawLink
                : (is_string($rawLink) ? filter_var($rawLink, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null);
        }

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

            if ($actuatorState !== null || $actuatorReason !== null || $reportedDoorState !== null
                || $outputOn !== null || $doorLink !== null || $otaCapable !== null
                || $otaProtocol !== null || $otaState !== null || $otaVersion !== null
                || $remoteOpenCapable !== null || $firmwareVersion !== null
            ) {
                $meta = is_array($rpi->meta) ? $rpi->meta : [];
                if ($actuatorState !== null) $meta['actuator_state'] = $actuatorState;
                if ($actuatorReason !== null) $meta['actuator_reason'] = $actuatorReason;
                if ($reportedDoorState !== null) $meta['door_state'] = $reportedDoorState;
                if ($outputOn !== null) $meta['output_on'] = $outputOn;
                if ($doorLink !== null) $meta['door_link'] = $doorLink;
                if ($otaCapable !== null) $meta['ota_capable'] = $otaCapable;
                if ($otaProtocol !== null) $meta['ota_protocol'] = $otaProtocol;
                if ($otaState !== null) $meta['ota_state'] = $otaState;
                if ($otaVersion !== null) $meta['ota_version'] = $otaVersion;
                if ($remoteOpenCapable !== null) {
                    $meta['remote_open_capable'] = $remoteOpenCapable;
                } elseif ($firmwareVersion !== null) {
                    $meta['remote_open_capable'] = false;
                }
                $meta['actuator_updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                $deviceRepo->updateMeta($rpi->id, $meta);
            }
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
        $firmwareUpdatePending = $firmwareOperationRepo->findNextForExternal($extId) !== null;

        // Opportunistic cleanup: bound the queue while the chip is active.
        // Only runs when there is a backlog, so the DELETE is infrequent.
        if ($hasPending) {
            $commandQueueRepo->purgeStale(24);
        }

        return \App\Http\Response::json(200, [
            'ok' => true,
            'has_pending_commands' => $hasPending,
            'firmware_update_pending' => $firmwareUpdatePending,
            'firmware_variant' => $firmwareVariant,
            'firmware_version' => $firmwareVersion,
            'variant_mismatch' => $variantMismatch,
            'actuator_state' => $actuatorState,
            'actuator_reason' => $actuatorReason,
            'output_on' => $outputOn,
            'door_state' => $reportedDoorState,
            'door_link' => $doorLink,
            'ota_capable' => $otaCapable,
            'ota_protocol' => $otaProtocol,
            'ota_state' => $otaState,
            'remote_open_capable' => $remoteOpenCapable,
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
// F122: Zigbee/ESP32/camera only. No cloud probe exists any more.
// Body: {"device_ids":[<int>,...],"verify_cameras":true}
$router->post(
    '/dashboard-api/ping-all-devices',
    function (\App\Http\Request $request) use ($pdo, $deviceRepo, $deviceStatusFactory, $commandQueueRepo, $resolveOwnerChip): \App\Http\Response {
        $body = $request->jsonBody ?? [];
        $ids = $body['device_ids'] ?? null;
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
                $st = resolveDeviceOnlineState($row, DEVICE_PROBE_TTL_SECONDS);
                $results[] = [
                    'device_id' => $deviceId,
                    'found' => true,
                    'online' => $st['online'],
                    'state' => $st['state'],
                    'checked_via' => 'ZIGBEE',
                    '_pending_check' => false,
                    'error' => $st['reason'],
                    'liveness_source' => $st['source'],
                    'liveness_reason' => $st['reason'],
                ];
                continue;
            }

            if ($category === DeviceLivenessService::CATEGORY_RETIRED) {
                $results[] = [
                    'device_id' => $deviceId,
                    'found' => true,
                    'online' => null,
                    'state' => 'retired',
                    '_pending_check' => false,
                    'checked_via' => 'RETIRED',
                    'error' => 'device_retired',
                    'liveness_source' => DeviceLivenessService::SOURCE_RETIRED,
                    'liveness_reason' => 'sensor_retired',
                ];
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
                $st = resolveDeviceOnlineState($row, DEVICE_PROBE_TTL_SECONDS);
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
$timbreRecordingService = new \App\Domain\Timbre\TimbreRecordingService($pdo, $switchService);
$warehouseRecordingService = new \App\Domain\Timbre\RoomRecordingCoordinator(
    $pdo,
    $warehouseRecordingService,
    $timbreRecordingService
);
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

// F116/RF-293: apertura remota del almacén por cola de comandos del ESP32.
$warehouseDoorOpenService = new \App\Domain\Warehouse\WarehouseDoorOpenService(
    $roomRepo,
    $deviceRepo,
    $commandQueueRepo,
    $accessEventRepo,
    $iotSessionRepo,
    $warehouseRecordingService
);

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
    $warehouseRecordingService,
    new RoomSensorStateRepository($pdo),
    new \App\Domain\Sensors\RadarPresenceReportRepository($pdo)
);
// POST /presence/events always uses SimulatedSensorIngress (canonical format:
// room_id, sensor, value). Real signals arrive by Zigbee2MQTT push.
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

// ── F100: firmware OTA (panel) ─────────────────────────────────────────────
$router->get(
    '/api/v1/firmware/releases',
    [$firmwareOtaController, 'listReleases'],
    $authFactory(['rooms:read'])
);
$router->get(
    '/api/v1/devices/{id}/firmware-operations',
    [$firmwareOtaController, 'listForDevice'],
    $authFactory(['rooms:read'])
);
$router->post(
    '/api/v1/devices/{id}/firmware-update',
    [$firmwareOtaController, 'createForDevice'],
    $authFactory(['rooms:write'])
);
$router->get(
    '/api/v1/firmware-operations/{id}',
    [$firmwareOtaController, 'show'],
    $authFactory(['rooms:read'])
);
$router->post(
    '/api/v1/firmware-operations/{id}/cancel',
    [$firmwareOtaController, 'cancel'],
    $authFactory(['rooms:write'])
);

// ── F100: firmware OTA (dispositivo ESP, credencial vinculada) ─────────────
$router->get(
    '/dashboard-api/firmware-update',
    [$firmwareOtaController, 'deviceManifest'],
    $authFactory(['qr:validate'])
);
$router->get(
    '/dashboard-api/firmware-download',
    [$firmwareOtaController, 'deviceBinary'],
    $authFactory(['qr:validate'])
);
$router->post(
    '/dashboard-api/firmware-progress',
    [$firmwareOtaController, 'deviceProgress'],
    $authFactory(['qr:validate'])
);
$router->post(
    '/dashboard-api/firmware-confirm',
    [$firmwareOtaController, 'deviceConfirm'],
    $authFactory(['qr:validate'])
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
$router->get(
    '/api/v1/anomalies/{id}',
    [$anomalyController, 'show'],
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
$router->post(
    '/api/v1/anomalies/{id}/reopen',
    [$anomalyController, 'reopen'],
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

// --- Routes: Analítica del panel (F119, solo lectura, sin nube) ---
$analyticsController = new AnalyticsController($pdo);
$router->get('/api/v1/analytics/overview',        [$analyticsController, 'overview'],      $authFactory(['rooms:read']));
$router->get('/api/v1/analytics/live',            [$analyticsController, 'live'],          $authFactory(['rooms:read']));
$router->get('/api/v1/analytics/workers',         [$analyticsController, 'workers'],       $authFactory(['workers:read']));
$router->get('/api/v1/analytics/workers/{id}',    [$analyticsController, 'workerDetail'],  $authFactory(['workers:read']));
$router->get('/api/v1/analytics/rooms',           [$analyticsController, 'rooms'],         $authFactory(['rooms:read']));
$router->get('/api/v1/analytics/rooms/{id}',      [$analyticsController, 'roomDetail'],    $authFactory(['rooms:read']));
$router->get('/api/v1/analytics/accesses',        [$analyticsController, 'accesses'],      $authFactory(['audit:read']));
$router->get('/api/v1/analytics/keys',            [$analyticsController, 'keys'],          $authFactory(['keys:read']));
$router->get('/api/v1/analytics/devices',         [$analyticsController, 'devices'],       $authFactory(['rooms:read']));
$router->get('/api/v1/analytics/anomalies',       [$analyticsController, 'anomalies'],     $authFactory(['presence:read']));
$router->get('/api/v1/analytics/warehouse',       [$analyticsController, 'warehouse'],     $authFactory(['rooms:read']));
$router->get('/api/v1/analytics/warehouse/logs',  [$analyticsController, 'warehouseLogs'], $authFactory(['rooms:read']));
$router->get('/api/v1/analytics/warehouse/visits/{id}', [$analyticsController, 'warehouseVisitDetail'], $authFactory(['rooms:read']));
$router->get('/api/v1/analytics/occupations',     [$analyticsController, 'occupations'],   $authFactory(['rooms:read']));
$router->get('/api/v1/analytics/occupations/{kind}/{id}', [$analyticsController, 'occupationDetail'], $authFactory(['rooms:read']));
$router->get('/api/v1/analytics/search',          [$analyticsController, 'search'],        $authFactory(['rooms:read']));

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

// --- Routes: video portero de oficina TIMBRADO (F117, público LAN) ---
$timbreStateController = new \App\Http\Controllers\TimbreStateController($pdo);
$timbreController = new \App\Http\Controllers\TimbreController(
    $pdo,
    $warehouseDoorOpenService,
    $accessKeyService
);
$router->get('/timbre-api/state',      [$timbreStateController, 'show']);
$router->get('/timbre-api/openings',   [$timbreController, 'openings']);
$router->post('/timbre-api/door/open', [$timbreController, 'openDoor']);
$router->post('/timbre-api/keys',      [$timbreController, 'createKey']);

// POST /almacen-api/motion — F88/RF-123.2: el worker de movimiento de cámaras
// publica que una cámara del almacén ha detectado movimiento. Uso interno
// (loopback/LAN, sin auth en el MVP). Solo BD local; sin cuota de nube.
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
// F116: en el hardware local (ESP32 + relé) la API encola un comando `open`;
// el ESP32 lo ejecuta y el sensor Zigbee confirma la apertura física. El motor
// de grabación NO recibe una señal artificial: la recibe del push del sensor.
$router->post(
    '/almacen-api/door/open',
    function (\App\Http\Request $request) use ($warehouseDoorOpenService): \App\Http\Response {
        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        $roomId = (int) ($body['room_id'] ?? 0);
        if ($roomId <= 0) {
            return \App\Http\Response::json(400, ['error' => 'room_id_required']);
        }
        $correlationId = (string) ($request->attr('correlation_id', '') ?? '');
        $result = $warehouseDoorOpenService->request($roomId, $correlationId, 'panel');
        return \App\Http\Response::json(200, array_merge(
            ['ok' => true, 'mode' => 'manual', 'visit_started' => false],
            $result
        ));
    }
);

// POST /almacen-api/switch — control manual de la luz Zigbee (F91/RF-147).
// Misma autorización que door/open (panel LAN). Solo transporte ZIGBEE2MQTT:
// no se usa como puerta trasera hacia la nube. La confirmación física llega por
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

// POST /almacen-api/sensors/refresh — F122: Zigbee only, no cloud probe.
// The panel may call this for compatibility; Zigbee state arrives by push, so the
// endpoint only reports that no out-of-band read is needed.
$router->post(
    '/almacen-api/sensors/refresh',
    function (\App\Http\Request $request) use ($pdo, $warehouseStateController): \App\Http\Response {
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
            "SELECT d.id, d.transport FROM devices d
             JOIN rooms r ON r.pack_id = d.pack_id
             WHERE r.id = :rid AND d.kind = 'PROXIMITY' LIMIT 1"
        );
        $ds->execute([':rid' => $roomId]);
        $door = $ds->fetch(\PDO::FETCH_ASSOC);
        if ($door === false) {
            return \App\Http\Response::json(200, [
                'ok' => false, 'probed' => false, 'reason' => 'no_door_sensor',
                'state' => $warehouseStateController->stateArray($roomId),
            ]);
        }
        if (strtoupper((string) ($door['transport'] ?? '')) !== 'ZIGBEE2MQTT') {
            return \App\Http\Response::json(422, [
                'ok' => false, 'probed' => false, 'reason' => 'sensor_not_zigbee',
                'state' => $warehouseStateController->stateArray($roomId),
            ]);
        }

        return \App\Http\Response::json(200, [
            'ok' => true,
            'probed' => false,
            'reason' => 'zigbee_push',
            'state' => $warehouseStateController->stateArray($roomId),
        ]);
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

// --- Video portero del almacén (F115/RF-286, público LAN) ---
$router->get(
    '/timbre',
    function (\App\Http\Request $request): \App\Http\Response {
        $htmlFile = __DIR__ . '/timbre.html';
        if (!is_file($htmlFile)) {
            return \App\Http\Response::json(404, ['error' => 'timbre HTML not found']);
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

// ── Presence-sensor calibration (per-room, LAN dashboard) ─────────────────
// Generalizes the /simula dev tool to the PRESENCE device assigned to any room
// (each sensor lives in its own room/pack). The live badge below reads the raw
// Zigbee DP directly while the calibration modal is open. The domain
// presence_state only flips via push and can lag several seconds. We intentionally
// DO NOT inject events into iot_session here to avoid polluting anomalies.

// GET /dashboard-api/presence-calibrate/status?room_id=N
// Reads far_detection, sensitivity and raw presence_state from the Zigbee radar.
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

        // F93/RF-156: radar Zigbee → lectura real por push (sin /get ni nube).
        if (($dev['transport'] ?? '') === 'ZIGBEE2MQTT') {
            $cal = new \App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService($pdo);
            return \App\Http\Response::json(200, $cal->status($roomId, $dev));
        }

        return \App\Http\Response::json(422, [
            'error' => 'sensor_not_zigbee',
            'device' => ['id' => $dev['id'], 'external_id' => $dev['external_id']],
        ]);
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

        return \App\Http\Response::json(422, [
            'error' => 'sensor_not_zigbee',
            'device' => ['id' => $dev['id'], 'external_id' => $dev['external_id']],
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

if ($requestPath === '/timbre-api/event-stream' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $roomId = (int) ($_GET['room_id'] ?? 0);
    (new \App\Http\Controllers\TimbreEventStreamController($timbreStateController))->stream($roomId);
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
