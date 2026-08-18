#!/usr/bin/env php
<?php

declare(strict_types=1);
require __DIR__ . '/_env.php';

/**
 * Servidor HTTP para control de cerradura Tuya vía API Cloud.
 *
 * Flujo correcto (WBR3/jtmspro):
 *   1. Alguien pulsa el timbre en la cerradura → unlock_request cambia a 1
 *   2. Llamar a /approve → envía remote_no_pd_setkey=AAAB (aprobación)
 *   3. La cerradura se abre
 *
 * Endpoints:
 *   GET  /              → página principal con enlaces
 *   GET  /status        → estado completo de la cerradura
 *   GET  /status-json   → estado en JSON (para scripts)
 *   GET  /check-request → solo devuelve unlock_request y doorbell
 *   GET  /approve       → aprueba solicitud pendiente y abre
 *   GET  /close         → cierra (arming_switch=true)
 *   GET  /poll          → espera hasta detectar solicitud y auto-aprueba (SSE)
 *
 * Uso:
 *   bash /root/cerraduras/start-tuya-lock-server.sh
 */

// ─── Credenciales Tuya ──────────────────────────────────────────────
$accessId     = $_ENV['TUYA_ACCESS_ID'] ?? getenv('TUYA_ACCESS_ID') ?: '';
$accessSecret = $_ENV['TUYA_ACCESS_SECRET'] ?? getenv('TUYA_ACCESS_SECRET') ?: '';
$baseUrl      = $_ENV['TUYA_BASE_URL'] ?? getenv('TUYA_BASE_URL') ?: 'https://openapi.tuyaeu.com';
$deviceId     = 'bfafd3f2013c4b1876f5g5';

// ─── Cache de token en memoria ──────────────────────────────────────
$cachedToken = null;
$tokenExpiry = 0;

// ─── Funciones de firma y llamada Tuya ──────────────────────────────
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
        throw new \RuntimeException('No se pudo obtener token de Tuya: ' .
            ($curlError ?: ($data['msg'] ?? 'desconocido')));
    }
    $cachedToken = $data['result']['access_token'];
    $tokenExpiry = $now + (int)($data['result']['expire_time'] ?? 7200) - 60;
    return $cachedToken;
}

function sendCommand(
    string $code,
    $value,
    string $deviceId,
    string $accessId,
    string $accessSecret,
    string $baseUrl,
    ?string &$cachedToken,
    int &$tokenExpiry
): array {
    $token = getAccessToken($accessId, $accessSecret, $baseUrl, $cachedToken, $tokenExpiry);
    $body  = json_encode(['commands' => [['code' => $code, 'value' => $value]]]);
    return tuyaCall(
        'POST',
        "/v1.0/iot-03/devices/$deviceId/commands",
        [],
        $body,
        $accessId, $token, $accessSecret, $baseUrl
    );
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

// ─── HTML helpers ────────────────────────────────────────────────────
function htmlPage(string $title, string $body, string $extraScript = ''): string {
    $refresh = ($title === '🔄 Esperando timbre...') ? '<meta http-equiv="refresh" content="3">' : '';
    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>$title</title>
$refresh
<style>
  body { font-family: system-ui, sans-serif; max-width: 700px; margin: 40px auto; padding: 0 20px; background: #111; color: #e0e0e0; }
  h1 { color: #f0c040; }
  pre { background: #1a1a2e; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 13px; }
  .ok { color: #4caf50; font-weight: bold; }
  .fail { color: #f44336; }
  .pending { color: #ff9800; font-size: 1.2em; }
  a { color: #64b5f6; }
  .nav { margin: 16px 0; }
  .nav a { display: inline-block; margin: 4px 8px; padding: 8px 16px; background: #333; border-radius: 4px; text-decoration: none; color: #e0e0e0; }
  .nav a:hover { background: #444; }
  .nav a.approve { background: #2e7d32; }
  .nav a.approve:hover { background: #388e3c; }
  .status-box { background: #1a1a2e; padding: 16px; border-radius: 8px; margin: 16px 0; }
  .status-item { margin: 4px 0; }
  .status-label { color: #888; }
</style>
$extraScript
</head>
<body>
<h1>$title</h1>
<pre>$body</pre>
<div class="nav">
  <a href="/">🏠 Inicio</a>
  <a href="/status">📊 Estado</a>
  <a href="/check-request">🔔 Ver solicitud</a>
  <a href="/approve" class="approve">✅ Aprobar y abrir</a>
  <a href="/close">🔒 Cerrar</a>
  <a href="/poll">⏳ Esperar timbre</a>
</div>
</body>
</html>
HTML;
}

// ─── Router ─────────────────────────────────────────────────────────
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = strtok($uri, '?');

// ── / ──
if ($uri === '/') {
    header('Content-Type: text/html; charset=utf-8');
    $body = "🔐 Cerradura: $deviceId\n\n";
    $body .= "FLUJO CORRECTO para abrir:\n";
    $body .= "  1. Alguien pulsa el timbre 🔔 en la cerradura\n";
    $body .= "  2. Visita /check-request o /poll para detectarlo\n";
    $body .= "  3. Pulsa /approve para aprobar la apertura\n";
    echo htmlPage('🏠 Control Cerradura Tuya', $body);
    exit;
}

// ── /status ──
if ($uri === '/status') {
    header('Content-Type: text/html; charset=utf-8');
    [, $data, $curlError, $elapsed] = getDeviceStatus(
        $deviceId, $accessId, $accessSecret, $baseUrl, $cachedToken, $tokenExpiry
    );
    $output = "HTTP | {$elapsed}ms\n";
    if ($curlError) {
        $output .= "ERROR: $curlError\n";
    } else {
        $output .= "──────────────────────────────────────────\n";
        $dps = $data['result'] ?? [];
        foreach ($dps as $dp) {
            $val = json_encode($dp['value'], JSON_UNESCAPED_UNICODE);
            if (strlen($val) > 80) $val = substr($val, 0, 80) . '...';
            // Highlight key DPs
            $marker = '';
            if ($dp['code'] === 'unlock_request' && $dp['value'] != 0) $marker = ' ← ⚡ SOLICITUD PENDIENTE';
            if ($dp['code'] === 'doorbell' && $dp['value']) $marker = ' ← 🔔 TIMBRE';
            $output .= sprintf("  %-25s = %s%s\n", $dp['code'], $val, $marker);
        }
    }
    echo htmlPage('📊 Estado Cerradura', $output);
    exit;
}

// ── /status-json ──
if ($uri === '/status-json') {
    header('Content-Type: application/json; charset=utf-8');
    [, $data, $curlError] = getDeviceStatus(
        $deviceId, $accessId, $accessSecret, $baseUrl, $cachedToken, $tokenExpiry
    );
    if ($curlError) {
        echo json_encode(['error' => $curlError]);
    } else {
        $dps = $data['result'] ?? [];
        $flat = [];
        foreach ($dps as $dp) {
            $flat[$dp['code']] = $dp['value'];
        }
        echo json_encode($flat, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit;
}

// ── /check-request ──
if ($uri === '/check-request') {
    header('Content-Type: text/html; charset=utf-8');
    [, $data, $curlError, $elapsed] = getDeviceStatus(
        $deviceId, $accessId, $accessSecret, $baseUrl, $cachedToken, $tokenExpiry
    );
    $dps = $data['result'] ?? [];
    $unlockRequest = getDpValue($dps, 'unlock_request');
    $doorbell = getDpValue($dps, 'doorbell');

    $output = "HTTP | {$elapsed}ms\n";
    $output .= "──────────────────────────────────────────\n";
    $output .= "  unlock_request = " . json_encode($unlockRequest);
    if ($unlockRequest != 0) {
        $output .= " ← ⚡ SOLICITUD PENDIENTE";
    }
    $output .= "\n";
    $output .= "  doorbell       = " . json_encode($doorbell);
    $output .= "\n\n";

    if ($unlockRequest != 0) {
        $output .= "⚡ HAY UNA SOLICITUD DE APERTURA PENDIENTE\n";
        $output .= "   → Ve a /approve para APROBAR y abrir la cerradura";
    } else {
        $output .= "❌ No hay solicitud pendiente.\n";
        $output .= "   → Pulsa el timbre 🔔 en la cerradura primero";
    }

    echo htmlPage('🔔 Solicitud de Apertura', $output);
    exit;
}

// ── /approve ──
if ($uri === '/approve') {
    header('Content-Type: text/html; charset=utf-8');

    // 1. Leer estado actual para obtener unlock_request y otros DPs clave
    $output = "[1] Leyendo estado actual...\n";
    [, $stData, , $elapsedSt] = getDeviceStatus(
        $deviceId, $accessId, $accessSecret, $baseUrl, $cachedToken, $tokenExpiry
    );
    $dps = $stData['result'] ?? [];
    $unlockReq     = getDpValue($dps, 'unlock_request');
    $doorbell      = getDpValue($dps, 'doorbell');
    $armingSwitch  = getDpValue($dps, 'arming_switch');
    $remoteSetkey  = getDpValue($dps, 'remote_no_pd_setkey');
    $remoteDpKey   = getDpValue($dps, 'remote_no_dp_key');

    $output .= "    unlock_request       = " . json_encode($unlockReq);
    if ($unlockReq != 0) $output .= " ← ⚡ SOLICITUD PENDIENTE";
    $output .= "\n    doorbell             = " . json_encode($doorbell) . "\n";
    $output .= "    arming_switch        = " . json_encode($armingSwitch) . "\n";
    $output .= "    remote_no_pd_setkey  = " . json_encode($remoteSetkey) . "\n";
    $output .= "    remote_no_dp_key     = " . json_encode($remoteDpKey) . "\n";
    $output .= "    ({$elapsedSt}ms)\n\n";

    if ($unlockReq == 0 || $unlockReq === false || $unlockReq === "" || $unlockReq === null) {
        $output .= "❌ No hay solicitud de apertura pendiente.\n";
        $output .= "   → Pulsa primero el timbre 🔔 en la cerradura";
        echo htmlPage('⚠️ Sin solicitud', $output);
        exit;
    }

    // 2. Enviar aprobación — probando múltiples estrategias
    $output .= "[2] Enviando APROBACIÓN...\n";
    $output .= "    unlock_request vale: " . json_encode($unlockReq) . "\n\n";

    // Estrategia A: remote_no_pd_setkey con el valor de unlock_request
    $output .= "    [A] remote_no_pd_setkey = " . json_encode($unlockReq) . " (eco de unlock_request)... ";
    [$hA, $dA, $eA, $msA] = sendCommand(
        'remote_no_pd_setkey', $unlockReq,
        $deviceId, $accessId, $accessSecret, $baseUrl, $cachedToken, $tokenExpiry
    );
    $okA = ($dA['success'] ?? false);
    $output .= "HTTP $hA (" . $msA . "ms) → " . ($okA ? "OK" : "FAIL") . "\n";

    // Estrategia B: remote_no_dp_key con el valor de unlock_request
    $output .= "    [B] remote_no_dp_key    = " . json_encode($unlockReq) . " (eco)... ";
    [$hB, $dB, $eB, $msB] = sendCommand(
        'remote_no_dp_key', $unlockReq,
        $deviceId, $accessId, $accessSecret, $baseUrl, $cachedToken, $tokenExpiry
    );
    $okB = ($dB['success'] ?? false);
    $output .= "HTTP $hB (" . $msB . "ms) → " . ($okB ? "OK" : "FAIL") . "\n";

    // Estrategia C: remote_no_pd_setkey original + desarmar
    $output .= "    [C] desarmar (arming_switch=false)... ";
    [$hC, $dC, $eC, $msC] = sendCommand(
        'arming_switch', false,
        $deviceId, $accessId, $accessSecret, $baseUrl, $cachedToken, $tokenExpiry
    );
    $okC = ($dC['success'] ?? false);
    $output .= "HTTP $hC (" . $msC . "ms) → " . ($okC ? "OK" : "FAIL") . "\n";

    // 3. Verificar resultado
    $output .= "\n[3] Verificando resultado (esperando 3s)...\n";
    sleep(3);
    [, $stAfter, , ] = getDeviceStatus(
        $deviceId, $accessId, $accessSecret, $baseUrl, $cachedToken, $tokenExpiry
    );
    $dpsAfter = $stAfter['result'] ?? [];
    $unlockReqAfter = getDpValue($dpsAfter, 'unlock_request');
    $armingAfter    = getDpValue($dpsAfter, 'arming_switch');
    $setkeyAfter    = getDpValue($dpsAfter, 'remote_no_pd_setkey');
    $dpkeyAfter     = getDpValue($dpsAfter, 'remote_no_dp_key');
    $output .= "    unlock_request       = " . json_encode($unlockReqAfter);
    if ($unlockReqAfter != 0) $output .= " (sigue pendiente)";
    $output .= "\n    arming_switch        = " . json_encode($armingAfter) . "\n";
    $output .= "    remote_no_pd_setkey  = " . json_encode($setkeyAfter) . "\n";
    $output .= "    remote_no_dp_key     = " . json_encode($dpkeyAfter) . "\n";

    $changedSetkey = ($setkeyAfter !== $remoteSetkey);
    $changedDpKey  = ($dpkeyAfter !== $remoteDpKey);
    $changedArming = ($armingAfter !== $armingSwitch);
    $output .= "\n    Cambios detectados:";
    $output .= "\n      remote_no_pd_setkey: " . json_encode($remoteSetkey) . " → " . json_encode($setkeyAfter) . ($changedSetkey ? " ✅" : "");
    $output .= "\n      remote_no_dp_key:    " . json_encode($remoteDpKey) . " → " . json_encode($dpkeyAfter) . ($changedDpKey ? " ✅" : "");
    $output .= "\n      arming_switch:       " . json_encode($armingSwitch) . " → " . json_encode($armingAfter) . ($changedArming ? " ✅" : "");

    $output .= "\n\n✅ APROBACIÓN ENVIADA (estrategias A+B+C)";
    $output .= "\n   Si la cerradura no abrió, necesito ver el valor REAL de unlock_request.";
    $output .= "\n   Ve a /check-request cuando suene el timbre y dime el valor EXACTO.";

    echo htmlPage('✅ Aprobar Apertura', $output);
    exit;
}

// ── /close ──
if ($uri === '/close') {
    header('Content-Type: text/html; charset=utf-8');
    [$httpCode, $data, $curlError, $elapsed] = sendCommand(
        'arming_switch', true,
        $deviceId, $accessId, $accessSecret, $baseUrl,
        $cachedToken, $tokenExpiry
    );
    $success = ($data['success'] ?? false);
    $output  = "COMANDO: arming_switch = true\n";
    $output .= "──────────────────────────────────────────\n";
    $output .= "HTTP $httpCode | {$elapsed}ms\n";
    $output .= json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $output .= "\n\n" . ($success ? "✅ COMANDO ENVIADO OK" : "❌ FALLO");
    echo htmlPage('🔒 Cerrar Cerradura', $output);
    exit;
}

// ── /poll (auto-aprobar cuando suene el timbre) ──
if ($uri === '/poll') {
    // Check current state first
    [, $data, , ] = getDeviceStatus(
        $deviceId, $accessId, $accessSecret, $baseUrl, $cachedToken, $tokenExpiry
    );
    $dps = $data['result'] ?? [];
    $unlockReq = getDpValue($dps, 'unlock_request');

    if ($unlockReq != 0) {
        // Request already pending → auto-approve
        header('Content-Type: text/html; charset=utf-8');
        $output = "⚡ SOLICITUD YA PENDIENTE (unlock_request=$unlockReq)\n";
        $output .= "Enviando aprobación automática...\n\n";
        [$h, $d, $e, $ms] = sendCommand(
            'remote_no_pd_setkey', 'AAAB',
            $deviceId, $accessId, $accessSecret, $baseUrl,
            $cachedToken, $tokenExpiry
        );
        $output .= "HTTP $h | {$ms}ms\n";
        $output .= json_encode($d, JSON_UNESCAPED_UNICODE) . "\n";
        $output .= "\n✅ APROBACIÓN AUTOMÁTICA ENVIADA";
        echo htmlPage('✅ Auto-Aprobar', $output);
        exit;
    }

    // No request yet → page that auto-refreshes every 3 seconds
    header('Content-Type: text/html; charset=utf-8');
    $output = "Esperando a que alguien pulse el timbre...\n";
    $output .= "Esta página se actualiza cada 3s.\n";
    $output .= "Cuando detecte unlock_request≠0, aprobará automáticamente.\n";
    $output .= "\nunlock_request actual: " . json_encode($unlockReq) . "\n";
    $output .= "doorbell actual:       " . json_encode(getDpValue($dps, 'doorbell'));
    echo htmlPage('🔄 Esperando timbre...', $output);
    exit;
}

// 404
header('Content-Type: text/plain; charset=utf-8', true, 404);
echo "404 — Endpoints: / /status /status-json /check-request /approve /close /poll\n";
