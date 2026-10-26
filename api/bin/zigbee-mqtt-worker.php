#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Gateways\Sensor\ZigbeeMqttMessageMapper;
use App\Infrastructure\Gateways\Sensor\ZigbeeMqttReconnectBackoff;
use App\Infrastructure\Gateways\Sensor\ZigbeeMqttRuntimeFactory;
use App\Support\Config;
use App\Support\Ulid;

const MAX_MQTT_LINE_BYTES = ZigbeeMqttMessageMapper::MAX_PAYLOAD_BYTES + 205;

function usage(): void
{
    echo "Zigbee2MQTT local consumer (disabled by default)\n"
        . "  php bin/zigbee-mqtt-worker.php [--help]\n"
        . "  php bin/zigbee-mqtt-worker.php --stdin --dry-run < topic<TAB>payload\n\n"
        . "Live consumption requires ZIGBEE_MQTT_ENABLED=true, a configured device map,\n"
        . "a local broker and the mosquitto_sub client.\n";
}

function loadDeviceMap(string $projectDir): array
{
    $path = Config::get('ZIGBEE_MQTT_MAP_FILE', 'config/zigbee2mqtt-devices.json') ?? '';
    if ($path === '') {
        throw new RuntimeException('ZIGBEE_MQTT_MAP_FILE is empty');
    }
    if ($path[0] !== DIRECTORY_SEPARATOR) {
        $path = $projectDir . DIRECTORY_SEPARATOR . $path;
    }
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Zigbee device map is missing or unreadable');
    }

    $raw = file_get_contents($path);
    if ($raw === false || strlen($raw) > 1048576) {
        throw new RuntimeException('Zigbee device map is unreadable or too large');
    }
    try {
        $config = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Zigbee device map contains invalid JSON', 0, $error);
    }
    if (!is_array($config)) {
        throw new RuntimeException('Zigbee device map root must be a JSON object');
    }
    return $config;
}

function resolveClientBinary(string $configured): ?string
{
    if ($configured === '') {
        return null;
    }
    if (str_contains($configured, DIRECTORY_SEPARATOR)) {
        return is_file($configured) && is_executable($configured) ? $configured : null;
    }
    $path = getenv('PATH') ?: '';
    foreach (explode(PATH_SEPARATOR, $path) as $directory) {
        $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $configured;
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }
    return null;
}

function processMappedMessage(ZigbeeMqttMessageMapper $mapper, string $topic, string $payload, ?callable $processEvent): void
{
    $result = $mapper->mapMessage($topic, $payload, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    if ($result['diagnostic'] !== null) {
        error_log('[zigbee-mqtt-worker] availability ' . $topic . '=' . $result['diagnostic']['state']);
    }
    if ($result['discard_reason'] !== null) {
        error_log('[zigbee-mqtt-worker] discarded topic=' . $topic . ' reason=' . $result['discard_reason']);
    }
    foreach ($result['events'] as $event) {
        if ($processEvent === null) {
            echo json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            continue;
        }
        $processed = $processEvent($event);
        if (($processed['status'] ?? '') !== 'processed') {
            error_log('[zigbee-mqtt-worker] discarded mapped event reason=' . (string) ($processed['reason'] ?? 'unknown'));
        }
    }
}

$args = array_slice($argv, 1);
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    usage();
    exit(0);
}

foreach ($args as $argument) {
    if (!in_array($argument, ['--stdin', '--dry-run'], true)) {
        fwrite(STDERR, 'Unsupported option: ' . $argument . "\n");
        usage();
        exit(2);
    }
}

$stdinDryRun = in_array('--stdin', $args, true) && in_array('--dry-run', $args, true);
if (in_array('--stdin', $args, true) !== in_array('--dry-run', $args, true)) {
    fwrite(STDERR, "Both --stdin and --dry-run are required together.\n");
    exit(2);
}

$projectDir = dirname(__DIR__);
Config::load($projectDir . '/.env');
if (!$stdinDryRun && !Config::getBool('ZIGBEE_MQTT_ENABLED', false)) {
    echo "[zigbee-mqtt-worker] disabled (ZIGBEE_MQTT_ENABLED is false).\n";
    exit(0);
}

try {
    $topicPrefix = Config::get('ZIGBEE_MQTT_TOPIC_PREFIX', 'zigbee2mqtt') ?? 'zigbee2mqtt';
    $mapper = new ZigbeeMqttMessageMapper(loadDeviceMap($projectDir), $topicPrefix);
} catch (Throwable $error) {
    fwrite(STDERR, '[zigbee-mqtt-worker] configuration error: ' . $error->getMessage() . "\n");
    exit(1);
}

if ($stdinDryRun) {
    while (!feof(STDIN)) {
        $line = fgets(STDIN, MAX_MQTT_LINE_BYTES + 1);
        if ($line === false) {
            break;
        }
        if (!str_ends_with($line, "\n") && !feof(STDIN)) {
            while (($rest = fgets(STDIN, 8192)) !== false && !str_ends_with($rest, "\n")) {
                // Drain an overlong test record without buffering the remainder.
            }
            error_log('[zigbee-mqtt-worker] discarded stdin record reason=payload_too_large');
            continue;
        }
        $line = rtrim($line, "\r\n");
        $separator = strpos($line, "\t");
        if ($separator === false) {
            error_log('[zigbee-mqtt-worker] discarded stdin record reason=invalid_record');
            continue;
        }
        processMappedMessage($mapper, substr($line, 0, $separator), substr($line, $separator + 1), null);
    }
    exit(0);
}

$clientName = Config::get('MOSQUITTO_SUB_BIN', 'mosquitto_sub') ?? 'mosquitto_sub';
$client = resolveClientBinary($clientName);
if ($client === null) {
    fwrite(STDERR, '[zigbee-mqtt-worker] mosquitto_sub client not found or not executable.\n');
    exit(1);
}

$host = Config::get('ZIGBEE_MQTT_HOST', '127.0.0.1') ?? '127.0.0.1';
$portRaw = Config::get('ZIGBEE_MQTT_PORT', '1883') ?? '1883';
$port = filter_var($portRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
$clientId = Config::get('ZIGBEE_MQTT_CLIENT_ID', 'cerraduras-zigbee-consumer') ?? '';
if ($host === '' || preg_match('/[\r\n\0]/', $host) === 1 || $port === false
    || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $clientId) !== 1
) {
    fwrite(STDERR, "[zigbee-mqtt-worker] invalid broker connection configuration.\n");
    exit(1);
}

try {
    $processor = ZigbeeMqttRuntimeFactory::create(PdoFactory::make());
} catch (Throwable $error) {
    fwrite(STDERR, '[zigbee-mqtt-worker] runtime setup failed: ' . $error->getMessage() . "\n");
    exit(1);
}

$processEvent = static function (array $event) use ($processor): array {
    return $processor->process($event, Ulid::generate());
};
$consecutiveFailures = 0;
$topicFilter = $topicPrefix . '/#';

while (true) {
    $command = [
        $client,
        '-h', $host,
        '-p', (string) $port,
        '-i', $clientId,
        '-c',
        '-q', '1',
        '-t', $topicFilter,
        '-F', '%t\\t%p',
    ];
    $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'r'], 2 => ['file', '/dev/stderr', 'a']], $pipes);
    if (!is_resource($process)) {
        $consecutiveFailures++;
        $delay = ZigbeeMqttReconnectBackoff::delayFor($consecutiveFailures);
        error_log('[zigbee-mqtt-worker] unable to start client; retry in ' . $delay . 's');
        sleep($delay);
        continue;
    }
    fclose($pipes[0]);
    $stdout = $pipes[1];

    while (($line = fgets($stdout, MAX_MQTT_LINE_BYTES + 1)) !== false) {
        if (!str_ends_with($line, "\n") && !feof($stdout)) {
            while (($rest = fgets($stdout, 8192)) !== false && !str_ends_with($rest, "\n")) {
                // Drain an oversized broker record without retaining it in memory.
            }
            error_log('[zigbee-mqtt-worker] discarded broker record reason=payload_too_large');
            continue;
        }
        $line = rtrim($line, "\r\n");
        $separator = strpos($line, "\t");
        if ($separator === false) {
            error_log('[zigbee-mqtt-worker] discarded broker record reason=invalid_record');
            continue;
        }
        try {
            processMappedMessage($mapper, substr($line, 0, $separator), substr($line, $separator + 1), $processEvent);
        } catch (Throwable $error) {
            error_log('[zigbee-mqtt-worker] event processing failed: ' . $error->getMessage());
        }
    }

    fclose($stdout);
    $exitCode = proc_close($process);
    $consecutiveFailures++;
    $delay = ZigbeeMqttReconnectBackoff::delayFor($consecutiveFailures);
    error_log('[zigbee-mqtt-worker] MQTT client exited code=' . $exitCode . '; retry in ' . $delay . 's');
    sleep($delay);
}
