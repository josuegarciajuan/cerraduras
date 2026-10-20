<?php
/**
 * tuya_new_fetch.php — lectura de solo lectura de la cuenta Tuya NUEVA (PoC L1).
 *
 * Lee credenciales de /root/cerraduras/api/run/tuya-new-account.env (modo 600),
 * lista los dispositivos y guarda id/nombre/local_key en
 * /root/cerraduras/api/run/tuya-new-devices.json (600). No imprime la clave.
 *
 * Uso: php tuya_new_fetch.php
 */
declare(strict_types=1);

$envFile = '/root/cerraduras/api/run/tuya-new-account.env';
if (!is_file($envFile)) {
    fwrite(STDERR, "Falta $envFile (créalo con TUYA_ACCESS_ID/SECRET/BASE_URL, modo 600)\n");
    exit(1);
}
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $eq = strpos($line, '=');
    if ($eq !== false) $env[trim(substr($line, 0, $eq))] = trim(substr($line, $eq + 1), " \t\"'");
}
$aid  = $env['TUYA_ACCESS_ID'] ?? '';
$sec  = $env['TUYA_ACCESS_SECRET'] ?? '';
$base = $env['TUYA_BASE_URL'] ?? 'https://openapi.tuyaeu.com';
if ($aid === '' || $sec === '') { fwrite(STDERR, "Credenciales vacías en $envFile\n"); exit(1); }

function call(string $m, string $p, array $q, string $b, string $aid, string $tok, string $sec, string $base): array {
    $cs = hash('sha256', $b);
    $s = $m . "\n" . $cs . "\n\n" . $p;
    if (!empty($q)) { ksort($q); $s .= '?' . http_build_query($q); }
    $t = (string)(int)(microtime(true) * 1000);
    $sign = strtoupper(hash_hmac('sha256', $aid . $tok . $t . $s, $sec));
    $url = $base . $p;
    if (!empty($q)) $url .= '?' . http_build_query($q);
    $h = ["client_id: $aid", "sign: $sign", "sign_method: HMAC-SHA256", "t: $t",
          "Content-Type: application/json", "Content-SHA256: $cs"];
    if ($tok !== '') $h[] = "access_token: $tok";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $h]);
    $r = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$c, json_decode((string)$r, true) ?: ['raw' => $r]];
}

[$c, $d] = call('GET', '/v1.0/token', ['grant_type' => '1'], '', $aid, '', $sec, $base);
if (!($d['success'] ?? false)) { fwrite(STDERR, "TOKEN FAIL: " . json_encode($d) . "\n"); exit(1); }
$tok = $d['result']['access_token'];
echo "Token OK (cuenta nueva)\n\n";

[$c, $d] = call('GET', '/v1.0/iot-03/devices', [], '', $aid, $tok, $sec, $base);
if (!($d['success'] ?? false)) { fwrite(STDERR, "LIST FAIL: " . json_encode($d) . "\n"); exit(1); }
$list = $d['result'] ?? [];
echo "Dispositivos: " . count($list) . "\n\n";

$out = [];
foreach ($list as $dev) {
    $id = $dev['id'] ?? '';
    if ($id === '') continue;
    [$c, $det] = call('GET', "/v1.0/iot-03/devices/$id", [], '', $aid, $tok, $sec, $base);
    $r = $det['result'] ?? [];
    $lk = (string)($r['local_key'] ?? '');
    $mask = $lk === '' ? '(vacío)' : substr($lk, 0, 2) . '***' . substr($lk, -2);
    printf("%-10s %-28s %-26s %-8s %-12s lk=%s\n",
        $r['category'] ?? '?', $id, $r['name'] ?? '?',
        isset($r['online']) ? ($r['online'] ? 'online' : 'offline') : '?',
        $r['ip'] ?? '?', $mask);
    $out[] = [
        'device_id' => $id,
        'name' => $r['name'] ?? '',
        'category' => $r['category'] ?? '',
        'product_id' => $r['product_id'] ?? '',
        'model' => $r['model'] ?? '',
        'ip' => $r['ip'] ?? '',
        'online' => $r['online'] ?? null,
        'sub' => $r['sub'] ?? null,
        'local_key' => $lk,
    ];
}
$path = '/root/cerraduras/api/run/tuya-new-devices.json';
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
chmod($path, 0600);
echo "\nGuardado (600): $path\n";
