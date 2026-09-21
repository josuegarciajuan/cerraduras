<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Switch;

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use App\Support\Errors\UpstreamException;
use App\Support\Resilience\CircuitBreaker;

/**
 * TuyaSwitchGateway: sends on/off commands to a real Tuya smart switch
 * (EAWCBT-J Smart Electricity Protector) via the Tuya Cloud API.
 *
 * Adapts the HMAC-SHA256 signing and token management logic from
 * TuyaLockGateway.
 *
 * DP commands for EAWCBT-J:
 *   on  → switch_1 = true
 *   off → switch_1 = false
 *
 * The DP code can be overridden via device meta_json.dp_code.
 *
 * Device lookup: the gateway reads the Tuya device_id (external_id)
 * from the devices table (kind=SWITCH) for the given room.
 *
 * See design.md §2.4b, RF-16, TSK-SW4.
 */
final class TuyaSwitchGateway implements SwitchGatewayInterface
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

    public function turnOn(int $roomId, array $ctx = []): array
    {
        return $this->executeCommand($roomId, true);
    }

    public function turnOff(int $roomId, array $ctx = []): array
    {
        return $this->executeCommand($roomId, false);
    }

    public function turnOnForDevice(Device $device, array $ctx = []): array
    {
        return $this->executeCommandForDevice($device, true);
    }

    public function turnOffForDevice(Device $device, array $ctx = []): array
    {
        return $this->executeCommandForDevice($device, false);
    }

    public function getProvider(): string
    {
        return self::PROVIDER;
    }

    /**
     * Interpret a raw Tuya Cloud response into an observable, classified result.
     *
     * Pure and side-effect free so the failure taxonomy can be unit-tested
     * without network access. When `success` is truthy the command was accepted;
     * otherwise the failure is classified so the caller can tell CUOTA
     * (429 / exhaust / 28841105 / 28841107), device OFFLINE (2008) or an invalid
     * DP (`dp`/`not exist`/`invalid`/`code not` / 1106) apart.
     *
     * @param array<string,mixed> $data respuesta JSON de Tuya
     * @return array{ok:bool, code:int|string|null, msg:string|null, category:string}
     *   category ∈ ok|quota|offline|dp_invalid|unknown
     */
    public static function classifyResponse(int $httpCode, array $data): array
    {
        $success = (bool) ($data['success'] ?? false);

        $rawCode = $data['code'] ?? null;
        $code    = (is_int($rawCode) || is_string($rawCode)) ? $rawCode : null;
        $codeNum = (is_int($rawCode) || (is_string($rawCode) && is_numeric($rawCode)))
            ? (int) $rawCode
            : null;

        $rawMsg = $data['msg'] ?? null;
        $msg    = is_string($rawMsg) ? $rawMsg : (is_scalar($rawMsg) ? (string) $rawMsg : null);

        if ($success) {
            return ['ok' => true, 'code' => $code, 'msg' => $msg, 'category' => 'ok'];
        }

        $needle = $msg !== null ? strtolower($msg) : '';

        // Cuota: HTTP 429, mensaje de agotamiento o códigos de cuota IoT Core.
        if ($httpCode === 429
            || $codeNum === 28841105
            || $codeNum === 28841107
            || ($needle !== '' && (
                str_contains($needle, 'quota')
                || str_contains($needle, 'exhaust')
                || str_contains($needle, 'limit')
            ))
        ) {
            return ['ok' => false, 'code' => $code, 'msg' => $msg, 'category' => 'quota'];
        }

        // Dispositivo offline.
        if ($codeNum === 2008
            || ($needle !== '' && (
                str_contains($needle, 'offline')
                || str_contains($needle, 'device is offline')
            ))
        ) {
            return ['ok' => false, 'code' => $code, 'msg' => $msg, 'category' => 'offline'];
        }

        // DP inválido / inexistente.
        if ($codeNum === 1106
            || ($needle !== '' && (
                preg_match('/\bdp\b/', $needle) === 1
                || str_contains($needle, 'not exist')
                || str_contains($needle, 'invalid')
                || str_contains($needle, 'code not')
            ))
        ) {
            return ['ok' => false, 'code' => $code, 'msg' => $msg, 'category' => 'dp_invalid'];
        }

        return ['ok' => false, 'code' => $code, 'msg' => $msg, 'category' => 'unknown'];
    }

    // -----------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------

    /**
     * Resolve the Tuya device_id and DP code from the devices table.
     *
     * @return array{0: int, 1: string, 2: string, 3: array<string,mixed>} [internalDbId, externalDeviceId, dpCode, existingMeta]
     * @throws UpstreamException if no SWITCH device is registered for the room.
     */
    private function resolveDevice(int $roomId): array
    {
        $device = $this->deviceRepo->findForRoomKind($roomId, Device::KIND_SWITCH);
        if ($device === null || $device->externalId === '' || $device->externalId === null) {
            throw new UpstreamException(
                'No Tuya SWITCH device registered for room',
                ['room_id' => $roomId]
            );
        }

        // Default DP code; overridable via meta_json
        $dpCode = 'switch';
        $existingMeta = is_array($device->meta) ? $device->meta : [];
        if (isset($existingMeta['dp_code'])) {
            $dpCode = (string) $existingMeta['dp_code'];
        }

        return [$device->id, (string) $device->externalId, $dpCode, $existingMeta];
    }

    /**
     * Execute a command against the Tuya Cloud API.
     *
     * @param bool $turnOn true = on, false = off
     * @return array{ok:bool, provider:string, error:string|null}
     */
    private function executeCommand(int $roomId, bool $turnOn): array
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
            [$internalId, $deviceId, $dpCode, $existingMeta] = $this->resolveDevice($roomId);
        } catch (UpstreamException $e) {
            return ['ok' => false, 'provider' => self::PROVIDER, 'error' => $e->getMessage()];
        }

        try {
            [$httpCode, $data, $elapsed] = $this->sendCommand($deviceId, $dpCode, $turnOn);
        } catch (\RuntimeException $e) {
            self::$breaker->reportFailure();
            return [
                'ok'       => false,
                'provider' => self::PROVIDER,
                'error'    => 'Tuya auth/network error: ' . $e->getMessage(),
            ];
        }

        $classification = self::classifyResponse($httpCode, $data);
        $success        = $classification['ok'];
        $error          = null;
        if (!$success) {
            $error = self::describeError($httpCode, $classification);
            self::$breaker->reportFailure();
        } else {
            self::$breaker->reportSuccess();
        }

        $action  = $turnOn ? 'on' : 'off';
        $codeStr = $classification['code'] === null ? '-' : (string) $classification['code'];
        $msgStr  = $classification['msg'] === null ? '-' : $classification['msg'];
        error_log(sprintf(
            '[TuyaSwitchGateway] %s room=%d device=%s dp=%s → HTTP %d %s code=%s msg=%s category=%s (%dms)',
            $action, $roomId, $deviceId, $dpCode, $httpCode,
            $success ? 'OK' : 'FAIL', $codeStr, $msgStr, $classification['category'], $elapsed
        ));

        // Persist last_command (success) or the classified error (failure) so the
        // dashboard can tell CUOTA / OFFLINE / DP inválido apart.
        $this->persistMeta($internalId, $existingMeta, $turnOn, $classification, $error);

        return ['ok' => $success, 'provider' => self::PROVIDER, 'error' => $error];
    }

    /**
     * Execute a command for a known Device, bypassing the room-based
     * findForRoomKind lookup entirely. The Device object already carries
     * the Tuya device_id (externalId) and DP code (meta_json.dp_code).
     *
     * Used by the pack-based SwitchService::turnOnByPack / turnOffByPack.
     */
    private function executeCommandForDevice(Device $device, bool $turnOn): array
    {
        $internalId  = $device->id;
        $deviceId    = (string) $device->externalId;
        $existingMeta = is_array($device->meta) ? $device->meta : [];
        $dpCode      = isset($existingMeta['dp_code']) ? (string) $existingMeta['dp_code'] : 'switch';

        if ($deviceId === '') {
            return ['ok' => false, 'provider' => self::PROVIDER, 'error' => 'Device has no external_id'];
        }

        // Circuit breaker
        if (self::$breaker === null) {
            self::$breaker = new CircuitBreaker('tuya-api', 5, 60);
        }
        if (!self::$breaker->isAvailable()) {
            return ['ok' => false, 'provider' => self::PROVIDER, 'error' => 'Circuit breaker OPEN'];
        }

        try {
            [$httpCode, $data, $elapsed] = $this->sendCommand($deviceId, $dpCode, $turnOn);
        } catch (\RuntimeException $e) {
            self::$breaker->reportFailure();
            return [
                'ok'       => false,
                'provider' => self::PROVIDER,
                'error'    => 'Tuya auth/network error: ' . $e->getMessage(),
            ];
        }

        $classification = self::classifyResponse($httpCode, $data);
        $success        = $classification['ok'];
        $error          = null;
        if (!$success) {
            $error = self::describeError($httpCode, $classification);
            self::$breaker->reportFailure();
        } else {
            self::$breaker->reportSuccess();
        }

        $action  = $turnOn ? 'on' : 'off';
        $codeStr = $classification['code'] === null ? '-' : (string) $classification['code'];
        $msgStr  = $classification['msg'] === null ? '-' : $classification['msg'];
        error_log(sprintf(
            '[TuyaSwitchGateway] %s (pack-based) device=%s dp=%s → HTTP %d %s code=%s msg=%s category=%s (%dms)',
            $action, $deviceId, $dpCode, $httpCode,
            $success ? 'OK' : 'FAIL', $codeStr, $msgStr, $classification['category'], $elapsed
        ));

        // Persist last_command (success) or the classified error (failure).
        $this->persistMeta($internalId, $existingMeta, $turnOn, $classification, $error);

        return ['ok' => $success, 'provider' => self::PROVIDER, 'error' => $error];
    }

    /**
     * Build the human-readable error returned to callers (includes code + msg).
     *
     * @param array{ok:bool, code:int|string|null, msg:string|null, category:string} $c
     */
    private static function describeError(int $httpCode, array $c): string
    {
        $code = $c['code'] === null ? '-' : (string) $c['code'];
        $msg  = $c['msg'] === null ? 'unknown' : $c['msg'];
        return sprintf('Tuya API returned HTTP %d [%s] code=%s msg=%s', $httpCode, $c['category'], $code, $msg);
    }

    /**
     * Best-effort persistence of the last command result in devices.meta_json.
     *
     * Success keeps last_command/commanded_at and clears the error block;
     * failure records last_error/last_error_at/last_error_category.
     *
     * @param array<string,mixed> $existingMeta
     * @param array{ok:bool, code:int|string|null, msg:string|null, category:string} $classification
     */
    private function persistMeta(
        int $internalId,
        array $existingMeta,
        bool $turnOn,
        array $classification,
        ?string $error
    ): void {
        try {
            $merged = $existingMeta;
            if ($classification['ok']) {
                $merged['last_command'] = $turnOn ? 'ON' : 'OFF';
                $merged['commanded_at'] = gmdate('Y-m-d\TH:i:s.v\Z');
                unset($merged['last_error'], $merged['last_error_at'], $merged['last_error_category']);
            } else {
                $merged['last_error']          = $error ?? 'Tuya command failed';
                $merged['last_error_at']       = gmdate('Y-m-d\TH:i:s.v\Z');
                $merged['last_error_category'] = $classification['category'];
            }
            $this->deviceRepo->patch($internalId, [
                'meta_json' => json_encode($merged, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            // Swallow: persistence failure must never fail the command
            error_log('[TuyaSwitchGateway] Failed to persist switch meta for device ' . $internalId . ': ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // Tuya Cloud API helpers (adapted from TuyaLockGateway)
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
     * @param bool $value true = on, false = off
     * @return array{0:int, 1:array, 2:int}  [httpCode, responseData, elapsedMs]
     */
    private function sendCommand(string $deviceId, string $dpCode, bool $value): array
    {
        $token = $this->getAccessToken();

        $body = json_encode(['commands' => [['code' => $dpCode, 'value' => $value]]]);

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
