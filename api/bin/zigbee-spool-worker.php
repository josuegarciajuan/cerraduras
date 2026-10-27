#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/zigbee-spool-worker.php — F91/TSK-91.06
 *
 * Consume el spool duradero escrito por `zigbee-ingress`, normaliza con el
 * mapa MQTT v2 y entrega al dominio (IotSessionService / SwitchStateService).
 *
 * No habla con MQTT ni con Tuya. No hace polling remoto: solo lee ficheros
 * locales. Por defecto procesa lo pendiente y sale (`--once`).
 *
 * Uso:
 *   php bin/zigbee-spool-worker.php
 *   php bin/zigbee-spool-worker.php --limit=100
 *   php bin/zigbee-spool-worker.php --watch
 *   php bin/zigbee-spool-worker.php --dry-run
 *   php bin/zigbee-spool-worker.php --requeue=<fichero.json>
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Gateways\Sensor\ZigbeeMqttMessageMapper;
use App\Infrastructure\Gateways\Sensor\ZigbeeMqttRuntimeFactory;
use App\Infrastructure\Zigbee\ZigbeeSpoolQueue;
use App\Support\Config;
use App\Support\Ulid;

Config::load(__DIR__ . '/../.env');

const MAX_ATTEMPTS = 3;

$opts = parseArgs($argv);
if (isset($opts['help'])) {
    echo "Uso: php bin/zigbee-spool-worker.php [--limit=N] [--watch] [--dry-run] [--requeue=FILE --yes] [--help]\n";
    exit(0);
}

$apiDir = dirname(__DIR__);
$spoolDir = resolvePath($apiDir, Config::get('ZIGBEE_SPOOL_DIR', 'run/zigbee-spool') ?? 'run/zigbee-spool');
$mapFile = resolvePath($apiDir, Config::get('ZIGBEE_MQTT_MAP_FILE', 'config/zigbee2mqtt-devices.json') ?? 'config/zigbee2mqtt-devices.json');
$prefix = Config::get('ZIGBEE_MQTT_TOPIC_PREFIX', 'zigbee2mqtt') ?? 'zigbee2mqtt';
$statusFile = resolvePath($apiDir, Config::get('ZIGBEE_SPOOL_STATUS_FILE', 'run/zigbee-spool-status.json') ?? 'run/zigbee-spool-status.json');
$limit = max(1, (int) ($opts['limit'] ?? 1000));
$dryRun = isset($opts['dry-run']);
$watch = isset($opts['watch']);
$spoolEnabled = Config::getBool('ZIGBEE_SPOOL_ENABLED', false);

try {
    if (!is_file($mapFile) || !is_readable($mapFile)) {
        throw new RuntimeException('Zigbee map is missing or unreadable: ' . $mapFile);
    }
    $rawMap = file_get_contents($mapFile);
    $config = is_string($rawMap) ? json_decode($rawMap, true, 64, JSON_THROW_ON_ERROR) : null;
    if (!is_array($config)) {
        throw new RuntimeException('Zigbee map must be a JSON object');
    }
    $mapper = new ZigbeeMqttMessageMapper($config, $prefix);
} catch (Throwable $e) {
    fwrite(STDERR, '[zigbee-spool] ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

$queue = new ZigbeeSpoolQueue($spoolDir);

// F91.13: el consumidor continuo es opt-in. El replay manual y el dry-run
// siguen disponibles con el flag apagado para diagnóstico.
if (!$spoolEnabled && !$dryRun && !isset($opts['requeue'])) {
    echo "[zigbee-spool] disabled (ZIGBEE_SPOOL_ENABLED is not true); nothing started\n";
    exit(0);
}

if (isset($opts['requeue'])) {
    if (!isset($opts['yes'])) {
        fwrite(STDERR, "[zigbee-spool] --requeue requiere --yes para confirmar el replay\n");
        exit(2);
    }
    $name = $queue->requeue((string) $opts['requeue']);
    if ($name === null) {
        fwrite(STDERR, "[zigbee-spool] no se pudo reencolar {$opts['requeue']}\n");
        exit(1);
    }
    echo "[zigbee-spool] reencolado como {$name}\n";
    exit(0);
}

if ($dryRun) {
    $files = $queue->pendingFiles();
    foreach (array_slice($files, 0, $limit) as $file) {
        $path = $spoolDir . '/incoming/' . basename($file);
        $record = json_decode((string) file_get_contents($path), true);
        if (!is_array($record)) {
            echo "[dry-run] {$file}: invalid_json\n";
            continue;
        }
        $err = ZigbeeSpoolQueue::validateRecord($record);
        if ($err !== null) {
            echo "[dry-run] {$file}: {$err}\n";
            continue;
        }
        $mapped = $mapper->mapMessage(
            (string) $record['topic'],
            (string) $record['payload'],
            new DateTimeImmutable((string) $record['received_at']),
            (bool) ($record['bootstrap'] ?? false)
        );
        echo "[dry-run] {$file}: events=" . count($mapped['events'])
            . ' discard=' . ($mapped['discard_reason'] ?? 'null') . "\n";
        foreach ($mapped['events'] as $event) {
            echo '  ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }
    }
    exit(0);
}

$pdo = PdoFactory::make();
$processor = ZigbeeMqttRuntimeFactory::create($pdo);

$counters = [
    'processed' => 0,
    'discarded' => 0,
    'quarantined' => 0,
    'retried' => 0,
    'errors' => 0,
    'last_processed_at' => null,
];

function logLine(string $message): void
{
    fwrite(STDERR, '[zigbee-spool ' . gmdate('Y-m-d\TH:i:s\Z') . '] ' . $message . "\n");
}

function writeStatus(string $file, array $counters, ZigbeeSpoolQueue $queue): void
{
    $payload = array_merge($counters, [
        'counts' => $queue->counts(),
        'updated_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
    ]);
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $tmp = $dir . '/.zigbee-spool-status-' . getmypid() . '-' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($tmp, 0600);
    rename($tmp, $file);
}

function processPending(
    ZigbeeSpoolQueue $queue,
    ZigbeeMqttMessageMapper $mapper,
    $processor,
    int $limit,
    array &$counters,
    string $statusFile
): int {
    $files = $queue->pendingFiles();
    $handled = 0;
    foreach (array_slice($files, 0, $limit) as $file) {
        $claimed = $queue->claim($file);
        if ($claimed === null) {
            continue;
        }
        $record = $claimed['record'];
        $name = $claimed['file'];
        $handled++;

        $error = ZigbeeSpoolQueue::validateRecord($record);
        if ($error !== null) {
            $queue->quarantine($name, $record, $error);
            $counters['quarantined']++;
            logLine("quarantine {$name}: {$error}");
            writeStatus($statusFile, $counters, $queue);
            continue;
        }

        try {
            $receivedAt = new DateTimeImmutable((string) $record['received_at']);
            $mapped = $mapper->mapMessage(
                (string) $record['topic'],
                (string) $record['payload'],
                $receivedAt,
                (bool) ($record['bootstrap'] ?? false)
            );

            if ($mapped['diagnostic'] !== null) {
                logLine('availability ' . json_encode($mapped['diagnostic']));
            }
            if ($mapped['events'] === []) {
                $counters['discarded']++;
                logLine("discard {$name}: " . ($mapped['discard_reason'] ?? 'no_event'));
                $queue->complete($name);
                writeStatus($statusFile, $counters, $queue);
                continue;
            }

            foreach ($mapped['events'] as $event) {
                $result = $processor->process($event, Ulid::generate());
                if (($result['status'] ?? '') === 'discarded') {
                    $counters['discarded']++;
                    logLine("discard {$name}: " . ($result['reason'] ?? 'domain_discard'));
                }
            }
            $queue->complete($name);
            $counters['processed']++;
            $counters['last_processed_at'] = gmdate('Y-m-d\TH:i:s.v\Z');
            logLine("processed {$name}");
            writeStatus($statusFile, $counters, $queue);
        } catch (Throwable $e) {
            $counters['errors']++;
            $attempts = (int) ($record['attempts'] ?? 0);
            if ($attempts + 1 < MAX_ATTEMPTS) {
                $queue->retry($name, $record, $e->getMessage());
                $counters['retried']++;
                logLine("retry {$name} attempt=" . ($attempts + 1) . ': ' . $e->getMessage());
            } else {
                $queue->quarantine($name, $record, $e->getMessage());
                $counters['quarantined']++;
                logLine("quarantine {$name} after " . ($attempts + 1) . ' attempts: ' . $e->getMessage());
            }
            writeStatus($statusFile, $counters, $queue);
        }
    }
    return $handled;
}

if ($watch) {
    logLine('watching spool ' . $spoolDir);
    while (true) {
        $processed = processPending($queue, $mapper, $processor, $limit, $counters, $statusFile);
        if ($processed === 0) {
            usleep(500000);
        }
    }
}

$processed = processPending($queue, $mapper, $processor, $limit, $counters, $statusFile);
writeStatus($statusFile, $counters, $queue);
logLine('done handled=' . $processed . ' processed=' . $counters['processed']
    . ' discarded=' . $counters['discarded'] . ' quarantined=' . $counters['quarantined']);
exit(0);

// ---------------------------------------------------------------------------

/** @return array<string,string> */
function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $out['help'] = '1';
            continue;
        }
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
            $out[$key] = $value;
        } else {
            $out[$arg] = '1';
        }
    }
    return $out;
}

function resolvePath(string $apiDir, string $path): string
{
    if ($path === '') {
        return $path;
    }
    if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
        return $path;
    }
    return $apiDir . '/' . ltrim($path, '/');
}
