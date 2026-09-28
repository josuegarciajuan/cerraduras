<?php
declare(strict_types=1);

/**
 * Unit tests for TuyaCreditsClassifier::classifyTuyaCredits().
 *
 * Pins the read-only "does the account have IoT Core credits?" taxonomy used by
 * GET /dashboard-api/tuya-quota. Pure and network-free: no DB, no Tuya
 * credentials, no curl (never consumes real quota).
 *
 * Run:
 *   php tests/Unit/TuyaCreditsTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\TuyaCreditsClassifier;

$passed = 0;
$failed = 0;

function pass(string $label): void
{
    global $passed;
    $passed++;
    echo "  PASS  {$label}\n";
}

function fail(string $label, string $msg = ''): void
{
    global $failed;
    $failed++;
    echo "  FAIL  {$label}";
    if ($msg !== '') {
        echo " — {$msg}";
    }
    echo "\n";
}

/**
 * @param array{credits:string, category:string} $r
 */
function check(string $label, string $expCredits, string $expCategory, array $r): void
{
    if ($r['credits'] === $expCredits && $r['category'] === $expCategory) {
        pass($label);
    } else {
        fail($label, 'expected ' . $expCredits . '/' . $expCategory . ' got ' . json_encode($r));
    }
}

echo "TuyaCreditsClassifier (Fase 53)\n";
echo str_repeat('=', 60) . "\n\n";

// T1: success=true → credits ok / category ok
$r = TuyaCreditsClassifier::classifyTuyaCredits(200, ['success' => true, 'result' => ['online' => true], 'code' => 0, 'msg' => 'ok']);
check('success=true → ok/ok', 'ok', 'ok', $r);

// T2: code 28841004 (IoT Core trial quota) → exhausted/quota
$r = TuyaCreditsClassifier::classifyTuyaCredits(200, [
    'success' => false, 'code' => 28841004, 'msg' => 'IoT Core trial quota is exhausted.',
]);
check('code 28841004 → exhausted/quota', 'exhausted', 'quota', $r);

// T3: code 28841105 → exhausted/quota
$r = TuyaCreditsClassifier::classifyTuyaCredits(200, [
    'success' => false, 'code' => 28841105, 'msg' => 'something went wrong',
]);
check('code 28841105 → exhausted/quota', 'exhausted', 'quota', $r);

// T4: code 28841107 → exhausted/quota
$r = TuyaCreditsClassifier::classifyTuyaCredits(200, [
    'success' => false, 'code' => 28841107, 'msg' => 'something went wrong',
]);
check('code 28841107 → exhausted/quota', 'exhausted', 'quota', $r);

// T5: HTTP 429 → exhausted/quota
$r = TuyaCreditsClassifier::classifyTuyaCredits(429, [
    'success' => false, 'code' => null, 'msg' => 'too many requests',
]);
check('HTTP 429 → exhausted/quota', 'exhausted', 'quota', $r);

// T6: msg keyword (real Tuya trial message) → exhausted/quota
$r = TuyaCreditsClassifier::classifyTuyaCredits(200, [
    'success' => false, 'code' => 1, 'msg' => 'IoT Core trial quota is exhausted.',
]);
check('msg "quota is exhausted" → exhausted/quota', 'exhausted', 'quota', $r);

// T6b: msg keyword "limit" → exhausted/quota (case-insensitive)
$r = TuyaCreditsClassifier::classifyTuyaCredits(200, [
    'success' => false, 'code' => 1, 'msg' => 'Call Limit exceeded',
]);
check('msg "Call Limit" → exhausted/quota', 'exhausted', 'quota', $r);

// T7: code 2008 → ok/offline (quota works, device is unreachable)
$r = TuyaCreditsClassifier::classifyTuyaCredits(200, [
    'success' => false, 'code' => 2008, 'msg' => 'permission deny',
]);
check('code 2008 → ok/offline', 'ok', 'offline', $r);

// T8: msg "device is offline" → ok/offline
$r = TuyaCreditsClassifier::classifyTuyaCredits(200, [
    'success' => false, 'code' => 1, 'msg' => 'device is offline',
]);
check('msg "device is offline" → ok/offline', 'ok', 'offline', $r);

// T9: empty response (token/network failure) → unknown/unknown
$r = TuyaCreditsClassifier::classifyTuyaCredits(502, []);
check('empty response → unknown/unknown', 'unknown', 'unknown', $r);

// T10: unclassified logical failure → unknown/unknown
$r = TuyaCreditsClassifier::classifyTuyaCredits(200, [
    'success' => false, 'code' => 999999, 'msg' => 'weird failure',
]);
check('unclassified → unknown/unknown', 'unknown', 'unknown', $r);

// T11: missing code/msg must not crash
$r = TuyaCreditsClassifier::classifyTuyaCredits(200, ['success' => false]);
check('missing code/msg → unknown/unknown', 'unknown', 'unknown', $r);

// T12: success wins over a quota-looking code (logical success = credits ok)
$r = TuyaCreditsClassifier::classifyTuyaCredits(200, ['success' => true, 'code' => 0, 'msg' => 'ok']);
check('success=true short-circuits to ok/ok', 'ok', 'ok', $r);

echo "\n";
echo str_repeat('=', 60) . "\n";
echo sprintf("Total: %d passed, %d failed\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
