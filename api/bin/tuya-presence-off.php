#!/usr/bin/env php
<?php

declare(strict_types=1);
require __DIR__ . '/_env.php';

/**
 * Apaga/restaura la detección del ZY-M100-5 seteando far_detection=0.
 * Útil para forzar AUSENTE durante pruebas en la mesa de trabajo.
 *
 * Uso:
 *   php api/bin/tuya-presence-off.php
 */

// ─── Credenciales ──────────────────────────────────────────────────
$accessId     = $_ENV['TUYA_ACCESS_ID'] ?? getenv('TUYA_ACCESS_ID') ?: '';
$accessSecret = $_ENV['TUYA_ACCESS_SECRET'] ?? getenv('TUYA_ACCESS_SECRET') ?: '';
$baseUrl      = $_ENV['TUYA_BASE_URL'] ?? getenv('TUYA_BASE_URL') ?: 'https://openapi.tuyaeu.com';
$deviceId     = 'bf98d27d79685e38a2wbda';

// ─── Colores ───────────────────────────────────────────────────────
function green(string $t): string  { return "\033[32m$t\033[0m"; }
function red(string $t): string    { return "\033[31m$t\033[0m"; }
function yellow(string $t): string { return "\033[33m$t\033[0m"; }
function dim(string $t): string    { return "\033[2m$t\033[0m"; }
function bold(string $t): string   { return "\033[1m$t\033[0m"; }

// ─── Firma Tuya ──────────────────────────────────────────────────
function tuyaCall(string $method, string $path, string $body): array {
    global $accessId, $accessSecret, $baseUrl;

    // Token
    static $token = null, $expiry = 0;
    if ($token === null || time() >= $expiry) {
        $t = tuyaRequest('GET', '/v1.0/token', ['grant_type' => '1'], '');
        $data = json_decode($t['body'], true);
        $token = $data['result']['access_token'] ?? throw new \RuntimeException('token fail');
        $expiry = time() + 7200 - 60;
    }

    return tuyaRequest($method, $path, [], $body, $token);
}

function tuyaRequest(
    string $method, string $path, array $params, string $body, string $token = ''
): array {
    global $accessId, $accessSecret, $baseUrl;

    $contentSha = hash('sha256', $body);
    $strToSign = $method . "\n" . $contentSha . "\n" . "\n" . $path;
    if (!empty($params)) {
        ksort($params);
        $strToSign .= '?' . http_build_query($params);
    }
    $t = (string)(int)(microtime(true) * 1000);
    $sign = strtoupper(hash_hmac('sha256', $accessId . $token . $t . $strToSign, $accessSecret));

    $url = $baseUrl . $path;
    if (!empty($params)) $url .= '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            "client_id: $accessId", "sign: $sign", "sign_method: HMAC-SHA256",
            "t: $t", "Content-Type: application/json", "Content-SHA256: $contentSha",
            $token ? "access_token: $token" : "X-Dummy: skip",
        ],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $resp    = curl_exec($ch);
    $http    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err     = curl_error($ch);
    curl_close($ch);

    return ['http' => $http, 'body' => $resp, 'error' => $err];
}

// ─── Leer estado actual ─────────────────────────────────────────────
function readStatus(): array {
    global $deviceId;
    $res = tuyaCall('GET', "/v1.0/iot-03/devices/$deviceId/status", '');
    $data = json_decode($res['body'], true);
    $dps = $data['result'] ?? [];
    $state = [];
    foreach ($dps as $dp) {
        $state[$dp['code'] ?? ''] = $dp['value'] ?? null;
    }
    return $state;
}

function setDp(string $code, int $value): bool {
    global $deviceId;
    $body = json_encode(['commands' => [['code' => $code, 'value' => $value]]]);
    $res = tuyaCall('POST', "/v1.0/iot-03/devices/$deviceId/commands", $body);
    $data = json_decode($res['body'], true);
    return ($data['success'] ?? false) === true;
}

// ─── Main ──────────────────────────────────────────────────────────

$current = readStatus();
$now = $current['far_detection'] ?? '?';
$state = ($current['presence_state'] ?? '') === 'presence' ? green('PRESENTE') : red('AUSENTE');
$dist  = $current['target_dis_closest'] ?? '?';

echo bold("═══ ZY-M100 Presence Hack ═══") . "\n";
echo "  far_detection = $now\n";
echo "  state         = $state\n";
echo "  distance      = {$dist}cm\n\n";

echo dim("Apagando detección (far_detection=1, sensibilidad=0)...") . "\n";
$ok1 = setDp('far_detection', 1);     // 1 cm — prácticamente ciego
$ok2 = setDp('sensitivity', 0);        // sensibilidad mínima
if (!$ok1 || !$ok2) {
    echo red("❌ Fallo al enviar comandos") . "\n";
    exit(1);
}

echo dim("  Esperando 5s para que se refresque...") . "\n";
sleep(5);

$current = readStatus();
$state = ($current['presence_state'] ?? '') === 'presence' ? red('PRESENTE ⚠️ (todavía te detecta)') : green('AUSENTE ✅');
echo "  Resultado → $state (far_det=" . ($current['far_detection'] ?? '?') . ", sens=" . ($current['sensitivity'] ?? '?') . ")\n";

if (($current['presence_state'] ?? '') === 'presence') {
    echo yellow("  ⚠️ El sensor sigue viéndote — aléjate un par de metros y espera ~15s\n");
}

echo "\nPresiona Enter para restaurar (far_det=300, sens=9) o Ctrl+C para salir... ";
fgets(STDIN);

echo dim("\nRestaurando far_detection=300 sensitivity=9...") . "\n";
setDp('far_detection', 300);
setDp('sensitivity', 9);

sleep(1);
$current = readStatus();
$state = ($current['presence_state'] ?? '') === 'presence' ? green('PRESENTE') : red('AUSENTE');
echo "  far_detection = " . ($current['far_detection'] ?? '?') . "\n";
echo "  state         = $state\n";

echo green("\n✅ Listo.") . "\n";
