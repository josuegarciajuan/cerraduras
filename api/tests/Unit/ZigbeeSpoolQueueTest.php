<?php
declare(strict_types=1);

/**
 * F91/TSK-91.06 — durable spool queue transitions and validation.
 *
 * Run:
 *   php tests/Unit/ZigbeeSpoolQueueTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Zigbee\ZigbeeSpoolQueue;

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }
function check(string $m, bool $c, string $d = ''): void { $c ? pass($m) : fail($m . ($d ? " — {$d}" : '')); }
function same(string $m, mixed $a, mixed $e): void
{
    if ($a === $e) { pass($m); return; }
    fail($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}

function tmp(): string
{
    $dir = sys_get_temp_dir() . '/zigbee-spool-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    return $dir;
}

function rec(string $topic = 'zigbee2mqtt/almacen-zb-puerta', string $payload = '{"contact":true}', int $attempts = 0): array
{
    return [
        'schema' => 1,
        'topic' => $topic,
        'payload' => $payload,
        'payload_sha256' => hash('sha256', $payload),
        'qos' => 1,
        'dup' => false,
        'received_at' => '2026-10-27T10:00:00.123Z',
        'attempts' => $attempts,
        'bootstrap' => false,
    ];
}

function put(string $dir, string $name, array $record): void
{
    file_put_contents($dir . '/incoming/' . $name, json_encode($record, JSON_UNESCAPED_SLASHES));
}

echo "Zigbee spool queue (F91/TSK-91.06)\n";
echo str_repeat("=", 60) . "\n\n";

$dir = tmp();
$q = new ZigbeeSpoolQueue($dir);
put($dir, '0000000000001-00000001-aaaaaaaaaaaaaaaa.json', rec());
same('pendingFiles detects incoming', $q->pendingFiles(), ['0000000000001-00000001-aaaaaaaaaaaaaaaa.json']);

$claimed = $q->claim('0000000000001-00000001-aaaaaaaaaaaaaaaa.json');
check('claim returns record', is_array($claimed) && ($claimed['record']['topic'] ?? null) === 'zigbee2mqtt/almacen-zb-puerta');
check('claim moves incoming→processing', !is_file($dir . '/incoming/0000000000001-00000001-aaaaaaaaaaaaaaaa.json')
    && is_file($dir . '/processing/0000000000001-00000001-aaaaaaaaaaaaaaaa.json'));
$q->complete('0000000000001-00000001-aaaaaaaaaaaaaaaa.json');
check('complete moves processing→processed', is_file($dir . '/processed/0000000000001-00000001-aaaaaaaaaaaaaaaa.json'));

put($dir, '0000000000002-00000002-bbbbbbbbbbbbbbbb.json', rec());
$q->claim('0000000000002-00000002-bbbbbbbbbbbbbbbb.json');
$q->retry('0000000000002-00000002-bbbbbbbbbbbbbbbb.json', rec(), 'db_down');
$retried = json_decode(file_get_contents($dir . '/incoming/0000000000002-00000002-bbbbbbbbbbbbbbbb.json'), true);
same('retry increments attempts', $retried['attempts'], 1);
same('retry stores last_error', $retried['last_error'], 'db_down');
check('retry returns to incoming', is_file($dir . '/incoming/0000000000002-00000002-bbbbbbbbbbbbbbbb.json')
    && !is_file($dir . '/processing/0000000000002-00000002-bbbbbbbbbbbbbbbb.json'));

put($dir, '0000000000003-00000003-cccccccccccccccc.json', rec());
$q->claim('0000000000003-00000003-cccccccccccccccc.json');
$q->quarantine('0000000000003-00000003-cccccccccccccccc.json', rec(attempts: 2), 'poison');
$quarantined = json_decode(file_get_contents($dir . '/quarantine/0000000000003-00000003-cccccccccccccccc.json'), true);
same('quarantine stores reason', $quarantined['last_error'], 'poison');
check('quarantine not in processing', !is_file($dir . '/processing/0000000000003-00000003-cccccccccccccccc.json'));

$requeued = $q->requeue('0000000000003-00000003-cccccccccccccccc.json');
check('requeue creates incoming copy', is_string($requeued) && is_file($dir . '/incoming/' . $requeued));
$replayed = json_decode(file_get_contents($dir . '/incoming/' . $requeued), true);
same('requeue resets attempts', $replayed['attempts'], 0);
same('requeue marks replay', $replayed['replay'], true);
check('requeue keeps original quarantine', is_file($dir . '/quarantine/0000000000003-00000003-cccccccccccccccc.json'));

same('validateRecord accepts valid record', ZigbeeSpoolQueue::validateRecord(rec()), null);
same('validateRecord rejects schema', ZigbeeSpoolQueue::validateRecord(array_merge(rec(), ['schema' => 2])), 'schema_mismatch');
same('validateRecord rejects hash mismatch', ZigbeeSpoolQueue::validateRecord(array_merge(rec(), ['payload_sha256' => str_repeat('a', 64)])), 'hash_mismatch');
same('validateRecord rejects bad received_at', ZigbeeSpoolQueue::validateRecord(array_merge(rec(), ['received_at' => 'not-a-date'])), 'received_at_invalid');
same('validateRecord rejects bad attempts', ZigbeeSpoolQueue::validateRecord(array_merge(rec(), ['attempts' => -1])), 'attempts_invalid');

$counts = $q->counts();
check('counts expose all queue states', $counts['incoming'] >= 2 && $counts['processed'] === 1 && $counts['quarantine'] === 1);

// Cleanup recursively.
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $file) {
    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
}
rmdir($dir);

echo "\nTotal: {$passed} passed, {$failed} failed\n\n";
exit($failed === 0 ? 0 : 1);
