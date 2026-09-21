<?php
declare(strict_types=1);

/**
 * Unit tests for TuyaSwitchGateway::classifyResponse().
 *
 * The EAWCBT-J switch command path used to log only "HTTP 200 FAIL" without the
 * Tuya `code`/`msg`, and SwitchService swallowed `ok:false`. These tests pin the
 * failure taxonomy (quota | offline | dp_invalid | unknown) and the propagation
 * of code/msg so Bug 2 (light does not turn off) can be diagnosed.
 *
 * Pure and network-free: no DB, no Tuya credentials, no curl.
 *
 * Run:
 *   php tests/Unit/TuyaSwitchResponseTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Gateways\Switch\TuyaSwitchGateway;

$passed = 0;
$failed = 0;

function pass(string $msg): void
{
    global $passed;
    $passed++;
    echo "  ✅ {$msg}\n";
}

function fail(string $msg): void
{
    global $failed;
    $failed++;
    echo "  ❌ {$msg}\n";
}

/**
 * @param array{ok:bool, code:int|string|null, msg:string|null, category:string} $r
 */
function check(string $label, bool $cond, array $r): void
{
    if ($cond) {
        pass($label);
    } else {
        fail($label . ' — got ' . json_encode($r));
    }
}

echo "TuyaSwitchGateway::classifyResponse()\n";
echo str_repeat('=', 60) . "\n\n";

// T1: success=true → ok, category ok
$r = TuyaSwitchGateway::classifyResponse(200, ['success' => true, 'result' => true, 'code' => 0, 'msg' => 'ok']);
check('success=true → ok / category=ok', $r['ok'] === true && $r['category'] === 'ok', $r);

// T2: quota by code 28841105 (IoT Core quota)
$r = TuyaSwitchGateway::classifyResponse(200, ['success' => false, 'code' => 28841105, 'msg' => 'something went wrong']);
check('code 28841105 → quota', $r['ok'] === false && $r['category'] === 'quota', $r);

// T3: quota by code 28841107
$r = TuyaSwitchGateway::classifyResponse(200, ['success' => false, 'code' => 28841107, 'msg' => 'something went wrong']);
check('code 28841107 → quota', $r['category'] === 'quota', $r);

// T4: quota by HTTP 429
$r = TuyaSwitchGateway::classifyResponse(429, ['success' => false, 'code' => null, 'msg' => 'too many requests']);
check('HTTP 429 → quota', $r['category'] === 'quota', $r);

// T5: quota by message keyword
$r = TuyaSwitchGateway::classifyResponse(200, ['success' => false, 'code' => 1, 'msg' => 'daily quota exhausted']);
check('msg "quota exhausted" → quota', $r['category'] === 'quota', $r);

// T6: offline by code 2008
$r = TuyaSwitchGateway::classifyResponse(200, ['success' => false, 'code' => 2008, 'msg' => 'permission deny']);
check('code 2008 → offline', $r['category'] === 'offline', $r);

// T7: offline by message
$r = TuyaSwitchGateway::classifyResponse(200, ['success' => false, 'code' => 1, 'msg' => 'device is offline']);
check('msg "device is offline" → offline', $r['category'] === 'offline', $r);

// T8: dp_invalid by message ("dp not exist")
$r = TuyaSwitchGateway::classifyResponse(200, ['success' => false, 'code' => 1, 'msg' => 'dp not exist']);
check('msg "dp not exist" → dp_invalid', $r['category'] === 'dp_invalid', $r);

// T9: dp_invalid by code 1106
$r = TuyaSwitchGateway::classifyResponse(200, ['success' => false, 'code' => 1106, 'msg' => 'command error']);
check('code 1106 → dp_invalid', $r['category'] === 'dp_invalid', $r);

// T10: unknown
$r = TuyaSwitchGateway::classifyResponse(200, ['success' => false, 'code' => 999999, 'msg' => 'weird failure']);
check('unclassified → unknown', $r['category'] === 'unknown' && $r['ok'] === false, $r);

// T11: code/msg propagate on failure
$r = TuyaSwitchGateway::classifyResponse(500, ['success' => false, 'code' => 2008, 'msg' => 'device offline']);
check(
    'failure propagates code/msg',
    $r['code'] === 2008 && $r['msg'] === 'device offline',
    $r
);

// T12: code/msg propagate on success
$r = TuyaSwitchGateway::classifyResponse(200, ['success' => true, 'code' => 0, 'msg' => 'ok']);
check(
    'success propagates code/msg',
    $r['code'] === 0 && $r['msg'] === 'ok',
    $r
);

// T13: missing fields do not crash and default to unknown
$r = TuyaSwitchGateway::classifyResponse(200, ['success' => false]);
check(
    'missing code/msg → unknown with nulls',
    $r['category'] === 'unknown' && $r['code'] === null && $r['msg'] === null,
    $r
);

echo "\n";
echo str_repeat('=', 60) . "\n";
echo sprintf("Total: %d passed, %d failed\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
