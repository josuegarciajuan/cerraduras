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
use App\Http\Controllers\QrRejectionController;
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

// ── F46+: presupuesto de cuota Tuya COMPARTIDO con el poller (Node) ──────────
// Mismo fichero `api/run/tuya-quota.json` que escribe `tuya-presence-poller.js`.
// Una sola fuente de verdad: ni la app ni el poller agotan la cuota (el
// 2026-09-17 una puerta atascada agotó la cuota con ~1.400 llamadas/hora).
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

    // F46+: presupuesto compartido (mismo fichero que el poller Node).
    $qchk = tuyaQuotaCheck();
    if (!$qchk['ok']) {
        return ['http' => 429, 'data' => [], 'error' => 'Tuya quota budget (' . $qchk['reason'] . ')', 'elapsed_ms' => 0];
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
        // Detect quota exhaustion and set backoff
        $code = $data['code'] ?? 0;
        if ($code === 28841004 || stripos($data['msg'] ?? '', 'quota') !== false) {
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
/** Cuántos segundos conserva una sonda Tuya real antes de considerarse "sin verificar". */
const TUYA_PROBE_TTL_SECONDS = 600;

function deviceIsEsp32(array $dev): bool {
    return in_array((string) ($dev['kind'] ?? ''), DEVICE_KIND_ESP32, true);
}

function deviceIsTuyaCloud(array $dev): bool {
    return in_array((string) ($dev['kind'] ?? ''), DEVICE_KIND_TUYA, true);
}

/**
 * Sonda real de conectividad de un dispositivo Tuya cloud (GET /devices/{id}).
 * Devuelve true/false si la API respondió con un result.online válido, o null
 * si Tuya no pudo confirmar (error HTTP/red/cuota). NO debe llamarse en polls.
 */
function probeTuyaOnlineOnce(\PDO $pdo, int $deviceId, string $externalId): ?bool {
    if ($externalId === '') {
        return null;
    }
    // F46+: los devices con `presence_source='disabled'` NO se sondean (0 cuota).
    try {
        $m = $pdo->prepare('SELECT meta_json FROM devices WHERE id = :id');
        $m->execute([':id' => $deviceId]);
        $mj = $m->fetchColumn();
        if (is_string($mj) && $mj !== '') {
            $meta = json_decode($mj, true);
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
    $state = $online ? 1 : 0;
    $stmt = $pdo->prepare(
        'UPDATE devices SET online_state = :st, online_probed_at = UTC_TIMESTAMP(3) WHERE id = :did'
    );
    $stmt->execute([':st' => $state, ':did' => $deviceId]);
    return $online;
}

/**
 * @return array{state:string, online:bool|null} estado verídico de un dispositivo
 *   (usado por /dashboard-api/device-status). Nunca devuelve online=true sin una
 *   señal real (heartbeat/check para ESP32, sonda Tuya fresca para Tuya cloud).
 */
function resolveDeviceOnlineState(array $dev, int $probeTtlSeconds): array {
    $kind = (string) ($dev['kind'] ?? '');
    if (!deviceIsTuyaCloud(['kind' => $kind])) {
        // ESP32/local: estado desde last_seen_at (heartbeat/check) fresco.
        if (($dev['last_seen_at'] ?? null) === null) {
            return ['state' => 'unknown', 'online' => null];
        }
        $online = (bool) ($dev['online'] ?? false); // SQL ya calcula la ventana de 10 min
        return ['state' => $online ? 'online' : 'offline', 'online' => $online];
    }

    // F46++: dispositivos con PUSH de Tuya (PROXIMITY siempre; PRESENCE con
    // presence_source='push'): un `last_seen_at` fresco es señal REAL de
    // conectividad (el dispositivo acaba de enviar un mensaje por Pulsar).
    // Evita el "desconocido" permanente cuando la sonda Tuya no está disponible
    // (p. ej. cuota IoT Core agotada) sin falsear verde: si deja de reportar,
    // last_seen envejece y vuelve a 'unknown'.
    $meta   = is_array($dev['meta'] ?? null) ? $dev['meta'] : [];
    $isPush = ($kind === 'PROXIMITY') || (($meta['presence_source'] ?? null) === 'push');
    if ($isPush && !empty($dev['online'])) {
        return ['state' => 'online', 'online' => true];
    }

    // Tuya cloud: estado desde la última SONDA real, si no está caducada.
    $probedAt = $dev['online_probed_at'] ?? null;
    $stateRaw = $dev['online_state'] ?? null;
    $fresh = false;
    if ($probedAt !== null) {
        $probedTs = strtotime((string) $probedAt . ' UTC');
        if ($probedTs === false) {
            $probedTs = strtotime((string) $probedAt);
        }
        $fresh = $probedTs !== false && (time() - $probedTs) <= $probeTtlSeconds;
    }
    if ($stateRaw === null || !$fresh) {
        return ['state' => 'unknown', 'online' => null]; // no verificado aún o sonda vieja
    }
    $online = ((int) $stateRaw === 1);
    return ['state' => $online ? 'online' : 'offline', 'online' => $online];
}

/**
 * Resolve the PRESENCE sensor device assigned to a room (canonical room→pack→device chain).
 * Returns null when the room has no presence sensor (e.g. room without a pack, or a pack
 * without a PRESENCE device). Mirrors the discovery used by /dashboard-api/device-status.
 */
function resolvePresenceDeviceForRoom(\PDO $pdo, int $roomId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT d.id, d.kind, d.external_id, d.label, d.meta_json, d.last_seen_at, d.battery_pct,
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
    $row['online']  = (bool) ($row['online'] ?? false);
    $row['meta']    = !empty($row['meta_json']) ? json_decode($row['meta_json'], true) : null;
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
// F41 (contracts.md §5): 6 workers with expected/instances/pids/healthy/degraded.
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
            'presence-poller-manager'=> ['label' => 'Gestor Poller Presencia',  'run_key' => 'presence-poller-manager', 'pattern' => 'bin/presence-poller-manager.sh', 'systemd_unit' => 'cerraduras-presence-poller'],
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
        // Devices of the pack with online status (canonical F30: device → pack).
        $devStmt = $pdo->prepare(
            "SELECT d.id, d.kind, d.external_id, d.label, d.meta_json, d.last_seen_at, d.battery_pct,
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
$qrRejectionController = new QrRejectionController($pdo);
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

        // Resolve devices via pack chain (canonical model: device → pack → room).
        // Use MySQL DATE_SUB for consistent timezone. The SQL `online` column is a
        // freshness hint used ONLY for ESP32/local devices (last_seen heartbeat).
        $rows = $pdo->prepare(
            "SELECT d.id, d.kind, d.external_id, d.label, d.meta_json, d.last_seen_at,
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

            $d['tuya_cloud'] = deviceIsTuyaCloud($d);

            if (deviceIsTuyaCloud($d)) {
                // Tuya cloud: estado verídico desde la sonda real (online_state/probed_at).
                $st = resolveDeviceOnlineState($d, TUYA_PROBE_TTL_SECONDS);
                $d['state']  = $st['state'];
                $d['online'] = $st['online'];
                $d['online_state']     = ($d['online_state'] ?? null);
                $d['online_probed_at'] = ($d['online_probed_at'] ?? null);
                continue;
            }

            // ESP32 / local: estado desde last_seen (heartbeat/check fresco).
            $st = resolveDeviceOnlineState($d, TUYA_PROBE_TTL_SECONDS);
            $d['state']  = $st['state'];
            $d['online'] = $st['online'];
            $d['online_state']     = null;
            $d['online_probed_at'] = null;

            // Heredar liveness del RPI a sub-dispositivos ESP32 del mismo hardware
            // (solo marcador informativo; LOCK/SCANNER además se confirman por pull).
            if ($rpiOnline && in_array($d['kind'], $inheritOnlineKinds, true) && !$d['online'] && $d['kind'] !== 'RPI') {
                $d['online'] = true;
                $d['_inherited'] = true;
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

        $hasPending = $commandQueueRepo->hasPending($extId);

        // Opportunistic cleanup: bound the queue while the chip is active.
        // Only runs when there is a backlog, so the DELETE is infrequent.
        if ($hasPending) {
            $commandQueueRepo->purgeStale(24);
        }

        return \App\Http\Response::json(200, [
            'ok' => true,
            'has_pending_commands' => $hasPending,
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
        // Solo se sondea Tuya cloud (consume cuota) cuando el cliente lo pide
        // explícitamente (carga del panel / botón "Comprobar"). Sin la flag no se
        // quema cuota: los dispositivos Tuya se devuelven como 'unknown' (sin verificar).
        $verifyTuya = !empty($body['verify_tuya']);
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
                    'online' => null,
                    'state' => 'unknown',
                    'found' => false,
                    '_pending_check' => false,
                ];
                continue;
            }

            $kind = (string) $dev->kind;
            $isPullKind = in_array($kind, [
                \App\Domain\Devices\Device::KIND_SCANNER,
                \App\Domain\Devices\Device::KIND_LOCK,
            ], true);

            if (deviceIsTuyaCloud(['kind' => $kind])) {
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
                    ];
                    continue;
                }
                // Tuya cloud (PRESENCE/PROXIMITY/SWITCH): sonda REAL de conectividad.
                // Consume cuota Tuya → este endpoint solo lo invocan la carga del panel
                // y el botón "Comprobar dispositivos", nunca el poll automático.
                $probe = probeTuyaOnlineOnce($pdo, $deviceId, (string) $dev->externalId);
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
                    ];
                }
                continue;
            }

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
                    'state' => 'unknown',
                    'found' => true,
                    '_pending_check' => $queuedId !== null,
                    'checked_via' => 'PULL',
                    'external_id' => $owner,
                ];
            } else {
                // RPI: self-beacons vía /dashboard-api/device-heartbeat. No hay comando
                // que encolar; se reporta online (heartbeat) y el poll de device-status
                // reconcilia con last_seen real en segundos.
                $results[] = [
                    'device_id' => $deviceId,
                    'online' => true,
                    'state' => 'online',
                    'found' => true,
                    '_pending_check' => false,
                    'checked_via' => 'HEARTBEAT',
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

$exitRuleEvaluator = new ExitRuleEvaluator($iotSessionRepo, $roomRepo, $roomTypeRepo, $stayRepo);
$exitActionService = new ExitActionService(
    $roomRepo, $iotSessionRepo, $stayStateMachine, $accessEventRepo, $switchService, $anomalyService,
    $workerSessionRepo,
    $stayRepo,            // F41: lockActiveForRoom() under the exit transaction
    $exitRuleEvaluator,   // F41: re-validates the rule under the lock
    $roomTypeRepo         // F41: cooldown config for executeIfPending()
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
// the domain presence_state only flips via webhook/poller and can lag several
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
