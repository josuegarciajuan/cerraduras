<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Lock;

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use App\Support\Errors\UpstreamException;
use App\Support\Resilience\CircuitBreaker;

/**
 * TuyaLockGateway: sends open/lock commands to a real Tuya smart lock via
 * the Tuya Cloud API (Central Europe data center).
 *
 * Adapts the HMAC-SHA256 signing and token management logic from
 * api/bin/tuya-lock-server.php.
 *
 * DP commands (WBR3/jtmspro lock model):
 *   open → remote_no_pd_setkey
 *   lock → arming_switch = true
 *
 * Device lookup: the gateway reads the Tuya device_id (external_id)
 * from the devices table (kind=LOCK) for the given room.
 *
 * See design.md §2.4, TSK-200.
 */
final class TuyaLockGateway implements LockGatewayInterface
{
    private const PROVIDER = 'TUYA';

    private DeviceRepositoryInterface $deviceRepo;

    /** @var string|null Cached access token. */
    private static $cachedToken  = null;

    /** @var int Timestamp when the token expires. */
    private static $tokenExpiry = 0;

    /** @var CircuitBreaker|null Shared circuit breaker for Tuya API. */
    private static $breaker = null;

    public function __construct(DeviceRepositoryInterface $deviceRepo)
    {
        $this->deviceRepo = $deviceRepo;
    }

    private function tuyaEnv(string $key): string
    {
        return $_ENV[$key] ?? getenv($key) ?: '';
    }

    public function open(int $roomId, array $ctx = []): array
    {
        return $this->executeCommand($roomId, 'open');
    }

    public function lock(int $roomId, array $ctx = []): array
    {
        return $this->executeCommand($roomId, 'lock');
    }

    public function getProvider(): string
    {
        return self::PROVIDER;
    }

    // -----------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------

    /**
     * Resolve the Tuya device_id from the devices table.
     *
     * @throws UpstreamException if no LOCK device is registered for the room.
     */
    private function resolveDeviceId(int $roomId): string
    {
        $device = $this->deviceRepo->findForRoomKind($roomId, Device::KIND_LOCK);
        if ($device === null || $device->externalId === '' || $device->externalId === null) {
            throw new UpstreamException(
                'No Tuya LOCK device registered for room',
                ['room_id' => $roomId]
            );
        }
        return (string) $device->externalId;
    }

    /**
     * Execute a command against the Tuya Cloud API.
     *
     * @param string $action  'open' or 'lock'.
     * @return array{ok:bool, provider:string, error:string|null}
     */
    private function executeCommand(int $roomId, string $action): array
    {
        // Circuit breaker — reject early if Tuya API is unavailable
        if (self::$breaker === null) {
            self::$breaker = new CircuitBreaker('tuya-api', 5, 60);
        }
        if (!self::$breaker->isAvailable()) {
            return [
                'ok'       => false,
                'provider' => self::PROVIDER,
                'error'    => 'Circuit breaker OPEN — Tuya API temporarily unavailable',
            ];
        }

        try {
            $deviceId = $this->resolveDeviceId($roomId);
        } catch (UpstreamException $e) {
            return ['ok' => false, 'provider' => self::PROVIDER, 'error' => $e->getMessage()];
        }

        $code  = ($action === 'open') ? 'remote_no_pd_setkey' : 'arming_switch';
        $value = ($action === 'open') ? 'AAAB' : true;

        try {
            [$httpCode, $data, $elapsed] = $this->sendCommand($deviceId, $code, $value);
        } catch (\RuntimeException $e) {
            self::$breaker->reportFailure();
            return [
                'ok'       => false,
                'provider' => self::PROVIDER,
                'error'    => 'Tuya auth/network error: ' . $e->getMessage(),
            ];
        }

        $success = $data['success'] ?? false;
        $error   = null;
        if (!$success) {
            $error = 'Tuya API returned HTTP ' . $httpCode . ': ' . ($data['msg'] ?? 'unknown');
            self::$breaker->reportFailure();
        } else {
            self::$breaker->reportSuccess();
        }

        error_log(sprintf(
            '[TuyaLockGateway] %s room=%d device=%s → HTTP %d %s (%dms)',
            $action, $roomId, $deviceId, $httpCode,
            $success ? 'OK' : 'FAIL', $elapsed
        ));

        return ['ok' => $success, 'provider' => self::PROVIDER, 'error' => $error];
    }

    // -----------------------------------------------------------------
    // Tuya Cloud API helpers (adapted from tuya-lock-server.php)
    // -----------------------------------------------------------------

    private function tuyaSign(string $method, string $path, array $params, string $body, string $accessToken): array
    {
        $contentSha = hash('sha256', $body);
        $strToSign  = $method . "\n" . $contentSha . "\n" . "\n" . $path;
        if (!empty($params)) {
            ksort($params);
            $strToSign .= '?' . http_build_query($params);
        }
        $t       = (string)(int)(microtime(true) * 1000);
        $message = $this->tuyaEnv('TUYA_ACCESS_ID') . $accessToken . $t . $strToSign;
        $sign    = strtoupper(hash_hmac('sha256', $message, $this->tuyaEnv('TUYA_ACCESS_SECRET')));
        return [$sign, $t, $contentSha];
    }

    private function getAccessToken(): string
    {
        $now = time();
        if (self::$cachedToken !== null && $now < self::$tokenExpiry) {
            return self::$cachedToken;
        }

        [$sign, $t, $contentSha] = $this->tuyaSign('GET', '/v1.0/token', ['grant_type' => '1'], '', '');

        $ch = curl_init($this->tuyaEnv('TUYA_BASE_URL') . '/v1.0/token?grant_type=1');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 10,
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_HTTPHEADER      => [
                "client_id: " . $this->tuyaEnv('TUYA_ACCESS_ID'),
                "sign: $sign",
                "sign_method: HMAC-SHA256",
                "t: $t",
                "Content-SHA256: $contentSha",
            ],
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException('Tuya token: curl error — ' . $err);
        }

        $data = json_decode($body, true);
        if (!($data['success'] ?? false) || $httpCode !== 200) {
            throw new \RuntimeException('Tuya token: ' . ($data['msg'] ?? 'unknown'));
        }

        self::$cachedToken = $data['result']['access_token'];
        self::$tokenExpiry = $now + (int)($data['result']['expire_time'] ?? 7200) - 60;
        return self::$cachedToken;
    }

    /**
     * Send a DP command to the Tuya device.
     *
     * @param mixed $value
     * @return array{0:int, 1:array, 2:int}  [httpCode, responseData, elapsedMs]
     */
    private function sendCommand(string $deviceId, string $code, $value): array
    {
        $token = $this->getAccessToken();

        $body = json_encode(['commands' => [['code' => $code, 'value' => $value]]]);

        [$sign, $t, $contentSha] = $this->tuyaSign(
            'POST',
            "/v1.0/iot-03/devices/$deviceId/commands",
            [],
            $body,
            $token
        );

        $ch = curl_init($this->tuyaEnv('TUYA_BASE_URL') . "/v1.0/iot-03/devices/$deviceId/commands");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 8,
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $body,
            CURLOPT_HTTPHEADER      => [
                "client_id: " . $this->tuyaEnv('TUYA_ACCESS_ID'),
                "sign: $sign",
                "sign_method: HMAC-SHA256",
                "t: $t",
                "Content-Type: application/json",
                "Content-SHA256: $contentSha",
                "access_token: $token",
            ],
        ]);

        $start   = microtime(true);
        $resp    = curl_exec($ch);
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($resp, true);
        return [$httpCode, is_array($data) ? $data : ['success' => false, 'msg' => 'invalid_response'], $elapsed];
    }
}
