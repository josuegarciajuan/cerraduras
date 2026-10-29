<?php
declare(strict_types=1);

/**
 * Unit tests for F99 access-key reactivation rules (no DB, no network).
 *
 * Run: php tests/Unit/AccessKeyReactivationTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\AccessKeys\AccessKeyReactivationPolicy;

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

function check(string $label, bool $cond, string $detail = ''): void
{
    $cond ? ok($label) : bad($label, $detail);
}

$now = 2000000000;
$future = gmdate('Y-m-d H:i:s', $now + 3600);
$past   = gmdate('Y-m-d H:i:s', $now - 3600);

$revokedRole = [
    'id' => 10,
    'status' => 'REVOKED',
    'owner_type' => 'ROLE',
    'key_type' => 'QR',
    'valid_until' => $future,
];

$r = AccessKeyReactivationPolicy::evaluate($revokedRole, null, null, $now);
check('T1: revoked, valid role key can be reactivated', $r['ok'] === true, (string) ($r['code'] ?? ''));

$active = $revokedRole;
$active['status'] = 'ACTIVE';
$r = AccessKeyReactivationPolicy::evaluate($active, null, null, $now);
check('T2: an active key is rejected', $r['code'] === 'not_revoked', (string) ($r['code'] ?? ''));

$expired = $revokedRole;
$expired['valid_until'] = $past;
$r = AccessKeyReactivationPolicy::evaluate($expired, null, null, $now);
check('T3: an expired key is rejected', $r['code'] === 'key_expired', (string) ($r['code'] ?? ''));

$workerKey = $revokedRole;
$workerKey['owner_type'] = 'WORKER';
$workerKey['worker_id'] = 7;
$r = AccessKeyReactivationPolicy::evaluate($workerKey, false, null, $now);
check('T4: personal key with inactive worker is rejected', $r['code'] === 'worker_inactive', (string) ($r['code'] ?? ''));
$r = AccessKeyReactivationPolicy::evaluate($workerKey, true, null, $now);
check('T5: personal key with active worker can be reactivated', $r['ok'] === true, (string) ($r['code'] ?? ''));

$nfcKey = $revokedRole;
$nfcKey['key_type'] = 'NFC';
$nfcKey['nfc_card_id'] = 3;

$cardAvailable = ['id' => 3, 'status' => 'AVAILABLE', 'current_key_id' => null];
$r = AccessKeyReactivationPolicy::evaluate($nfcKey, null, $cardAvailable, $now);
check('T6: NFC key with available card can be reactivated', $r['ok'] === true, (string) ($r['code'] ?? ''));

$cardDisabled = ['id' => 3, 'status' => 'DISABLED', 'current_key_id' => null];
$r = AccessKeyReactivationPolicy::evaluate($nfcKey, null, $cardDisabled, $now);
check('T7: disabled card blocks reactivation', $r['code'] === 'nfc_card_unavailable', (string) ($r['code'] ?? ''));

$cardSame = ['id' => 3, 'status' => 'ASSIGNED', 'current_key_id' => 10];
$r = AccessKeyReactivationPolicy::evaluate($nfcKey, null, $cardSame, $now);
check('T8: card still bound to the same key is accepted', $r['ok'] === true, (string) ($r['code'] ?? ''));

$cardOther = ['id' => 3, 'status' => 'ASSIGNED', 'current_key_id' => 99];
$r = AccessKeyReactivationPolicy::evaluate($nfcKey, null, $cardOther, $now);
check('T9: card bound to another key blocks reactivation', $r['code'] === 'nfc_card_unavailable', (string) ($r['code'] ?? ''));

$r = AccessKeyReactivationPolicy::evaluate($nfcKey, null, null, $now);
check('T10: missing card blocks reactivation', $r['code'] === 'nfc_card_unavailable', (string) ($r['code'] ?? ''));

echo "\n  Result: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
