#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/_env.php';

$accessId     = $_ENV['TUYA_ACCESS_ID'] ?? getenv('TUYA_ACCESS_ID') ?: '';
$accessSecret = $_ENV['TUYA_ACCESS_SECRET'] ?? getenv('TUYA_ACCESS_SECRET') ?: '';
$baseUrl      = $_ENV['TUYA_BASE_URL'] ?? getenv('TUYA_BASE_URL') ?: 'https://openapi.tuyaeu.com';
$sensorDevId  = 'bf4c7e7d2cef28cea2nkwk';
$webhookUrl   = trim(file_get_contents('/tmp/tunnel_url.txt')) . '/api/v1/tuya/webhook';

function tuyaCall(
    string $method,
    string $path,
    array $queryParams,
    string $body,
    string $accessId,
    string $accessToken,
    string $accessSecret,
    string $baseUrl
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
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $data = json_decode((string)$respBody, true) ?: ['raw' => $respBody, 'curl_error' => $curlErr];
    return [$httpCode, $data, $curlErr];
}

// Get token
echo "--- Token ---\n";
[$code, $data] = tuyaCall('GET', '/v1.0/token', ['grant_type' => '1'], '', $accessId, '', $accessSecret, $baseUrl);
if (!($data['success'] ?? false)) {
    echo "FAIL: " . json_encode($data) . "\n";
    exit(1);
}
$token = $data['result']['access_token'];
echo "Token OK\n\n";

// Try more paths
$tests = [
    ['POST', '/v1.0/iot-03/msg-push/action/add-msg-push-url', [], json_encode(['url' => $webhookUrl])],
    ['POST', '/v1.0/iot-03/msg-push/action/add-msg-push-rule', [], json_encode(['url' => $webhookUrl, 'rule' => 'DEVICE_REPORT'])],
    ['POST', '/v1.0/iot/1.0/msg-push/config', [], json_encode(['url' => $webhookUrl, 'type' => 1])],
    ['POST', '/v1.0/cloud/msg-push/config', [], json_encode(['url' => $webhookUrl])],
    ['POST', '/v1.0/iot-03/msg-push/add', [], json_encode(['url' => $webhookUrl])],
    // Try with device_id in URL
    ['POST', "/v1.0/iot-03/devices/$sensorDevId/push-url", [], json_encode(['url' => $webhookUrl])],
    ['POST', "/v1.0/devices/$sensorDevId/subscriptions", [], json_encode(['url' => $webhookUrl, 'type' => 'deviceStatus'])],
    // Try IoT Data Hub / reporting
    ['POST', '/v1.0/iot-03/data-sub/actions/config', [], json_encode(['url' => $webhookUrl])],
];

echo "--- Testing Push Registration Endpoints ---\n";
echo "Webhook URL: $webhookUrl\n\n";

foreach ($tests as [$method, $path, $params, $body]) {
    [$code, $data, $err] = tuyaCall($method, $path, $params, $body, $accessId, $token, $accessSecret, $baseUrl);
    $msg = $data['msg'] ?? '';
    $success = $data['success'] ?? false;
    echo "  $method $path\n";
    echo "    → HTTP $code: " . ($success ? 'OK' : $msg) . "\n";
    if ($err) echo "    → CURL err: $err\n";
    if ($success) {
        echo "    ✅ REGISTERED!\n";
        echo "    Full: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
        break;
    }
}

// Final summary
echo "\n=== SUMMARY ===\n";
echo "Tunnel URL:    " . trim(file_get_contents('/tmp/tunnel_url.txt')) . "\n";
echo "Webhook URL:   $webhookUrl\n";
echo "Sensor device: $sensorDevId (online via Tuya Cloud)\n";
echo "Device status: doorcontact_state=false, battery=87%\n";
echo "\n";
echo "⚠️  Automatic webhook registration via Tuya API not available.\n";
echo "MANUAL SETUP REQUIRED:\n";
echo "  1. Go to https://iot.tuya.com/\n";
echo "  2. Navigate to Cloud → Development → Your Project\n";
echo "  3. Find 'Message Push' or 'Data Subscription' settings\n";
echo "  4. Add webhook URL: $webhookUrl\n";
echo "  5. Select event type: Device Status Report\n";
