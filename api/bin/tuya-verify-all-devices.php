#!/usr/bin/env php
<?php
declare(strict_types=1);

// ── CREDENCIALES NUEVA CUENTA (vía variables de entorno) ──
$accessId     = getenv('TUYA_ACCESS_ID') ?: '';
$accessSecret = getenv('TUYA_ACCESS_SECRET') ?: '';
$baseUrl      = 'https://openapi.tuyaeu.com';

function tuyaCall(
    string $method, string $path, array $params, string $body,
    string $accessId, string $accessToken, string $accessSecret, string $baseUrl
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
    if (!empty($params)) $url .= '?' . http_build_query($params);

    $headers = [
        "client_id: $accessId", "sign: $sign", "sign_method: HMAC-SHA256",
        "t: $t", "Content-Type: application/json", "Content-SHA256: $contentSha",
    ];
    if ($accessToken !== '') $headers[] = "access_token: $accessToken";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $respBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    if ($curlErr) return [$httpCode, ['error' => $curlErr], $respBody];
    $data = json_decode((string)$respBody, true) ?: ['raw' => $respBody];
    return [$httpCode, $data, $respBody];
}

echo "═══════════════════════════════════════════════════════════════\n";
echo " VERIFICACIÓN TODOS LOS DISPOSITIVOS — Cuenta: prueba2\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// ── GET TOKEN ──
echo ">>> TOKEN\n";
[$code, $data, $raw] = tuyaCall('GET', '/v1.0/token', ['grant_type'=>'1'], '', $accessId, '', $accessSecret, $baseUrl);
if (!($data['success'] ?? false)) {
    echo "⛔ TOKEN FAIL: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n"; exit(1);
}
$token = $data['result']['access_token'];
echo "✅ Token OK\n\n";

// ── DEVICES TO VERIFY ──
$devices = [
    ['id' => 'bfafd2013c4b1836f5g5', 'name' => '🔒 LOCK (cerradura)',     'category' => 'Lock'],
    ['id' => 'bf4c7e7d2cef28cea2nkwk', 'name' => '🚪 DOOR SENSOR (puerta)', 'category' => 'Door sensor (MC400D)'],
    ['id' => 'bf98d27d79685e38a2wbda', 'name' => '👁 PRESENCE (presencia)', 'category' => 'Presence (ZY-M100-5)'],
    ['id' => 'bfc8730a715c56c8d1paby', 'name' => '💡 SWITCH (luz)',        'category' => 'Circuit breaker'],
];

foreach ($devices as $dev) {
    $id   = $dev['id'];
    $name = $dev['name'];
    echo "──────────────────────────────────────────────────────────\n";
    echo " $name  →  $id\n";
    echo "──────────────────────────────────────────────────────────\n";

    // Detail
    [$code, $data, $raw] = tuyaCall('GET', "/v1.0/iot-03/devices/$id", [], '', $accessId, $token, $accessSecret, $baseUrl);
    $detailOk = $data['success'] ?? false;
    $online   = $detailOk ? ($data['result']['online'] ? '🟢 ONLINE' : '🔴 OFFLINE') : '❓';
    $model    = $detailOk ? ($data['result']['model'] ?? '?') : '?';
    $nameT    = $detailOk ? ($data['result']['name'] ?? '?') : '?';
    echo "  Detail: " . ($detailOk ? "✅" : "❌") . "  $online  name=\"$nameT\"  model=\"$model\"\n";
    if (!$detailOk) {
        echo "  ⚠ " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    }

    // Status
    [$code, $statusData, $raw] = tuyaCall('GET', "/v1.0/iot-03/devices/$id/status", [], '', $accessId, $token, $accessSecret, $baseUrl);
    $statusOk = $statusData['success'] ?? false;
    echo "  Status: " . ($statusOk ? "✅" : "❌") . "\n";
    if ($statusOk) {
        foreach ($statusData['result'] as $dp) {
            $v = is_bool($dp['value']) ? ($dp['value'] ? 'true' : 'false') : json_encode($dp['value'], JSON_UNESCAPED_UNICODE);
            echo "    DP: {$dp['code']} = $v\n";
        }
    } else {
        echo "  ⚠ " . json_encode($statusData, JSON_UNESCAPED_UNICODE) . "\n";
    }

    echo "\n";
}

// ── LOCK COMMANDS ──
echo "═══════════════════════════════════════════════════════════════\n";
echo " 🧪 LOCK COMMANDS TEST\n";
echo "═══════════════════════════════════════════════════════════════\n";

$lockId = 'bfafd2013c4b1836f5g5';

// Current status first
[$code, $status, $raw] = tuyaCall('GET', "/v1.0/iot-03/devices/$lockId/status", [], '', $accessId, $token, $accessSecret, $baseUrl);
echo "Current lock status: " . json_encode($status, JSON_UNESCAPED_UNICODE) . "\n\n";

// TRY LOCK (arming_switch = true)
echo ">>> LOCK (arming_switch = true)\n";
$body = json_encode(['commands' => [['code' => 'arming_switch', 'value' => true]]]);
[$code, $data, $raw] = tuyaCall('POST', "/v1.0/iot-03/devices/$lockId/commands", [], $body, $accessId, $token, $accessSecret, $baseUrl);
echo "HTTP $code: " . $raw . "\n\n";
sleep(2);

// TRY OPEN (remote_no_pd_setkey = "AAAB")
echo ">>> OPEN (remote_no_pd_setkey = \"AAAB\")\n";
$body = json_encode(['commands' => [['code' => 'remote_no_pd_setkey', 'value' => 'AAAB']]]);
[$code, $data, $raw] = tuyaCall('POST', "/v1.0/iot-03/devices/$lockId/commands", [], $body, $accessId, $token, $accessSecret, $baseUrl);
echo "HTTP $code: " . $raw . "\n\n";
sleep(2);

// Final status
[$code, $status, $raw] = tuyaCall('GET', "/v1.0/iot-03/devices/$lockId/status", [], '', $accessId, $token, $accessSecret, $baseUrl);
echo "Final lock status: " . json_encode($status, JSON_UNESCAPED_UNICODE) . "\n\n";

// ── SWITCH TOGGLE (confirm still works) ──
echo "═══════════════════════════════════════════════════════════════\n";
echo " 🧪 SWITCH ON/OFF (confirm)\n";
echo "═══════════════════════════════════════════════════════════════\n";
$switchId = 'bfc8730a715c56c8d1paby';

echo ">>> SWITCH ON\n";
$body = json_encode(['commands' => [['code' => 'switch', 'value' => true]]]);
[$code, $data, $raw] = tuyaCall('POST', "/v1.0/iot-03/devices/$switchId/commands", [], $body, $accessId, $token, $accessSecret, $baseUrl);
echo "  " . ($data['success'] ? '✅' : '❌') . " " . $raw . "\n";
sleep(2);

echo ">>> SWITCH OFF\n";
$body = json_encode(['commands' => [['code' => 'switch', 'value' => false]]]);
[$code, $data, $raw] = tuyaCall('POST', "/v1.0/iot-03/devices/$switchId/commands", [], $body, $accessId, $token, $accessSecret, $baseUrl);
echo "  " . ($data['success'] ? '✅' : '❌') . " " . $raw . "\n";

echo "\n═══════════════════════════════════════════════════════════════\n";
echo " VERIFICACIÓN COMPLETADA\n";
echo "═══════════════════════════════════════════════════════════════\n";
