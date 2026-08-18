#!/usr/bin/env php
<?php

declare(strict_types=1);
require __DIR__ . '/_env.php';

$accessId     = $_ENV['TUYA_ACCESS_ID'] ?? getenv('TUYA_ACCESS_ID') ?: '';
$accessSecret = $_ENV['TUYA_ACCESS_SECRET'] ?? getenv('TUYA_ACCESS_SECRET') ?: '';
$baseUrl      = $_ENV['TUYA_BASE_URL'] ?? getenv('TUYA_BASE_URL') ?: 'https://openapi.tuyaeu.com';

$tunnelUrl = trim(file_get_contents('/tmp/tunnel_url.txt'));
$webhookUrl = $tunnelUrl . '/api/v1/tuya/webhook';

echo "Tunnel URL:   $tunnelUrl\n";
echo "Webhook URL:  $webhookUrl\n\n";

// ─── Tuya API helpers ────────────────────────────────────────────────
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
    return [$sign, $t];
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
    [$sign, $t] = tuyaSign($method, $path, $params, $body, $accessId, $accessToken, $accessSecret);
    $contentSha = hash('sha256', $body);

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
        CURLOPT_TIMEOUT         => 10,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_HTTPHEADER      => $headers,
    ]);
    if ($method === 'POST' || $method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $respBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $data = json_decode($respBody, true) ?: ['raw' => $respBody];
    return [$httpCode, $data, $curlError];
}

// ─── Step 1: Get access token ─────────────────────────────────────────
echo "[1] Obteniendo access token...\n";
[$code, $data, $err] = tuyaCall('GET', '/v1.0/token', ['grant_type' => '1'], '', $accessId, '', $accessSecret, $baseUrl);
if ($err || !($data['success'] ?? false)) {
    echo "FAIL: No se pudo obtener token: " . ($err ?: ($data['msg'] ?? 'desconocido')) . "\n";
    echo "Response: " . json_encode($data) . "\n";
    exit(1);
}
$token = $data['result']['access_token'];
echo "OK: Token obtenido (expira en " . ($data['result']['expire_time'] ?? '?') . "s)\n\n";

// ─── Step 2: Try to register webhook ──────────────────────────────────
$endpoints = [
    // Modern IoT Cloud (iot-03)
    'POST /v1.0/iot-03/msg-push/actions/add-push-url' => json_encode([
        'url'       => $webhookUrl,
        'push_type' => 'DEVICE_REPORT_STATUS',
    ]),
    // Alternative: message push config
    'POST /v1.0/iot-03/msg-push/config' => json_encode([
        'url'       => $webhookUrl,
        'push_type' => 'DEVICE_REPORT_STATUS',
    ]),
    // Old-style (iot-01)
    'POST /v1.0/iot-01/msg-push/actions/update-msg-push-rule' => json_encode([
        'url'        => $webhookUrl,
        'rule_type'  => 'DEVICE_REPORT_STATUS',
    ]),
    // Another variant with different body format
    'PUT /v1.0/iot-03/msg-push/config' => json_encode([
        'url'       => $webhookUrl,
        'push_type' => 'DEVICE_REPORT_STATUS',
    ]),
    // Direct push config set
    'POST /v1.0/iot-03/actions/set-webhook' => json_encode([
        'url' => $webhookUrl,
    ]),
];

echo "[2] Registrando webhook URL en Tuya...\n";
echo "    Intentando múltiples endpoints...\n\n";

$registered = false;

foreach ($endpoints as $key => $body) {
    [$method, $path] = explode(' ', $key, 2);
    echo "    → $method $path\n";
    echo "      Body: $body\n";

    [$code, $data, $err] = tuyaCall($method, $path, [], $body, $accessId, $token, $accessSecret, $baseUrl);

    echo "      HTTP $code: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";

    if ($err) {
        echo "      Curl error: $err\n";
    }

    $success = $data['success'] ?? false;
    if ($success) {
        echo "      ✅ REGISTRADO\n";
        $registered = true;
        break;
    }

    echo "\n";
}

if ($registered) {
    echo "\n✅ Webhook registrado exitosamente en Tuya IoT Cloud\n";
    echo "   URL: $webhookUrl\n";
} else {
    echo "\n⚠️ No se pudo registrar automáticamente el webhook.\n";
    echo "   Configuración manual necesaria en Tuya IoT Console:\n";
    echo "   1. Ve a https://iot.tuya.com/\n";
    echo "   2. Cloud → Development → Message Push\n";
    echo "   3. Añade esta URL como destino de push:\n";
    echo "      $webhookUrl\n";
    echo "   4. Selecciona 'Device Status Report' como tipo de mensaje\n";
}

// ─── Step 3: Verify current push config ───────────────────────────────
echo "\n[3] Configuración actual de push:\n";
[$code, $data, $err] = tuyaCall('GET', '/v1.0/iot-03/msg-push/config', [], '', $accessId, $token, $accessSecret, $baseUrl);
echo "    GET /v1.0/iot-03/msg-push/config → HTTP $code: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";

// Also try listing push rules
[$code2, $data2, $err2] = tuyaCall('GET', '/v1.0/iot-01/msg-push/actions/query-msg-push-rule', [], '', $accessId, $token, $accessSecret, $baseUrl);
echo "    GET /v1.0/iot-01/msg-push/actions/query-msg-push-rule → HTTP $code2: " . json_encode($data2, JSON_UNESCAPED_UNICODE) . "\n";

echo "\nDone.\n";
