#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/_env.php';

/**
 * Diagnóstico de Message Push / Pulsar para dispositivos Tuya.
 *
 * Verifica:
 *   1. Configuración global de push
 *   2. Estado de push por dispositivo (presencia + puerta)
 *   3. Intenta binding de dispositivos al servicio de mensajes
 *   4. Prueba endpoints alternativos de registro
 *
 * Uso: php api/bin/tuya-push-diag.php
 */

$accessId     = $_ENV['TUYA_ACCESS_ID'] ?? getenv('TUYA_ACCESS_ID') ?: '';
$accessSecret = $_ENV['TUYA_ACCESS_SECRET'] ?? getenv('TUYA_ACCESS_SECRET') ?: '';
$baseUrl      = $_ENV['TUYA_BASE_URL'] ?? getenv('TUYA_BASE_URL') ?: 'https://openapi.tuyaeu.com';
$presenceDevId = 'bf98d27d79685e38a2wbda'; // ZY-M100-5
$doorDevId     = 'bf4c7e7d2cef28cea2nkwk'; // MC400D
$lockDevId     = 'bfafd3f2013c4b1876f5g5';  // SmartLock

// ─── Colores ───────────────────────────────────────────────────────
function g(string $t): string  { return "\033[32m$t\033[0m"; }
function r(string $t): string  { return "\033[31m$t\033[0m"; }
function y(string $t): string  { return "\033[33m$t\033[0m"; }
function d(string $t): string  { return "\033[2m$t\033[0m"; }
function b(string $t): string  { return "\033[1m$t\033[0m"; }

// ─── Tuya API ────────────────────────────────────────────────────────
function tuyaCall(
    string $method, string $path, array $queryParams, string $body,
    string $accessId, string $accessToken, string $accessSecret, string $baseUrl
): array {
    $contentSha = hash('sha256', $body);
    $strToSign  = $method . "\n" . $contentSha . "\n" . "\n" . $path;
    if (!empty($queryParams)) {
        ksort($queryParams);
        $strToSign .= '?' . http_build_query($queryParams);
    }
    $t       = (string)(int)(microtime(true) * 1000);
    $message = $accessId . $accessToken . $t . $strToSign;
    $sign    = strtoupper(hash_hmac('sha256', $message, $accessSecret));

    $url = $baseUrl . $path;
    if (!empty($queryParams)) {
        $url .= '?' . http_build_query($queryParams);
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
        CURLOPT_TIMEOUT         => 12,
        CURLOPT_CONNECTTIMEOUT  => 8,
        CURLOPT_HTTPHEADER      => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $start    = microtime(true);
    $respBody = curl_exec($ch);
    $elapsed  = round((microtime(true) - $start) * 1000);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $data = json_decode((string)$respBody, true) ?: ['raw' => $respBody];
    return [$httpCode, $data, $curlErr, $elapsed];
}

// ─── Main ──────────────────────────────────────────────────────────

echo b("═══ Diagnóstico Tuya Message Push / Pulsar ═══") . "\n\n";

// Step 1: Token
echo d("--- Token ---") . "\n";
[$code, $data, $err] = tuyaCall('GET', '/v1.0/token', ['grant_type' => '1'], '', $accessId, '', $accessSecret, $baseUrl);
if (!($data['success'] ?? false)) {
    echo r("❌ Token falló: " . ($data['msg'] ?? 'unknown')) . "\n";
    exit(1);
}
$token = $data['result']['access_token'];
echo g("✅ Token OK") . "\n\n";

// ================================================================
// SECTION 1: Global push configuration
// ================================================================
echo b("═══ 1. Configuración global de push ═══") . "\n\n";

$configPaths = [
    'GET /iot-03/msg-push/config'            => ['GET', '/v1.0/iot-03/msg-push/config', []],
    'GET /iot-01/msg-push/actions/query*'    => ['GET', '/v1.0/iot-01/msg-push/actions/query-msg-push-rule', []],
    'GET /iot-03/msg-push/actions/list'      => ['GET', '/v1.0/iot-03/msg-push/actions/list', []],
    'GET /iot-03/msg-push/rules'             => ['GET', '/v1.0/iot-03/msg-push/rules', []],
    'GET /iot-03/msg-push/status'            => ['GET', '/v1.0/iot-03/msg-push/status', []],
    'GET /iot/1.0/msg-push/config'           => ['GET', '/v1.0/iot/1.0/msg-push/config', []],
];

foreach ($configPaths as $label => [$method, $path, $params]) {
    [$code, $data, $err, $ms] = tuyaCall($method, $path, $params, '', $accessId, $token, $accessSecret, $baseUrl);
    $status = ($data['success'] ?? false) ? g('OK') : r('FAIL');
    $msg    = $data['msg'] ?? '';
    $info   = $data['success'] ? json_encode($data['result'] ?? $data, JSON_UNESCAPED_UNICODE) : "($msg)";
    if (strlen($info) > 200) $info = substr($info, 0, 200) . '...';
    echo "  $label\n";
    echo "    → HTTP $code $status [{$ms}ms] $info\n\n";
}

// ================================================================
// SECTION 2: Per-device push status
// ================================================================
echo b("═══ 2. Estado de push por dispositivo ═══") . "\n\n";

$devices = [
    'ZY-M100 (presencia)' => $presenceDevId,
    'MC400D (puerta)'     => $doorDevId,
    'SmartLock'           => $lockDevId,
];

foreach ($devices as $name => $devId) {
    echo "  📱 $name ($devId)\n";

    // Check 1: device push status endpoint
    ["/v1.0/iot-03/msg-push/devices/$devId/status"];
    $paths = [
        ["GET", "/v1.0/iot-03/msg-push/devices/$devId/status", []],
        ["GET", "/v1.0/iot-03/msg-push/devices/$devId", []],
        ["GET", "/v1.0/iot-03/devices/$devId/report-channel", []],
        ["GET", "/v1.0/iot-01/msg-push/devices/$devId", []],
    ];

    foreach ($paths as [$method, $path, $params]) {
        [$code, $data, $err, $ms] = tuyaCall($method, $path, $params, '', $accessId, $token, $accessSecret, $baseUrl);
        $status = ($data['success'] ?? false) ? g('✓') : r('✗');
        $msg = $data['msg'] ?? '';
        $short = basename(str_replace("/$devId", '', $path));
        $result = $data['success'] ? json_encode($data['result'] ?? $data, JSON_UNESCAPED_UNICODE) : $msg;
        if (strlen($result) > 150) $result = substr($result, 0, 150) . '...';
        echo "      $status $short → HTTP $code [{$ms}ms] $result\n";
    }
    echo "\n";
}

// ================================================================
// SECTION 3: Try device binding to enable push
// ================================================================
echo b("═══ 3. Intento de binding de dispositivos ═══") . "\n\n";

foreach ($devices as $name => $devId) {
    echo "  📱 $name ($devId)\n";

    $bindingPaths = [
        ["POST /iot-03/msg-push/devices/binding",                        'POST', '/v1.0/iot-03/msg-push/devices/binding',                        json_encode(['device_id' => $devId])],
        ["POST /iot-03/msg-push/devices/binding (array)",                'POST', '/v1.0/iot-03/msg-push/devices/binding',                        json_encode(['device_ids' => [$devId]])],
        ["POST /iot-03/msg-push/actions/bind-device",                    'POST', '/v1.0/iot-03/msg-push/actions/bind-device',                    json_encode(['device_id' => $devId])],
        ["POST /iot-03/msg-push/actions/subscribe-device",               'POST', '/v1.0/iot-03/msg-push/actions/subscribe-device',               json_encode(['device_id' => $devId])],
        ["POST /iot-03/msg-push/action/add-msg-push-rule (device)",      'POST', '/v1.0/iot-03/msg-push/action/add-msg-push-rule',              json_encode(['device_id' => $devId, 'rule' => 'DEVICE_REPORT'])],
        ["POST /iot-03/msg-push/device/bind",                            'POST', '/v1.0/iot-03/msg-push/device/bind',                            json_encode(['device_id' => $devId])],
        ["POST /iot-03/devices/{id}/enable-msg",                         'POST', "/v1.0/iot-03/devices/$devId/enable-msg",                       '{}'],
        ["POST /iot-03/devices/{id}/subscribe",                          'POST', "/v1.0/iot-03/devices/$devId/subscribe",                        json_encode(['type' => 'status'])],
    ];

    $anySuccess = false;
    foreach ($bindingPaths as [$label, $method, $path, $body]) {
        [$code, $data, $err, $ms] = tuyaCall($method, $path, [], $body, $accessId, $token, $accessSecret, $baseUrl);
        $success = $data['success'] ?? false;
        $status  = $success ? g('✅') : r('✗');
        $msg     = $data['msg'] ?? '';
        $detail  = $success ? g('BOUND') : $msg;
        echo "      $status $label → HTTP $code [{$ms}ms] $detail\n";
        if ($success) $anySuccess = true;
    }
    echo $anySuccess ? g("      ✅ Binding exitoso para $name\n\n") : r("      ❌ Ningún endpoint de binding funcionó para $name\n\n");
}

// ================================================================
// SECTION 4: Check API product subscriptions
// ================================================================
echo b("═══ 4. Suscripciones de API products ═══") . "\n\n";

$subPaths = [
    ['GET', '/v1.0/iot-03/applications', []],
    ['GET', '/v1.0/iot-03/projects', []],
    ['GET', '/v1.0/iot-03/api-products', []],
    ['GET', '/v1.0/iot-03/api-products/subscriptions', []],
];

foreach ($subPaths as [$method, $path, $params]) {
    [$code, $data, $err, $ms] = tuyaCall($method, $path, $params, '', $accessId, $token, $accessSecret, $baseUrl);
    $status = ($data['success'] ?? false) ? g('OK') : r('FAIL');
    $result = $data['success'] ? json_encode($data['result'] ?? $data, JSON_UNESCAPED_UNICODE) : ($data['msg'] ?? 'N/A');
    if (strlen($result) > 250) $result = substr($result, 0, 250) . '...';
    echo "  $method $path → HTTP $code $status\n    $result\n\n";
}

// ================================================================
// Final summary
// ================================================================
echo b("\n═══ RESUMEN ═══") . "\n";
echo "Si todos los endpoints devuelven 404/1106/PERMISSION_DENIED:\n";
echo "  → El API product 'Message Service' NO está suscrito al proyecto.\n";
echo "  → SOLUCIÓN MANUAL:\n";
echo "    1. Ir a https://iot.tuya.com/\n";
echo "    2. Cloud → Development → [Proyecto] → Service API\n";
echo "    3. Buscar 'Message Service' y suscribirlo\n";
echo "    4. Esperar 2-5 minutos\n";
echo "    5. Volver a ejecutar este script\n";
echo "\nSi algún binding devolvió success:\n";
echo "  → Pulsar debería empezar a recibir mensajes en ~2 min.\n";
echo "  → Ejecuta: node api/bin/tuya-presence-listen.js\n";
