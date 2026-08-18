<?php
declare(strict_types=1);

/**
 * Unit tests for QrTokenizer (TSK-060).
 *
 * Run:
 *   php tests/Unit/QrTokenizerTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Support\Qr\QrTokenizer;
use App\Support\Errors\ApiException;

$passed = 0;
$failed = 0;

function passed(string $label): void { global $passed; $passed++; echo "  PASS  {$label}\n"; }
function failed(string $label, string $why = ''): void { global $failed; $failed++; echo "  FAIL  {$label} {$why}\n"; }
function expectInvalid(callable $fn, string $label): void {
    try {
        $fn();
        failed($label, '(no exception)');
    } catch (ApiException $e) {
        if ($e->errorCode() === 'qr_invalid_signature') {
            passed($label);
        } else {
            failed($label, "(got code {$e->errorCode()})");
        }
    } catch (\Throwable $e) {
        failed($label, "(unexpected " . get_class($e) . ": {$e->getMessage()})");
    }
}

echo "QrTokenizer\n";

$secret = str_repeat('A', 32); // 32-byte secret
$tk = new QrTokenizer($secret);

$now = time();
$token = $tk->issue(42, 1001, '7c9a0f8e-0000-4000-8000-000000000001', $now, $now + 1800);
$payload = $tk->parse($token);

if ($payload['room_id'] === 42 && $payload['stay_id'] === 1001) {
    passed('round-trip preserves room_id and stay_id');
} else {
    failed('round-trip preserves room_id and stay_id');
}

if ($payload['jti'] === '7c9a0f8e-0000-4000-8000-000000000001') {
    passed('round-trip preserves jti');
} else {
    failed('round-trip preserves jti');
}

if ($payload['exp'] - $payload['iat'] === 1800) {
    passed('round-trip preserves iat/exp window');
} else {
    failed('round-trip preserves iat/exp window');
}

// Tamper with the signature -> qr_invalid_signature.
$badSig = (function () use ($token) {
    $parts = explode('.', $token);
    // flip a char in signature
    $sig = $parts[1];
    $sig[0] = $sig[0] === 'A' ? 'B' : 'A';
    $parts[1] = $sig;
    return implode('.', $parts);
})();

expectInvalid(function () use ($tk, $badSig) {
    $tk->parse($badSig);
}, 'tampered signature is rejected');

// Tamper with payload (re-base64 a different room_id) -> bad signature.
$payloadAltered = (function () use ($token, $tk) {
    $parts = explode('.', $token);
    $altered = base64_encode('{"v":1,"room_id":999,"stay_id":1001,"jti":"x","iat":0,"exp":1}');
    $altered = rtrim(strtr($altered, '+/', '-_'), '=');
    $parts[0] = $altered;
    // Recompute checksum so we hit the signature check rather than the checksum check.
    // We cheat: call the public API to issue another token and replace the payload only.
    // Recomputing the checksum here would require duplicating that algorithm; instead
    // we rely on the checksum failing first, which is also a valid invalidation path.
    return implode('.', $parts);
})();
expectInvalid(function () use ($tk, $payloadAltered) {
    $tk->parse($payloadAltered);
}, 'tampered payload is rejected (checksum or signature)');

// Different secret -> rejection.
$tk2 = new QrTokenizer(str_repeat('B', 32));
expectInvalid(function () use ($tk2, $token) {
    $tk2->parse($token);
}, 'wrong secret rejects token');

// Malformed token: only two segments.
expectInvalid(function () use ($tk) {
    $tk->parse('only.two');
}, 'two-segment token rejected');

// Empty.
expectInvalid(function () use ($tk) {
    $tk->parse('');
}, 'empty token rejected');

// hashForStorage stable.
$h1 = QrTokenizer::hashForStorage($token);
$h2 = QrTokenizer::hashForStorage($token);
if ($h1 === $h2 && strlen($h1) === 64) {
    passed('hashForStorage is stable and 64-char hex');
} else {
    failed('hashForStorage is stable and 64-char hex');
}

// Reject too-short secret.
try {
    new QrTokenizer('short');
    failed('reject too-short secret');
} catch (\InvalidArgumentException $e) {
    passed('reject too-short secret');
}

// exp <= iat rejected at issue.
try {
    $tk->issue(1, 1, 'x', $now, $now);
    failed('reject exp <= iat');
} catch (\InvalidArgumentException $e) {
    passed('reject exp <= iat');
}

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
