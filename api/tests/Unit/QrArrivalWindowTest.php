<?php
declare(strict_types=1);

/**
 * Unit tests: QrWindows — two-phase guest QR lifetime (Fase 51 / Bug 4).
 *
 * Pure logic, no DB / clock / HTTP. Covers the four contract cases:
 *   (a) never used, inside arrival window      → allowed (ok_unused)
 *   (b) never used, past arrival deadline      → qr_expired{arrival}
 *   (c) already used, inside usage window      → re-entry allowed (ok_in_use)
 *   (d) already used, past usage deadline      → qr_expired{usage}
 *
 * Run:
 *   php tests/Unit/QrArrivalWindowTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Qr\QrWindows;

$passed = 0;
$failed = 0;

function pass(string $label): void {
    global $passed;
    echo "  PASS  {$label}\n";
    $passed++;
}

function fail(string $label, string $msg = ''): void {
    global $failed;
    echo "  FAIL  {$label}";
    if ($msg) echo " — {$msg}";
    echo "\n";
    $failed++;
}

function expect_state(string $expected, string $label, int $issued, ?int $firstUsed, ?int $validUntil, int $dur, int $arrival, int $now): void {
    $got = QrWindows::evaluate($issued, $firstUsed, $validUntil, $dur, $arrival, $now);
    if ($got === $expected) {
        pass($label);
    } else {
        fail($label, "expected {$expected}, got {$got}");
    }
}

echo "QrWindows (Fase 51 / Bug 4)\n";

// Fixed reference epoch; all offsets are seconds.
$T            = 1_700_000_000;
$ARRIVAL_MIN  = 15;
$DUR_MIN      = 60;

// ---------------------------------------------------------------------------
// (a) never used, inside arrival window → allowed
// ---------------------------------------------------------------------------
expect_state(
    QrWindows::STATE_OK_UNUSED,
    '(a) never used + inside arrival window → ok_unused',
    $T, null, null, $DUR_MIN, $ARRIVAL_MIN, $T + 5 * 60
);

// ---------------------------------------------------------------------------
// (b) never used, past arrival deadline → qr_expired {arrival}
// ---------------------------------------------------------------------------
expect_state(
    QrWindows::STATE_EXPIRED_ARRIVAL,
    '(b) never used + past arrival window → expired_arrival',
    $T, null, null, $DUR_MIN, $ARRIVAL_MIN, $T + 16 * 60
);

// Boundary: exactly at the arrival deadline is still valid (strict >).
expect_state(
    QrWindows::STATE_OK_UNUSED,
    '(b-bis) exactly at arrival deadline → still ok_unused',
    $T, null, null, $DUR_MIN, $ARRIVAL_MIN, $T + 15 * 60
);

// ---------------------------------------------------------------------------
// (c) already used, inside usage window → re-entry allowed
// ---------------------------------------------------------------------------
$firstUse = $T + 5 * 60;
expect_state(
    QrWindows::STATE_OK_IN_USE,
    '(c) used + inside usage window → ok_in_use (re-entry)',
    $T, $firstUse, $firstUse + $DUR_MIN * 60, $DUR_MIN, $ARRIVAL_MIN, $T + 30 * 60
);

// ---------------------------------------------------------------------------
// (d) already used, past usage deadline → qr_expired {usage}
// ---------------------------------------------------------------------------
expect_state(
    QrWindows::STATE_EXPIRED_USAGE,
    '(d) used + past usage window → expired_usage',
    $T, $T, $T + $DUR_MIN * 60, $DUR_MIN, $ARRIVAL_MIN, $T + 61 * 60
);

// Boundary: exactly at the usage deadline is still valid (strict >).
expect_state(
    QrWindows::STATE_OK_IN_USE,
    '(d-bis) exactly at usage deadline → still ok_in_use',
    $T, $T, $T + $DUR_MIN * 60, $DUR_MIN, $ARRIVAL_MIN, $T + 60 * 60
);

// ---------------------------------------------------------------------------
// Legacy fallback: first use with valid_until = NULL derives the deadline
// from first_used_at + duracion_minutos.
// ---------------------------------------------------------------------------
expect_state(
    QrWindows::STATE_OK_IN_USE,
    'legacy fallback (valid_until null) inside duracion → ok_in_use',
    $T, $T, null, $DUR_MIN, $ARRIVAL_MIN, $T + 30 * 60
);
expect_state(
    QrWindows::STATE_EXPIRED_USAGE,
    'legacy fallback (valid_until null) past duracion → expired_usage',
    $T, $T, null, $DUR_MIN, $ARRIVAL_MIN, $T + 61 * 60
);

// valid_until (when present) is authoritative and wins over the derived one.
expect_state(
    QrWindows::STATE_EXPIRED_USAGE,
    'valid_until wins over derived deadline (shorter window)',
    $T, $T, $T + 10 * 60, $DUR_MIN, $ARRIVAL_MIN, $T + 11 * 60
);

// ---------------------------------------------------------------------------
// Deadline helpers
// ---------------------------------------------------------------------------
if (QrWindows::arrivalDeadline($T, $ARRIVAL_MIN) === $T + $ARRIVAL_MIN * 60) {
    pass('arrivalDeadline = issued + arrival minutes');
} else {
    fail('arrivalDeadline helper');
}
if (QrWindows::usageDeadline($T, $DUR_MIN) === $T + $DUR_MIN * 60) {
    pass('usageDeadline = firstUsed + duracion minutes');
} else {
    fail('usageDeadline helper');
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
