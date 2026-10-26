<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

$PASS = 0;
$FAIL = 0;

function pass(string $message): void
{
    global $PASS;
    $PASS++;
    echo "  ✅ {$message}\n";
}

function fail(string $message): void
{
    global $FAIL;
    $FAIL++;
    echo "  ❌ {$message}\n";
}

function check(string $label, bool $condition, string $detail = ''): void
{
    if ($condition) {
        pass($label);
    } else {
        fail($label . ($detail !== '' ? ' — ' . $detail : ''));
    }
}

/** @return array{int,string,string} */
function runZigbeeWorker(array $arguments, array $environment, string $stdin = ''): array
{
    $script = dirname(__DIR__, 2) . '/bin/zigbee-mqtt-worker.php';
    $process = proc_open(
        array_merge([PHP_BINARY, $script], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname($script, 2),
        $environment
    );
    if (!is_resource($process)) {
        return [127, '', 'proc_open failed'];
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return [proc_close($process), (string) $stdout, (string) $stderr];
}

$baseEnvironment = getenv();
if (!is_array($baseEnvironment)) {
    $baseEnvironment = [];
}
unset($baseEnvironment['ZIGBEE_MQTT_ENABLED']);

[$helpCode, $helpOut] = runZigbeeWorker(['--help'], $baseEnvironment);
check('F90: --help exits without broker setup', $helpCode === 0 && str_contains($helpOut, '--stdin --dry-run'));

$baseEnvironment['MOSQUITTO_SUB_BIN'] = '/no/such/mosquitto_sub';
[$disabledCode, $disabledOut] = runZigbeeWorker([], $baseEnvironment);
check(
    'F90: worker default disabled returns before transport/database initialization',
    $disabledCode === 0 && str_contains($disabledOut, 'disabled')
);
[$invalidArgsCode] = runZigbeeWorker(['--unknown-option'], $baseEnvironment);
check('F90: unsupported CLI options fail closed', $invalidArgsCode === 2);

$mapPath = tempnam(sys_get_temp_dir(), 'z2m-map-');
if ($mapPath === false) {
    fail('F90: temporary map can be created');
} else {
    $map = [
        'devices' => [
            'zigbee2mqtt/test-contact' => [
                'external_id' => 'test-ieee-contact',
                'entities' => [
                    'contact' => ['sensor' => 'PROXIMITY', 'values' => ['false' => 'OPEN']],
                ],
            ],
        ],
    ];
    file_put_contents($mapPath, json_encode($map, JSON_THROW_ON_ERROR));
    $dryRunEnvironment = $baseEnvironment;
    $dryRunEnvironment['ZIGBEE_MQTT_MAP_FILE'] = $mapPath;
    [$dryRunCode, $dryRunOut, $dryRunErr] = runZigbeeWorker(
        ['--stdin', '--dry-run'],
        $dryRunEnvironment,
        "zigbee2mqtt/test-contact\t{\"contact\":false}\n"
    );
    check(
        'F90: stdin dry-run maps captured payload without broker or database',
        $dryRunCode === 0
            && str_contains($dryRunOut, '"device_external_id":"test-ieee-contact"')
            && str_contains($dryRunOut, '"value":"OPEN"'),
        $dryRunErr
    );
    unlink($mapPath);
}

$workerSource = file_get_contents(dirname(__DIR__, 2) . '/bin/zigbee-mqtt-worker.php');
$processorSource = file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Gateways/Sensor/ZigbeeMqttEventProcessor.php');
$factorySource = file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Gateways/Sensor/ZigbeeMqttRuntimeFactory.php');
$domainFactorySource = file_get_contents(dirname(__DIR__, 2) . '/src/Domain/Presence/IotSessionServiceFactory.php');
$apiBootstrap = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
$guardedSource = (string) $workerSource . (string) $processorSource . (string) $factorySource . (string) $domainFactorySource;
check(
    'F90: each event receives a valid-length ULID correlation id, not its source id',
    str_contains((string) $workerSource, 'Ulid::generate()')
        && !str_contains((string) $workerSource, "source_event_id']);")
);
foreach (['TuyaSensorIngress', 'tuyaPresenceApi', 'TUYA_ACCESS_ID', 'openapi.tuya'] as $cloudMarker) {
    check('F90: worker path excludes cloud client marker ' . $cloudMarker, !str_contains($guardedSource, $cloudMarker));
}
check(
    'F90: HTTP bootstrap and MQTT worker share the IotSessionService factory',
    str_contains((string) $apiBootstrap, 'IotSessionServiceFactory::create')
        && str_contains((string) $factorySource, 'IotSessionServiceFactory::create')
);

echo "\nTotal: {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
