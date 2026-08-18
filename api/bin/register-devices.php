#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/register-devices.sh → bin/register-devices.php
 *
 * Registra dispositivos ESP32 en la tabla devices desde un archivo CSV.
 *
 * Formato CSV (sin cabecera):
 *   external_id,room_code
 *   a1b2c3d4,101
 *   92f57630,102
 *   ...
 *
 * Uso:
 *   php bin/register-devices.php devices.csv
 *
 * Cada línea llama a POST /api/v1/devices/register.
 *
 * Requisitos:
 *   - API_KEY en variable de entorno ADMIN_CLI_KEY (o hardcodeada abajo)
 *   - API corriendo en localhost (ajustar API_BASE_URL)
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/register-devices.php <devices.csv>\n");
    exit(1);
}

$csvFile = $argv[1];
if (!is_file($csvFile) || !is_readable($csvFile)) {
    fwrite(STDERR, "Error: cannot read {$csvFile}\n");
    exit(1);
}

$apiBase  = getenv('API_BASE_URL') ?: 'http://127.0.0.1:8080';
$adminKey = getenv('ADMIN_CLI_KEY') ?: 'b888a80507b084ad7be8908b3b46db06b820211d788aeb81';

$handle = fopen($csvFile, 'r');
if ($handle === false) {
    fwrite(STDERR, "Error: cannot open {$csvFile}\n");
    exit(1);
}

$total   = 0;
$success = 0;
$skipped = 0;

while (($line = fgets($handle)) !== false) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    $parts = str_getcsv($line);
    if (count($parts) < 2) {
        fwrite(STDERR, "WARN: skipping malformed line: {$line}\n");
        continue;
    }

    [$externalId, $roomCode] = $parts;
    $externalId = trim($externalId);
    $roomCode   = trim($roomCode);

    // Resolve room_code → room_id
    $roomUrl = "{$apiBase}/api/v1/rooms?code=" . urlencode($roomCode);
    $ctx = stream_context_create([
        'http' => [
            'header' => "X-API-Key: {$adminKey}\r\nAccept: application/json\r\n",
            'timeout' => 10,
        ],
    ]);
    $resp = @file_get_contents($roomUrl, false, $ctx);
    if ($resp === false) {
        fwrite(STDERR, "ERROR: cannot resolve room {$roomCode}\n");
        continue;
    }
    $data = json_decode($resp, true);
    $items = $data['items'] ?? [];
    if (empty($items)) {
        fwrite(STDERR, "ERROR: room {$roomCode} not found\n");
        continue;
    }
    $roomId = (int) $items[0]['id'];

    // POST /api/v1/devices/register
    $body = json_encode([
        'room_id'     => $roomId,
        'kind'        => 'RPI',
        'external_id' => $externalId,
    ], JSON_UNESCAPED_UNICODE);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nX-API-Key: {$adminKey}\r\nAccept: application/json\r\n",
            'content' => $body,
            'timeout' => 10,
        ],
    ]);
    $registerUrl = "{$apiBase}/api/v1/devices/register";
    $regResp = @file_get_contents($registerUrl, false, $ctx);

    $total++;
    if ($regResp !== false) {
        $regData = json_decode($regResp, true);
        if (isset($regData['id'])) {
            echo "  OK  room {$roomCode} (id={$roomId}) ← device {$externalId}\n";
            $success++;
        } else {
            echo "  SKIP room {$roomCode}: " . ($regData['error']['message'] ?? 'already exists') . "\n";
            $skipped++;
        }
    } else {
        fwrite(STDERR, "  FAIL room {$roomCode}: HTTP error\n");
    }
}

fclose($handle);

echo "\nDone. total={$total} success={$success} skipped={$skipped}\n";
exit(0);
