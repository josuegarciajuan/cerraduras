<?php
declare(strict_types=1);

/**
 * F138 — recuperación de claims abandonados del spool.
 *
 * Run: php tests/Unit/ZigbeeSpoolRecoveryTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Zigbee\ZigbeeSpoolQueue;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  {$label}\n";
        return;
    }
    $fail++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$dir = sys_get_temp_dir() . '/f138-spool-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
try {
    $queue = new ZigbeeSpoolQueue($dir);
    $old = $dir . '/processing/old.json';
    $fresh = $dir . '/processing/fresh.json';
    file_put_contents($old, json_encode(['topic' => 't', 'payload' => '{}']));
    file_put_contents($fresh, json_encode(['topic' => 't', 'payload' => '{}']));
    touch($old, time() - 600);

    $moved = $queue->recoverStaleClaims(300);
    check('claim antiguo recuperado', $moved === 1, 'got ' . $moved);
    check('claim antiguo vuelve a incoming', is_file($dir . '/incoming/old.json'));
    check('claim reciente no se mueve', is_file($fresh));
} finally {
    foreach (glob($dir . '/*/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir . '/incoming');
    @rmdir($dir . '/processing');
    @rmdir($dir . '/processed');
    @rmdir($dir . '/quarantine');
    @rmdir($dir);
}

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
