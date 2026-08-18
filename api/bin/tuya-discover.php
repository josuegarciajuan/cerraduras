#!/usr/bin/env php
<?php

/**
 * Tuya Device Discovery — Paso 1 de integración Tuya IoT
 *
 * Usa las credenciales del proyecto para:
 *  1. Obtener access token de Tuya Cloud
 *  2. Listar todos los dispositivos vinculados (vía associated-users/devices)
 *  3. Mostrar device_id, nombre, modelo, categoría y estado de cada uno
 *
 * Uso:
 *   php api/bin/tuya-discover.php <access_id> <access_secret> [base_url]
 *
 * Ejemplos:
 *   php api/bin/tuya-discover.php vwt9mp... bc3e1a...                         # Central Europe
 *   php api/bin/tuya-discover.php wnfftpek... <secret> https://openapi.tuyacn.com  # China DC
 */

if ($argc < 3) {
    echo "Uso: php tuya-discover.php <access_id> <access_secret> [base_url]\n";
    echo "  base_url por defecto: https://openapi.tuyaeu.com\n";
    exit(1);
}

$accessId     = $argv[1];
$accessSecret = $argv[2];
$baseUrl      = $argv[3] ?? 'https://openapi.tuyaeu.com';

echo "═══ Tuya Device Discovery ═══\n";
echo "  Access ID : $accessId\n";
echo "  Base URL  : $baseUrl\n\n";

// ──────────────────────────────────────────────────────────
// Signing algorithm: Tuya official SDK (Python → PHP)
// Path: tuya-iot-python-sdk/tuya_iot/openapi.py → _calculate_sign
// ──────────────────────────────────────────────────────────
function tuyaSignFull(
    string $method,
    string $path,
    array $params,
    string $body,
    string $accessId,
    string $accessToken,
    string $accessSecret
): array {
    // Content-SHA256
    $contentSha = hash('sha256', $body);

    // str_to_sign = METHOD \n SHA256(body) \n "" \n path
    $strToSign = $method . "\n" . $contentSha . "\n" . "\n" . $path;

    // Append sorted query params
    if (!empty($params)) {
        ksort($params);
        $strToSign .= '?';
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = "$k=$v";
        }
        $strToSign .= implode('&', $parts);
    }

    // message = access_id + access_token + t + str_to_sign
    $t = (string)(int)(microtime(true) * 1000);
    $message = $accessId . $accessToken . $t . $strToSign;

    $sign = strtoupper(hash_hmac('sha256', $message, $accessSecret));

    return [$sign, $t, $strToSign];
}

function tuyaRequest(
    string $method,
    string $path,
    array $params,
    string $body,
    string $accessId,
    string $accessToken,
    string $accessSecret,
    string $baseUrl
): array {
    [$sign, $t, $strToSign] = tuyaSignFull(
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
    ];
    if ($accessToken !== '') {
        $headers[] = "access_token: $accessToken";
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [$httpCode, $responseBody, $error, $sign, $t, $strToSign];
}

// ──────────────────────────────────────────────────────────
// Step 1: Get access token
// ──────────────────────────────────────────────────────────
echo "═══ Paso 1: Obtener access token ═══\n";

[$httpCode, $body, $error, $sign, $t, $strToSign] = tuyaRequest(
    'GET', '/v1.0/token',
    ['grant_type' => '1'],
    '',
    $accessId, '', $accessSecret, $baseUrl
);

echo "  str_to_sign: $strToSign\n";
echo "  sign: $sign\n";
echo "  t: $t\n\n";

if ($error) {
    echo "  ❌ Error curl: $error\n";
    exit(1);
}

echo "  HTTP $httpCode\n";
$tokenData = json_decode($body, true);
echo "  Response: " . json_encode($tokenData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

if (!($tokenData['success'] ?? false)) {
    $msg = $tokenData['msg'] ?? 'unknown';
    $code = $tokenData['code'] ?? 'N/A';
    echo "  ❌ Fallo al obtener token: [$code] $msg\n";
    echo "     Verifica que Access ID, Secret y base_url sean correctos.\n";
    exit(1);
}

$accessToken = $tokenData['result']['access_token'];
$uid = $tokenData['result']['uid'] ?? 'N/A';
$expireSec = $tokenData['result']['expire_time'] ?? 0;
echo "  ✅ Token OK. Expira en {$expireSec}s | UID: $uid\n";
echo "     Token: " . substr($accessToken, 0, 30) . "...\n\n";

// ──────────────────────────────────────────────────────────
// Step 2: List devices via associated-users endpoint
// ──────────────────────────────────────────────────────────
echo "═══ Paso 2: Listar dispositivos (associated-users) ═══\n";

[$httpCode2, $body2, $error2] = tuyaRequest(
    'GET', '/v1.0/iot-01/associated-users/devices',
    ['last_row_key' => ''],
    '',
    $accessId, $accessToken, $accessSecret, $baseUrl
);

if ($error2) {
    echo "  ❌ Error curl: $error2\n";
    exit(1);
}

echo "  HTTP $httpCode2\n";
$deviceData = json_decode($body2, true);

if (!($deviceData['success'] ?? false)) {
    echo "  ❌ Fallo al listar dispositivos.\n";
    echo "  Response: " . json_encode($deviceData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

$devices = $deviceData['result']['devices'] ?? $deviceData['result']['list'] ?? $deviceData['result'] ?? [];
$total = $deviceData['result']['total'] ?? count($devices);

echo "  ✅ Encontrados $total dispositivo(s):\n\n";

if (empty($devices)) {
    echo "  ⚠️  No hay dispositivos vinculados a este proyecto.\n";
    exit(0);
}

// ──────────────────────────────────────────────────────────
// Step 3: Display device details
// ──────────────────────────────────────────────────────────
echo str_repeat('─', 95) . "\n";
foreach ($devices as $i => $device) {
    $n = $i + 1;
    $id       = $device['id']        ?? 'N/A';
    $name     = $device['name']      ?? 'Sin nombre';
    $model    = $device['model']     ?? 'N/A';
    $prodName = $device['product_name'] ?? '';
    $category = $device['category']  ?? $device['category_name'] ?? 'N/A';
    $online   = ($device['online'] ?? false) ? '🟢 ONLINE' : '🔴 OFFLINE';
    $sub      = ($device['sub'] ?? false) ? ' (Sub-device)' : '';
    $ip       = $device['ip'] ?? 'N/A';
    $localKey = $device['local_key'] ?? '(no disponible)';
    $prodId   = $device['product_id'] ?? 'N/A';
    $uuid     = $device['uuid'] ?? 'N/A';

    // Detect lock by category or product name
    $isLock = (
        stripos($category, 'lock') !== false ||
        stripos($category, 'jtms') !== false ||
        stripos($prodName, '锁') !== false ||
        stripos($name, 'lock') !== false ||
        stripos($name, 'cerradura') !== false
    );
    $tag = $isLock ? ' 🔐 CERRAUDRA' : '';

    echo "  [$n] $name$tag\n";
    echo "      device_id   : $id\n";
    echo "      Modelo      : $model" . ($prodName ? " — $prodName" : '') . "\n";
    echo "      Categoría   : $category$sub\n";
    echo "      Product ID  : $prodId\n";
    echo "      UUID        : $uuid\n";
    echo "      Estado      : $online\n";
    echo "      IP          : $ip\n";
    echo "      Local Key   : $localKey\n";

    // Show status codes (DPs) if available
    if (!empty($device['status'] ?? [])) {
        echo "      DPs/Status  :\n";
        foreach ($device['status'] as $st) {
            $val = is_string($st['value']) ? $st['value'] : json_encode($st['value'], JSON_UNESCAPED_UNICODE);
            if (strlen($val) > 60) {
                $val = substr($val, 0, 60) . '...';
            }
            echo "        • {$st['code']} = $val\n";
        }
    }
    echo str_repeat('─', 95) . "\n";
}

// ──────────────────────────────────────────────────────────
// Summary for copy-paste
// ──────────────────────────────────────────────────────────
echo "\n═══ Resumen para integración ═══\n";
printf("%-26s | %-25s | %-15s | %-12s | %s\n", 'Device ID', 'Nombre', 'Categoría', 'Estado', 'Modelo');
echo str_repeat('─', 110) . "\n";
foreach ($devices as $device) {
    $id   = $device['id']       ?? 'N/A';
    $name = $device['name']     ?? 'Sin nombre';
    $cat  = $device['category'] ?? 'N/A';
    $on   = ($device['online'] ?? false) ? 'ONLINE' : 'OFFLINE';
    $mod  = $device['model']    ?? 'N/A';
    printf("%-26s | %-25s | %-15s | %-12s | %s\n", $id, $name, $cat, $on, $mod);
}

echo "\n✅ Descubrimiento completado.\n";
echo "   Copia el device_id de la cerradura y lo usamos en el siguiente script.\n";
