<?php
declare(strict_types=1);

/**
 * Unit tests for F96 access-key QR tokens (no DB).
 *
 * Run: php tests/Unit/AccessKeyTokenTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Support\Errors\ApiException;
use App\Support\Qr\QrTokenizer;

$pass = 0;
$fail = 0;

function ok(string $label): void
{
    global $pass;
    $pass++;
    echo "  PASS  {$label}\n";
}

function bad(string $label, string $detail = ''): void
{
    global $fail;
    $fail++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$tokenizer = new QrTokenizer(str_repeat('k', 32));
$jti = '550e8400-e29b-41d4-a716-446655440099';
$iat = 1700000000;
$exp = $iat + 3600;

$token = $tokenizer->issueKey(42, $jti, $iat, $exp);
$claims = $tokenizer->parse($token);

($claims['sub'] === 'key') ? ok('T1: key token discriminated by sub=key') : bad('T1', (string) ($claims['sub'] ?? ''));
((int) $claims['kid'] === 42) ? ok('T2: kid preserved') : bad('T2', (string) $claims['kid']);
($claims['jti'] === $jti) ? ok('T3: jti preserved') : bad('T3', (string) $claims['jti']);
((int) $claims['exp'] === $exp) ? ok('T4: exp preserved') : bad('T4', (string) $claims['exp']);

$tampered = $token;
$tampered[10] = $tampered[10] === 'A' ? 'B' : 'A';
try {
    $tokenizer->parse($tampered);
    bad('T5: tampered token rejected');
} catch (ApiException $e) {
    ($e->errorCode() === 'qr_invalid_signature') ? ok('T5: tampered token rejected') : bad('T5', $e->errorCode());
}

$other = new QrTokenizer(str_repeat('z', 32));
try {
    $other->parse($token);
    bad('T6: token signed with another secret rejected');
} catch (ApiException $e) {
    ($e->errorCode() === 'qr_invalid_signature') ? ok('T6: wrong secret rejected') : bad('T6', $e->errorCode());
}

$hashA = QrTokenizer::hashForStorage($token);
$hashB = QrTokenizer::hashForStorage($token);
($hashA === $hashB && strlen($hashA) === 64) ? ok('T7: storage hash is stable SHA-256') : bad('T7', $hashA);

try {
    $tokenizer->issueKey(1, $jti, $exp, $exp);
    bad('T8: exp must be greater than iat');
} catch (InvalidArgumentException $e) {
    ok('T8: exp must be greater than iat');
}

echo "\n  Result: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
