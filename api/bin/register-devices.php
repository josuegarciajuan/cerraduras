#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/register-devices.sh → bin/register-devices.php
 *
 * Registra dispositivos en la tabla devices desde un archivo CSV.
 *
 * Modelo canónico (F30): un dispositivo pertenece a un PACK, nunca a una room
 * directamente. El CSV referencia el pack por su `code`.
 *
 * Formato CSV (sin cabecera):
 *   external_id,pack_code
 *   a1b2c3d4,PRUEBAS
 *   92f57630,proto2
 *   ...
 *
 * Uso:
 *   php bin/register-devices.php devices.csv
 *
 * Cada línea llama a POST /api/v1/devices/register con { pack_id, kind, external_id }.
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

// Preload packs (code => id) once.
$packsUrl = "{$apiBase}/api/v1/device-packs";
$ctx0 = stream_context_create([
    'http' => [
        'header' => "X-API-Key: {$adminKey}\r\nAccept: application/json\r\n",
        'timeout' => 10,
    ],
]);
$packsResp = @file_get_contents($packsUrl, false, $ctx0);
if ($packsResp === false) {
    fwrite(STDERR, "ERROR: cannot list packs at {$packsUrl}\n");
    exit(1);
}
$packsData = json_decode($packsResp, true);
$packsById = [];
foreach (($packsData['items'] ?? $packsData) as $p) {
    if (isset($p['code']) && isset($p['id'])) {
        $packsById[(string) $p['code']] = (int) $p['id'];
    }
}
if (empty($packsById)) {
    fwrite(STDERR, "ERROR: no packs found (device registration requires a pack)\n");
    exit(1);
}

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

    [$externalId, $packCode] = $parts;
    $externalId = trim($externalId);
    $packCode   = trim($packCode);

    $packId = $packsById[$packCode] ?? null;
    if ($packId === null) {
        fwrite(STDERR, "ERROR: pack {$packCode} not found\n");
        continue;
    }

    // POST /api/v1/devices/register (pack-based, canonical F30)
    $body = json_encode([
        'pack_id'     => $packId,
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
            echo "  OK  pack {$packCode} (id={$packId}) ← device {$externalId}\n";
            $success++;
        } else {
            echo "  SKIP pack {$packCode}: " . ($regData['error']['message'] ?? 'already exists') . "\n";
            $skipped++;
        }
    } else {
        fwrite(STDERR, "  FAIL pack {$packCode}: HTTP error\n");
    }
}

fclose($handle);

echo "\nDone. total={$total} success={$success} skipped={$skipped}\n";
exit(0);
