#!/usr/bin/env php
<?php

declare(strict_types=1);
require __DIR__ . '/_env.php';

/**
 * Lector de estados del sensor magnético de puerta Tuya (MC400D).
 *
 * Consulta el device status cada ~1.5s y notifica cambios de
 * doorcontact_state (abierta/cerrada) + nivel de batería.
 *
 * Device: Alarma con sensor de puerta
 *   - device_id: bf4c7e7d2cef28cea2nkwk
 *   - modelo:    MC400D (WIFI门磁)
 *   - categoría: mcs
 *
 * Uso:
 *   php api/bin/tuya-door-sensor-read.php
 *
 * Ctrl+C para salir.
 */

// ─── Credenciales Tuya ──────────────────────────────────────────────
$accessId     = $_ENV['TUYA_ACCESS_ID'] ?? getenv('TUYA_ACCESS_ID') ?: '';
$accessSecret = $_ENV['TUYA_ACCESS_SECRET'] ?? getenv('TUYA_ACCESS_SECRET') ?: '';
$baseUrl      = $_ENV['TUYA_BASE_URL'] ?? getenv('TUYA_BASE_URL') ?: 'https://openapi.tuyaeu.com';
$deviceId     = 'bf4c7e7d2cef28cea2nkwk'; // Sensor MC400D

// ─── Cache de token en memoria ──────────────────────────────────────
$cachedToken = null;
$tokenExpiry = 0;

// ─── Funciones de firma y llamada Tuya (mismas que en tuya-lock-server) ───

function tuyaSign(
    string $method,
    string $path,
    array $params,
    string $body,
    string $accessId,
    string $accessToken,
    string $accessSecret
): array {
    $contentSha = hash('sha256', $body);
    $strToSign  = $method . "\n" . $contentSha . "\n" . "\n" . $path;
    if (!empty($params)) {
        ksort($params);
        $strToSign .= '?' . http_build_query($params);
    }
    $t       = (string)(int)(microtime(true) * 1000);
    $message = $accessId . $accessToken . $t . $strToSign;
    $sign    = strtoupper(hash_hmac('sha256', $message, $accessSecret));
    return [$sign, $t, $strToSign, $contentSha];
}

function tuyaCall(
    string $method,
    string $path,
    array $params,
    string $body,
    string $accessId,
    string $accessToken,
    string $accessSecret,
    string $baseUrl
): array {
    [$sign, $t, , $contentSha] = tuyaSign(
        $method, $path, $params, $body,
        $accessId, $accessToken, $accessSecret
    );

    $url = $baseUrl . $path;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $headers = [
        "client_id: $accessId",
        "sign: $sign",
        "sign_method: HMAC-SHA256",
        "t: $t",
        "Content-Type: application/json",
        "Content-SHA256: $contentSha",
    ];
    if ($accessToken !== '') {
        $headers[] = "access_token: $accessToken";
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 8,
        CURLOPT_CONNECTTIMEOUT  => 5,
        CURLOPT_HTTPHEADER      => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $start      = microtime(true);
    $respBody   = curl_exec($ch);
    $elapsed    = round((microtime(true) - $start) * 1000);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError  = curl_error($ch);
    curl_close($ch);

    $data = json_decode($respBody, true);

    return [$httpCode, $data, $curlError, $elapsed, $sign, $t, $url];
}

function getAccessToken(
    string $accessId,
    string $accessSecret,
    string $baseUrl,
    ?string &$cachedToken,
    int &$tokenExpiry
): string {
    $now = time();
    if ($cachedToken !== null && $now < $tokenExpiry) {
        return $cachedToken;
    }
    [, $data, $curlError] = tuyaCall(
        'GET', '/v1.0/token',
        ['grant_type' => '1'],
        '',
        $accessId, '', $accessSecret, $baseUrl
    );
    if ($curlError || !($data['success'] ?? false)) {
        throw new \RuntimeException(
            'No se pudo obtener token de Tuya: ' .
            ($curlError ?: ($data['msg'] ?? 'desconocido'))
        );
    }
    $cachedToken = $data['result']['access_token'];
    $tokenExpiry = $now + (int)($data['result']['expire_time'] ?? 7200) - 60;
    return $cachedToken;
}

function getDeviceStatus(
    string $deviceId,
    string $accessId,
    string $accessSecret,
    string $baseUrl,
    ?string &$cachedToken,
    int &$tokenExpiry
): array {
    $token = getAccessToken($accessId, $accessSecret, $baseUrl, $cachedToken, $tokenExpiry);
    return tuyaCall(
        'GET',
        "/v1.0/iot-03/devices/$deviceId/status",
        [],
        '',
        $accessId, $token, $accessSecret, $baseUrl
    );
}

function getDpValue(array $dps, string $code): mixed {
    foreach ($dps as $dp) {
        if (($dp['code'] ?? '') === $code) {
            return $dp['value'] ?? null;
        }
    }
    return null;
}

// ─── Terminal colors ────────────────────────────────────────────────
function green(string $text): string  { return "\033[32m$text\033[0m"; }
function red(string $text): string    { return "\033[31m$text\033[0m"; }
function yellow(string $text): string { return "\033[33m$text\033[0m"; }
function dim(string $text): string    { return "\033[2m$text\033[0m"; }
function bold(string $text): string   { return "\033[1m$text\033[0m"; }

// ─── Main ───────────────────────────────────────────────────────────

$POLL_INTERVAL_SEC = 1.5; // segundos entre consultas

echo bold("═══ Lector Sensor Puerta Tuya (MC400D) ═══") . "\n";
echo "  Device ID : $deviceId\n";
echo "  Intervalo : {$POLL_INTERVAL_SEC}s\n";
echo "  Ctrl+C para salir\n\n";

echo dim("─── Obteniendo token inicial... ───") . "\n";

try {
    getAccessToken($accessId, $accessSecret, $baseUrl, $cachedToken, $tokenExpiry);
    echo green("✅ Conectado a Tuya Cloud\n\n");
} catch (\RuntimeException $e) {
    echo red("❌ {$e->getMessage()}") . "\n";
    exit(1);
}

$lastDoorState = null;   // null = primera lectura, true = OPEN, false = CLOSED
$errorStreak   = 0;
$seq           = 0;
$startTime     = microtime(true);

while (true) {
    $seq++;

    try {
        [$httpCode, $data, $curlError, $elapsed] = getDeviceStatus(
            $deviceId, $accessId, $accessSecret, $baseUrl,
            $cachedToken, $tokenExpiry
        );

        if ($curlError) {
            $errorStreak++;
            if ($errorStreak <= 3) {
                echo red("  ⚠ Error curl: $curlError") . " [" . yellow("{$elapsed}ms") . "]\n";
            }
            if ($errorStreak === 3) {
                echo dim("  (suprimiendo más errores hasta recuperar conexión)\n");
            }
            usleep((int)($POLL_INTERVAL_SEC * 1_000_000));
            continue;
        }

        $errorStreak = 0; // reset

        if ($httpCode !== 200 || !($data['success'] ?? false)) {
            $msg = $data['msg'] ?? 'unknown';
            echo red("  ⚠ API error HTTP $httpCode: $msg") . " [" . yellow("{$elapsed}ms") . "]\n";
            usleep((int)($POLL_INTERVAL_SEC * 1_000_000));
            continue;
        }

        $dps = $data['result'] ?? [];
        $doorState      = getDpValue($dps, 'doorcontact_state'); // true=abierta, false=cerrada
        $batteryPercent = getDpValue($dps, 'battery_percentage');

        // Formatear timestamp
        $now = new \DateTime('now', new \DateTimeZone('Europe/Madrid'));
        $ts  = $now->format('Y-m-d H:i:s');

        // Primera lectura
        if ($lastDoorState === null) {
            $label = $doorState ? '🔓 ABIERTA' : '🔒 CERRADA';
            echo dim("[$ts]") . " ESTADO INICIAL: " . bold($label);
            echo "  " . dim("[{$elapsed}ms]");
            if ($batteryPercent !== null) {
                echo "  🔋 " . yellow("{$batteryPercent}%");
            }
            echo "\n";
            $lastDoorState = $doorState;
            usleep((int)($POLL_INTERVAL_SEC * 1_000_000));
            continue;
        }

        // Solo notificar si cambió
        if ($doorState !== $lastDoorState) {
            $prior = $lastDoorState ? 'ABIERTA' : 'CERRADA';
            $nowLabel = $doorState ? '🔓 ABIERTA' : '🔒 CERRADA';
            $arrow   = $doorState ? green('→ ABIERTA') : red('→ CERRADA');

            echo "[$ts] $nowLabel  ";
            echo dim("(era $prior)") . " ";
            echo $arrow;
            echo "  " . dim("[{$elapsed}ms]");
            if ($batteryPercent !== null) {
                echo "  🔋 " . yellow("{$batteryPercent}%");
            }
            echo "\n";

            $lastDoorState = $doorState;
        } else {
            // Si el estado no cambió, mostramos un "latido" cada ~20 ciclos
            if ($seq % 20 === 0) {
                $running = round(microtime(true) - $startTime);
                $label = $doorState ? 'ABIERTA' : 'CERRADA';
                echo dim("[$ts] sin cambios ($label) — {$running}s corriendo") . "\n";
            }
        }

    } catch (\RuntimeException $e) {
        echo red("  ❌ {$e->getMessage()}") . "\n";
        usleep((int)(5 * 1_000_000)); // esperar 5s antes de reintentar
        continue;
    }

    usleep((int)($POLL_INTERVAL_SEC * 1_000_000));
}
