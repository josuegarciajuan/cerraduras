<?php
declare(strict_types=1);

/**
 * F91/TSK-91.06 — spool worker CLI and dry-run contract.
 *
 * No DB, MQTT, Tuya or hardware: the dry-run path maps a local spool record.
 *
 * Run:
 *   php tests/Unit/ZigbeeSpoolWorkerTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Zigbee\ZigbeeSpoolQueue;

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }
function check(string $m, bool $c, string $d = ''): void { $c ? pass($m) : fail($m . ($d ? " — {$d}" : '')); }

/** @return array{0:int,1:string,2:string} */
function runWorker(array $args, array $env): array
{
    $script = dirname(__DIR__, 2) . '/bin/zigbee-spool-worker.php';
    $process = proc_open(
        array_merge([PHP_BINARY, $script], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname($script, 1),
        $env
    );
    if (!is_resource($process)) {
        return [127, '', 'proc_open failed'];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return [proc_close($process), (string) $out, (string) $err];
}

function tmp(): string
{
    $dir = sys_get_temp_dir() . '/zigbee-spool-worker-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    return $dir;
}

function cleanup(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

$baseEnv = getenv();
if (!is_array($baseEnv)) $baseEnv = [];
$baseEnv['ZIGBEE_MQTT_TOPIC_PREFIX'] = 'zigbee2mqtt';

echo "Zigbee spool worker (F91/TSK-91.06)\n";
echo str_repeat("=", 60) . "\n\n";

[$helpCode, $helpOut] = runWorker(['--help'], $baseEnv);
check('--help exits without DB/MQTT', $helpCode === 0 && str_contains($helpOut, '--dry-run'));

$dir = tmp();
$queue = new ZigbeeSpoolQueue($dir);
$payload = '{"contact":true,"linkquality":80}';
$record = [
    'schema' => 1,
    'topic' => 'zigbee2mqtt/almacen-zb-puerta',
    'payload' => $payload,
    'payload_sha256' => hash('sha256', $payload),
    'qos' => 1,
    'dup' => false,
    'received_at' => '2026-10-27T10:00:00.123Z',
    'attempts' => 0,
    'bootstrap' => false,
];
$name = '0000000000001-00000001-aaaaaaaaaaaaaaaa.json';
file_put_contents($dir . '/incoming/' . $name, json_encode($record));

$env = $baseEnv;
$env['ZIGBEE_SPOOL_DIR'] = $dir;
$env['ZIGBEE_MQTT_MAP_FILE'] = dirname(__DIR__, 2) . '/config/zigbee2mqtt-devices.json';

[$dryCode, $dryOut, $dryErr] = runWorker(['--dry-run'], $env);
check(
    'dry-run maps a spool record without DB',
    $dryCode === 0 && str_contains($dryOut, 'events=1') && str_contains($dryOut, '"value":"OPEN"'),
    $dryErr
);
check('dry-run does not move the record', is_file($dir . '/incoming/' . $name));

// Requeue a quarantined record without DB.
file_put_contents(
    $dir . '/quarantine/' . $name,
    json_encode(array_merge($record, ['last_error' => 'poison', 'attempts' => 3]))
);
unlink($dir . '/incoming/' . $name);
[$noYesCode] = runWorker(['--requeue=' . $name], $env);
check('--requeue requires explicit --yes', $noYesCode === 2);
[$reCode, $reOut, $reErr] = runWorker(['--requeue=' . $name, '--yes'], $env);
$requeuedFiles = glob($dir . '/incoming/*.json') ?: [];
check('--requeue restores an incoming record', $reCode === 0 && count($requeuedFiles) >= 1, $reErr);
$requeued = json_decode(file_get_contents($requeuedFiles[0]), true);
check('requeue resets attempts and marks replay', $requeued['attempts'] === 0 && ($requeued['replay'] ?? false) === true);
check('requeue audit log written', is_file($dir . '/replay.log'));

$source = file_get_contents(dirname(__DIR__, 2) . '/bin/zigbee-spool-worker.php');
check('worker uses the spool queue', str_contains($source, 'ZigbeeSpoolQueue'));
check('worker uses the shared domain runtime', str_contains($source, 'ZigbeeMqttRuntimeFactory::create'));
check('worker uses ULID correlation ids', str_contains($source, 'Ulid::generate()'));
check('worker has no MQTT client/poller', !str_contains($source, 'mosquitto') && !str_contains($source, 'setInterval'));
check('worker has no Tuya path', !str_contains($source, 'TuyaSensorIngress') && !str_contains($source, 'tuyaPresenceApi'));

cleanup($dir);

echo "\nTotal: {$passed} passed, {$failed} failed\n\n";
exit($failed === 0 ? 0 : 1);
