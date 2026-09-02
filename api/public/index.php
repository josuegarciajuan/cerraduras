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
use App\Domain\Devices\DeviceRepository;
use App\Domain\Devices\DeviceService;
use App\Domain\Devices\SwitchService;
use App\Domain\Debts\DebtRepository;
use App\Domain\Debts\DebtsService;
use App\Domain\Debts\OutboxVb6Repository;
use App\Domain\Debts\OverstayCalculator;
use App\Domain\Locks\AccessEventRepository;
use App\Domain\Locks\LockService;
use App\Domain\Presence\ExitActionService;
use App\Domain\Presence\ExitRuleEvaluator;
use App\Domain\Presence\IotSessionRepository;
use App\Domain\Presence\IotSessionService;
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
use App\Http\Controllers\AdminApiClientController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\DevEchoController;
use App\Http\Controllers\EventStreamController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DevicePackController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\LockController;
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

// --- Dashboard internal API (no auth — LAN/MVP) ---

/**
 * EMERGENCY FALLBACK: Check Tuya PRESENCE device online status via Tuya Cloud API.
 *
 * IMPORTANT – DO NOT call this from automatic dashboard polling.
 * Device liveness is determined by last_seen_at (updated by Pulsar webhooks,
 * presence poller, and device heartbeat). This function exists ONLY for
 * manual debugging or emergency use.
 *
 * Changes from the original (which burned Tuya trial quota on 2 accounts):
 *   - Only checks PRESENCE (the others use Pulsar push or are command-only)
 *   - Stale threshold: 30 min (was 5 min)
 *   - Cache TTL: 5 min + FILE-BASED (shared across PHP built-in server workers)
 *   - Detects quota exhaustion (code 28841004) and backs off for 6 hours
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
            file_put_contents($backoffFile, time() + 21600); // 6 hours
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
        file_put_contents($backoffFile, time() + 21600); // 6 hours backoff
    }

    // Cache result (5 min), unless all calls failed
    if (!$apiFailed) {
        $result['_ts'] = time();
        file_put_contents($cacheFile, json_encode($result));
        unset($result['_ts']);
    }

    return $result;
}

/**
 * Tuya Cloud API helper for presence sensor DP read/write.
 * Shared credentials with TuyaSwitchGateway / TuyaLockGateway.
 * Caches the access token in-process (static).
 *
 * @param string $method  'GET' or 'POST'
 * @param string $path    e.g. "/v1.0/iot-03/devices/{id}/status"
 * @param string|null $body  JSON body for POST (null for GET)
 * @return array{http:int, data:array, error:string|null, elapsed_ms:int}
 */
function tuyaPresenceApi(string $method, string $path, ?string $body): array {
    // ── Shared quota-exhaustion backoff (same file as checkTuyaOnline) ──
    $backoffFile = sys_get_temp_dir() . '/tuya_quota_backoff.txt';
    if (file_exists($backoffFile)) {
        $backoffUntil = (int) @file_get_contents($backoffFile);
        if (time() < $backoffUntil) {
            return ['http' => 429, 'data' => [], 'error' => 'Tuya quota exhausted — backoff active', 'elapsed_ms' => 0];
        }
        @unlink($backoffFile);
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
        // Detect quota exhaustion and set backoff
        $code = $data['code'] ?? 0;
        if ($code === 28841004 || stripos($data['msg'] ?? '', 'quota') !== false) {
            file_put_contents($backoffFile, time() + 21600); // 6 hours
        }
    }
    return ['http' => $httpCode, 'data' => is_array($data) ? $data : [], 'error' => null, 'elapsed_ms' => $elapsed];
}

// GET /dashboard-api/rooms — list all rooms with code + status + pack info
$router->get(
    '/dashboard-api/rooms',
    function (\App\Http\Request $request) use ($pdoProvider): \App\Http\Response {
        $pdo = $pdoProvider();
        $rows = $pdo->query(
            "SELECT r.id, r.code, r.status, r.pack_id, dp.name AS pack_name
             FROM rooms r
             LEFT JOIN device_packs dp ON dp.id = r.pack_id
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
// Returns online/offline status of key workers (poller, pulsar, exit-scan, overstay-scan).
$router->get(
    '/dashboard-api/system-status',
    function (\App\Http\Request $request): \App\Http\Response {
        $processes = [
            'tuya-presence-poller'  => ['label' => 'Sensor Presencia (Poller)', 'pattern' => 'tuya-presence-poller'],
            'tuya-pulsar-consumer'  => ['label' => 'Eventos Tuya (Pulsar)',    'pattern' => 'tuya-pulsar-consumer'],
            'exit-scan'             => ['label' => 'Regla de Salida (Exit)',   'pattern' => 'bin/exit-scan'],
            'overstay-scan'         => ['label' => 'Overstay Scanner',         'pattern' => 'bin/overstay-scan'],
        ];

        $result = [];
        foreach ($processes as $key => $cfg) {
            $pid = trim((string) @exec('pgrep -f "' . $cfg['pattern'] . '" | head -1'));
            $result[$key] = [
                'label'   => $cfg['label'],
                'online'  => $pid !== '' && is_numeric($pid),
                'pid'     => $pid !== '' ? (int) $pid : null,
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
    function (\App\Http\Request $request) use ($pdoProvider): \App\Http\Response {
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
        // Get devices with online status
        $devRows = $pdo->prepare(
            "SELECT d.id, d.kind, d.external_id, d.label, d.meta_json, d.last_seen_at, d.battery_pct,
                      (d.last_seen_at IS NOT NULL AND d.last_seen_at >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 10 MINUTE)) AS online
              FROM devices d
              JOIN rooms r ON r.pack_id = d.pack_id
              WHERE r.id = :rid AND r.pack_id IS NOT NULL
             ORDER BY d.kind"
        );
        $rows->execute([':rid' => $roomId]);
        $devices = $rows->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($devices as &$d) {
            $d['meta'] = !empty($d['meta_json']) ? json_decode($d['meta_json'], true) : null;
            unset($d['meta_json']);
        }
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
$deviceController = new DeviceController($deviceService);
$commandQueueRepo = new \App\Domain\Devices\CommandQueueRepository($pdo);

// Device Packs (F25)
$devicePackRepo     = new DevicePackRepository($pdo);
$qrTokenizer        = new QrTokenizer(Config::getRequired('QR_SIGNING_SECRET'));
$applyPackService   = new ApplyPackService($devicePackRepo, $deviceRepo, $roomRepo, $pdo);
$devicePackController = new DevicePackController($devicePackRepo, $applyPackService);

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

$qrTestCtrl         = new QrTestController($pdo, $qrTokenizer, $switchService);

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
    function (\App\Http\Request $request) use ($pdo, $deviceRepo): \App\Http\Response {
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

            // 5. Reset IoT session
            $pdo->prepare("INSERT INTO iot_sessions (room_id, door_state, presence_state, updated_at)
                           VALUES (:rid, 'CLOSED', 'ABSENT', UTC_TIMESTAMP(3))
                           ON DUPLICATE KEY UPDATE door_state = 'CLOSED', presence_state = 'ABSENT', last_open_at = NULL, last_absent_since = NULL, exit_evaluated_at = NULL, updated_at = UTC_TIMESTAMP(3)")
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
                "SELECT id, kind, external_id, last_seen_at FROM devices WHERE pack_id = :pid ORDER BY kind"
            );
            $devsStmt->execute([':pid' => $packId]);
            $packDevices = $devsStmt->fetchAll(\PDO::FETCH_ASSOC);

            $deviceChecks = [];
            $first = true;
            // F33: get RPI external_id from the pack for command queuing
            $rpiExtId = $rpi->externalId;
            foreach ($packDevices as $dev) {
                // F33: Skip RPI, SCANNER, and LOCK — all ESP32-connected, no Tuya
                $esp32Kinds = ['RPI', 'SCANNER', 'LOCK'];
                if (in_array($dev['kind'], $esp32Kinds, true)) {
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

                $apiResult = tuyaPresenceApi('GET', "/v1.0/iot-03/devices/{$dev['external_id']}", null);

                if ($apiResult['http'] === 200 && $apiResult['error'] === null) {
                    $online = (bool)($apiResult['data']['result']['online'] ?? false);
                    if ($online) {
                        $pdo->prepare("UPDATE devices SET last_seen_at = UTC_TIMESTAMP(3) WHERE id = :did")
                            ->execute([':did' => $dev['id']]);
                    }
                    $deviceChecks[] = [
                        'device_id' => (int)$dev['id'],
                        'kind' => $dev['kind'],
                        'external_id' => $dev['external_id'],
                        'online' => $online,
                        'error' => null,
                        'last_seen_at' => $online ? gmdate('Y-m-d\TH:i:s.v\Z') : $dev['last_seen_at'],
                        'checked_via' => 'TUYA',
                    ];
                } else {
                    $deviceChecks[] = [
                        'device_id' => (int)$dev['id'],
                        'kind' => $dev['kind'],
                        'external_id' => $dev['external_id'],
                        'online' => false,
                        'error' => $apiResult['error'] ?? "HTTP {$apiResult['http']}",
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
    function (\App\Http\Request $request) use ($pdo): \App\Http\Response {
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

        // Resolve devices via pack chain + direct room_id
        // Use MySQL DATE_SUB for consistent timezone — matches pack-detail endpoint
        $rows = $pdo->prepare(
            "SELECT d.id, d.kind, d.external_id, d.label, d.meta_json, d.last_seen_at, d.battery_pct,
                      (d.last_seen_at IS NOT NULL AND d.last_seen_at >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 10 MINUTE)) AS online
              FROM devices d
              JOIN rooms r ON r.pack_id = d.pack_id
              WHERE r.id = :rid AND r.pack_id IS NOT NULL
              ORDER BY d.kind"
        );
        $rows->execute([':rid' => $roomId]);
        $devices = $rows->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($devices as &$d) {
            $d['online'] = (bool) ($d['online'] ?? false);
            $d['meta'] = !empty($d['meta_json']) ? json_decode($d['meta_json'], true) : null;
            unset($d['meta_json']);
        }
        unset($d);

        // Inherit RPI liveness for LOCK and SCANNER (same physical hardware)
        $rpiOnline = false;
        foreach ($devices as $d) {
            if ($d['kind'] === 'RPI' && $d['online']) { $rpiOnline = true; break; }
        }
        if ($rpiOnline) {
            foreach ($devices as &$d) {
                if (in_array($d['kind'], ['LOCK', 'SCANNER']) && !$d['online']) {
                    $d['online'] = true;
                    $d['_inherited'] = true;
                }
            }
        }

        // NOTE: checkTuyaOnline() removed from the automatic poll.
        // Device online status is determined by last_seen_at, which is kept
        // fresh by Pulsar webhooks (PROXIMITY), presence poller (PRESENCE),
        // and device heartbeat (RPI/ESP32). LOCK and SWITCH are command-only
        // and do not need status polling.
        // checkTuyaOnline() remains available as a manual fallback for
        // debugging/emergency use but MUST NOT run on every dashboard poll
        // — it burns Tuya API quota unnecessarily.

        $onlineCount  = count(array_filter($devices, fn($d) => $d['online']));
        $offlineCount = count($devices) - $onlineCount;

        return \App\Http\Response::json(200, [
            'room_id' => $roomId,
            'pack_id' => $pack['pack_id'],
            'pack_code' => $pack['pack_code'],
            'pack_name' => $pack['pack_name'],
            'online' => $onlineCount,
            'offline' => $offlineCount,
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

        // RPI: precise liveness update (external_id match).
        $ownerPack = null;
        if ($rpi !== null) {
            $deviceRepo->updateLastSeen($rpi->id);
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

        return \App\Http\Response::json(200, [
            'ok' => true,
            'has_pending_commands' => $commandQueueRepo->hasPending($extId),
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
// For pull ESP32 sub-devices (SCANNER/LOCK) it enqueues a `check` command for the
// owner chip and reports _pending_check. RPI and non-pull devices report online.
// Body: {"device_ids":[<int>,...]}
$router->post(
    '/dashboard-api/ping-all-devices',
    function (\App\Http\Request $request) use ($pdo, $deviceRepo, $commandQueueRepo, $resolveOwnerChip): \App\Http\Response {
        $body = $request->jsonBody ?? [];
        $ids = $body['device_ids'] ?? null;
        if (!is_array($ids) || $ids === []) {
            return \App\Http\Response::json(400, ['error' => 'device_ids array required']);
        }

        $results = [];
        foreach ($ids as $rawId) {
            $deviceId = (int) $rawId;
            $dev = $deviceRepo->findById($deviceId);
            if ($dev === null) {
                $results[] = [
                    'device_id' => $deviceId,
                    'online' => false,
                    'found' => false,
                    '_pending_check' => false,
                ];
                continue;
            }

            $isPullKind = in_array($dev->kind, [
                \App\Domain\Devices\Device::KIND_SCANNER,
                \App\Domain\Devices\Device::KIND_LOCK,
            ], true);

            if ($isPullKind) {
                // Enqueue a check for the owner chip (fallback: this row's external_id).
                $owner = $resolveOwnerChip($deviceRepo, $dev->packId, $dev->externalId);
                $queuedId = null;
                if ($owner !== null) {
                    $queuedId = $commandQueueRepo->enqueue($owner, 'check');
                }
                $results[] = [
                    'device_id' => $deviceId,
                    'online' => false,          // pending live confirmation
                    'found' => true,
                    '_pending_check' => $queuedId !== null,
                    'checked_via' => 'PULL',
                    'external_id' => $owner,
                ];
            } else {
                // RPI and non-pull devices: nothing to queue (RPI self-beacons via heartbeat).
                $results[] = [
                    'device_id' => $deviceId,
                    'online' => true,
                    'found' => true,
                    '_pending_check' => false,
                    'checked_via' => 'PULL',
                ];
            }
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

$roomController = new RoomController($roomService, $roomStateService, $deviceService);

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
    $qrTokenizer
);

$accessEventRepo  = new AccessEventRepository($pdo);

// --- F38: Worker QR Validate ---
$workerQrService     = new \App\Domain\Workers\WorkerQrService(
    $qrTokenizer,
    $workerRepoForRoles,
    $workerRoleRepo,
    $workerSessionRepo,
    $roomRepo,
    $roomTypeRepo,
    $accessEventRepo
);
$workerQrController  = new WorkerQrController($workerQrService);

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
$qrController = new QrController($qrIssueService, $qrValidateService, $qrCredRepo);

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

$exitRuleEvaluator = new ExitRuleEvaluator($iotSessionRepo, $roomRepo, $roomTypeRepo);
$exitActionService = new ExitActionService(
    $roomRepo, $iotSessionRepo, $stayStateMachine, $accessEventRepo, $switchService, $anomalyService,
    $workerSessionRepo
);
$iotSessionService = new IotSessionService(
    $presenceEventRepo,
    $iotSessionRepo,
    $roomRepo,
    $roomTypeRepo,
    $stayRepo,
    $stayStateMachine,
    $accessEventRepo,
    $exitRuleEvaluator,
    $switchService,    // RF-16: turn on light on door open
    $exitActionService, // F28: shared exit action service
    $anomalyService,    // F35: anomaly detection
    $workerSessionRepo  // F38: worker sessions in exit rule
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
        return $qrTestCtrl->roomsReset($request);
    }
);

// ── Simula: presence sensor range toggle (dev tool, no auth) ──
define('SIMULA_DEVICE_ID', 'bf98d27d79685e38a2wbda');

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
                : (($dps['presence_state'] ?? 'none') === 'presence' ? 'PRESENT' : 'ABSENT'),
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
$router->get('/panel',            [$crmController, 'panel'],      [$crmSessionMw]);
$router->get('/panel/{path}',     [$crmController, 'panelAsset'], [$crmSessionMw]);

// --- SSE event stream (F33x) — bypass middleware for direct streaming ---
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($requestPath === '/dashboard-api/event-stream' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $roomId = (int) ($_GET['room_id'] ?? 1);
    if ($roomId > 0) {
        (new EventStreamController($pdo))->stream($roomId);
        // stream() calls exit() — execution ends here for SSE clients
    }
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
