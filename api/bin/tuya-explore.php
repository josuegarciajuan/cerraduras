#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/_env.php';

$accessId     = $_ENV['TUYA_ACCESS_ID'] ?? getenv('TUYA_ACCESS_ID') ?: '';
$accessSecret = $_ENV['TUYA_ACCESS_SECRET'] ?? getenv('TUYA_ACCESS_SECRET') ?: '';
$baseUrl      = $_ENV['TUYA_BASE_URL'] ?? getenv('TUYA_BASE_URL') ?: 'https://openapi.tuyaeu.com';

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
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $respBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$respBody, true) ?: ['raw' => $respBody];
    return [$httpCode, $data];
}

// Get token
[$code, $data] = tuyaCall('GET', '/v1.0/token', ['grant_type' => '1'], '', $accessId, '', $accessSecret, $baseUrl);
if (!($data['success'] ?? false)) {
    echo "FAIL: Token error: " . json_encode($data) . "\n";
    exit(1);
}
$token = $data['result']['access_token'];
echo "Token OK\n\n";

// List devices
[$code, $data] = tuyaCall('GET', '/v1.0/iot-03/devices', [], '', $accessId, $token, $accessSecret, $baseUrl);
echo "=== Devices ===\n";
echo "HTTP $code: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

// Check device detail to see if push is already configured
$sensorDevId = 'bf4c7e7d2cef28cea2nkwk';
[$code, $data] = tuyaCall('GET', "/v1.0/iot-03/devices/$sensorDevId", [], '', $accessId, $token, $accessSecret, $baseUrl);
echo "=== Device $sensorDevId ===\n";
echo "HTTP $code: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

// Check device status
[$code, $data] = tuyaCall('GET', "/v1.0/iot-03/devices/$sensorDevId/status", [], '', $accessId, $token, $accessSecret, $baseUrl);
echo "=== Device Status ===\n";
echo "HTTP $code: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

// Try various push-related GET endpoints
$paths = [
    '/v1.0/iot-03/msg-push/config',
    '/v1.0/iot-01/msg-push/actions/query-msg-push-rule',
    '/v1.0/iot-03/applications',
    '/v1.0/iot-03/projects',
];

foreach ($paths as $p) {
    [$code, $data] = tuyaCall('GET', $p, [], '', $accessId, $token, $accessSecret, $baseUrl);
    echo "GET $p → HTTP $code: " . ($data['msg'] ?? ($data['success'] ? 'OK' : json_encode($data))) . "\n";
}
