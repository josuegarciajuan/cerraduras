#!/usr/bin/env php
<?php

declare(strict_types=1);
require __DIR__ . '/_env.php';

$accessId     = $_ENV['TUYA_ACCESS_ID'] ?? getenv('TUYA_ACCESS_ID') ?: '';
$accessSecret = $_ENV['TUYA_ACCESS_SECRET'] ?? getenv('TUYA_ACCESS_SECRET') ?: '';
$baseUrl      = $_ENV['TUYA_BASE_URL'] ?? getenv('TUYA_BASE_URL') ?: 'https://openapi.tuyaeu.com';
$deviceId     = 'bf4c7e7d2cef28cea2nkwk';

$tunnelUrl   = trim(file_get_contents('/tmp/tunnel_url.txt'));
$webhookUrl  = $tunnelUrl . '/api/v1/tuya/webhook';

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
    $contentSha = hash('sha256', $body);
    $strToSign  = $method . "\n" . $contentSha . "\n" . "\n" . $path;
    if (!empty($params)) {
        ksort($params);
        $strToSign .= '?' . http_build_query($params);
    }
    $t       = (string)(int)(microtime(true) * 1000);
    $message = $accessId . $accessToken . $t . $strToSign;
    $sign    = strtoupper(hash_hmac('sha256', $message, $accessSecret));

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
    curl_close($ch);

    $data = json_decode($respBody, true) ?: ['raw' => $respBody];
    return [$httpCode, $data];
}

// Get token
[$code, $data] = tuyaCall('GET', '/v1.0/token', ['grant_type' => '1'], '', $accessId, '', $accessSecret, $baseUrl);
if (!($data['success'] ?? false)) {
    echo "FAIL: Token error\n"; exit(1);
}
$token = $data['result']['access_token'];
echo "Token OK\n\n";

// Try device-specific push config path
$endpoints = [
    // Device message push config
    'POST /v1.0/devices/' . $deviceId . '/push-config' => json_encode(['url' => $webhookUrl]),
    'POST /v1.0/iot-03/devices/' . $deviceId . '/push-config' => json_encode(['url' => $webhookUrl]),
    // Message center alternatives
    'POST /v1.0/iot-01/message-center/push-url' => json_encode(['url' => $webhookUrl]),
    'POST /v1.0/iot-03/message-center/push-url' => json_encode(['url' => $webhookUrl]),
    // Notification config
    'POST /v1.0/iot-03/operations/notification/url' => json_encode(['url' => $webhookUrl, 'type' => 'DEVICE_STATUS']),
    // Try the generic developer message service
    'POST /v1.0/iot-03/webhooks/custom' => json_encode([
        'url' => $webhookUrl,
        'events' => ['DEVICE_REPORT_STATUS']
    ]),
];

echo "Trying alternative endpoints for message push...\n";
foreach ($endpoints as $key => $body) {
    [$method, $path] = explode(' ', $key, 2);
    [$code, $data] = tuyaCall($method, $path, [], $body, $accessId, $token, $accessSecret, $baseUrl);
    echo "  $method $path → HTTP $code: " . ($data['msg'] ?? ($data['success'] ? 'OK' : 'N/A')) . "\n";
    if ($data['success'] ?? false) {
        echo "  ✅ Success! Response: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
        break;
    }
}
