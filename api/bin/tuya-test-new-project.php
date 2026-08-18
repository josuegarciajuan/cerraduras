#!/usr/bin/env php
<?php
declare(strict_types=1);

// ── NUEVAS CREDENCIALES (cuenta nueva: admin@nouesmalt.com) — vía variables de entorno ──
$accessId     = getenv('TUYA_ACCESS_ID') ?: '';
$accessSecret = getenv('TUYA_ACCESS_SECRET') ?: '';
$baseUrl      = 'https://openapi.tuyaeu.com';

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
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    $respBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return [$httpCode, ['error' => 'curl_error', 'msg' => $curlErr], $respBody];
    }

    $data = json_decode((string)$respBody, true) ?: ['raw' => $respBody];
    return [$httpCode, $data, $respBody];
}

echo "═══════════════════════════════════════════════\n";
echo " TEST NUEVO PROYECTO TUYA: prueba2 (cuenta nueva)\n";
echo " ACCESS_ID: $accessId\n";
echo "═══════════════════════════════════════════════\n\n";

// ── 1. OBTENER TOKEN ──
echo ">>> 1. GET TOKEN\n";
[$code, $data, $raw] = tuyaCall('GET', '/v1.0/token', ['grant_type' => '1'], '', $accessId, '', $accessSecret, $baseUrl);
echo "HTTP $code\n";
echo "Response: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

if (!($data['success'] ?? false)) {
    echo "⛔ ERROR: No se pudo obtener token. Abortando.\n";
    exit(1);
}
$token = $data['result']['access_token'];
$expire = $data['result']['expire_time'] ?? '?';
echo "✅ Token OK (expira en {$expire}s)\n\n";

// ── 2. LISTAR DISPOSITIVOS ──
echo ">>> 2. LIST DEVICES\n";
[$code, $data, $raw] = tuyaCall('GET', '/v1.0/iot-03/devices', ['page_size' => '50'], '', $accessId, $token, $accessSecret, $baseUrl);
echo "HTTP $code\n";
echo "Response: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

// ── 3. DETALLE DEL SWITCH ──
$switchDevId = 'bfc8730a715c56c8d1paby';
echo ">>> 3. DEVICE DETAIL: $switchDevId\n";
[$code, $data, $raw] = tuyaCall('GET', "/v1.0/iot-03/devices/$switchDevId", [], '', $accessId, $token, $accessSecret, $baseUrl);
echo "HTTP $code\n";
echo "Response: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

// ── 4. ESTADO ACTUAL ──
echo ">>> 4. DEVICE STATUS: $switchDevId\n";
[$code, $data, $raw] = tuyaCall('GET', "/v1.0/iot-03/devices/$switchDevId/status", [], '', $accessId, $token, $accessSecret, $baseUrl);
echo "HTTP $code\n";
echo "Response: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

// ── 5. ENCENDER/APAGAR VARIAS VECES ──
$cycles = 3;
for ($i = 1; $i <= $cycles; $i++) {
    // ENCENDER
    echo ">>> 5.{$i}a TURN ON (intento $i)\n";
    $body = json_encode(['commands' => [['code' => 'switch', 'value' => true]]]);
    [$code, $data, $raw] = tuyaCall('POST', "/v1.0/iot-03/devices/$switchDevId/commands", [], $body, $accessId, $token, $accessSecret, $baseUrl);
    echo "HTTP $code\n";
    echo "FULL RAW RESPONSE: " . $raw . "\n";
    echo "Parsed: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    sleep(2);

    // APAGAR
    echo ">>> 5.{$i}b TURN OFF (intento $i)\n";
    $body = json_encode(['commands' => [['code' => 'switch', 'value' => false]]]);
    [$code, $data, $raw] = tuyaCall('POST', "/v1.0/iot-03/devices/$switchDevId/commands", [], $body, $accessId, $token, $accessSecret, $baseUrl);
    echo "HTTP $code\n";
    echo "FULL RAW RESPONSE: " . $raw . "\n";
    echo "Parsed: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    sleep(2);
}

// ── 6. ESTADO FINAL ──
echo ">>> 6. FINAL STATUS: $switchDevId\n";
[$code, $data, $raw] = tuyaCall('GET', "/v1.0/iot-03/devices/$switchDevId/status", [], '', $accessId, $token, $accessSecret, $baseUrl);
echo "HTTP $code\n";
echo "Response: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

echo "═══════════════════════════════════════════════\n";
echo " TEST COMPLETADO\n";
echo "═══════════════════════════════════════════════\n";
