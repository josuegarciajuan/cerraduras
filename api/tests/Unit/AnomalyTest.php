<?php
declare(strict_types=1);

/**
 * Unit tests for Anomaly detectors (F35).
 *
 * Tests detectors that do NOT require PDO (A2, A5, A6, A7) plus
 * the Anomaly model lifecycle (acknowledge, dismiss).
 *
 * Detectors requiring DB queries (A1, A3, A4, A8) are tested via
 * HTTP/integration tests in BLOCK 25 of run-tests.sh.
 *
 * Run:
 *   php tests/Unit/AnomalyTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Anomalies\Anomaly;
use App\Domain\Anomalies\AnomalyDetector;
use App\Domain\Anomalies\AnomalyResult;
use App\Domain\Anomalies\Detectors\PresenceWithoutStay;
use App\Domain\Anomalies\Detectors\ExitWithoutDoorOpen;
use App\Domain\Anomalies\Detectors\PresenceAfterExit;
use App\Domain\Anomalies\Detectors\DoorOpenWithoutPresence;
use App\Domain\Presence\IotSession;
use App\Domain\Presence\PresenceEvent;
use App\Domain\Stays\Stay;

// ============================================================================
// Helpers
// ============================================================================
$PASS = 0; $FAIL = 0;
function pass(string $l): void  { global $PASS; $PASS++; echo "  PASS  {$l}\n"; }
function fail(string $l, string $w=''): void { global $FAIL; $FAIL++; echo "  FAIL  {$l} {$w}\n"; }

function makeEvent(string $sensor, string $value): PresenceEvent {
    return new PresenceEvent(0, 1, $sensor, $value, 'SIMULATED',
        '2026-07-25T10:00:00Z', '2026-07-25T10:00:00Z', null, null);
}

function makeSession(
    string $doorState = IotSession::DOOR_CLOSED,
    string $presenceState = IotSession::PRESENCE_ABSENT,
    ?string $lastOpenAt = null,
    ?string $lastCloseAt = null,
    ?string $lastAbsentSince = null
): IotSession {
    return new IotSession(0, 1, null, $doorState, $presenceState,
        $lastOpenAt, $lastCloseAt, $lastAbsentSince, null, '');
}

function makeStay(string $status, ?string $firstEntryAt = null, ?string $exitDetectedAt = null, int $duracionMinutos = 60): Stay {
    return new Stay(1, 1, $status, $duracionMinutos,
        '2026-07-25T09:00:00.000', $firstEntryAt, $exitDetectedAt, null,
        null, null, null, null, null, null, null, null, null,
        '2026-07-25T09:00:00.000', '2026-07-25T09:00:00.000');
}

echo "AnomalyDetectors\n";

// ============================================================================
// A2 - PresenceWithoutStay
// ============================================================================
$dA2 = new PresenceWithoutStay();

// T1: Presence without stay → anomaly detected
$r = $dA2->detect(makeSession(IotSession::DOOR_CLOSED, IotSession::PRESENCE_PRESENT), makeEvent('PRESENCE', 'PRESENT'), null);
if ($r !== null && $r->type === 'A2') pass('A2: presence without stay → detected');
else fail('A2: presence without stay → detected');

// T2: Presence with OCCUPIED stay → no anomaly
$stay = makeStay(Stay::STATUS_OCCUPIED, '2026-07-25T10:00:00.000');
$r = $dA2->detect(makeSession(IotSession::DOOR_CLOSED, IotSession::PRESENCE_PRESENT), makeEvent('PRESENCE', 'PRESENT'), $stay);
if ($r === null) pass('A2: presence with OCCUPIED stay → no anomaly');
else fail('A2: presence with OCCUPIED stay → no anomaly');

// T3: Non-PRESENCE event → no trigger
$r = $dA2->detect(makeSession(IotSession::DOOR_OPEN, IotSession::PRESENCE_PRESENT), makeEvent('PROXIMITY', 'OPEN'), null);
if ($r === null) pass('A2: PROXIMITY event → no trigger');
else fail('A2: PROXIMITY event → no trigger');

// ============================================================================
// A5 - ExitWithoutDoorOpen
// ============================================================================
$dA5 = new ExitWithoutDoorOpen();

// T4: Exit with recent door open → no anomaly
$stayOccupied = makeStay(Stay::STATUS_OCCUPIED, '2026-07-25T10:00:00.000');
$exitDetectedAt = '2026-07-25T12:00:00.000';
$lastOpenAt = '2026-07-25T11:59:50.000'; // 10s before exit
$r = $dA5->check(makeSession(IotSession::DOOR_CLOSED, IotSession::PRESENCE_ABSENT, $lastOpenAt),
    $stayOccupied, $exitDetectedAt, $lastOpenAt, $stayOccupied->firstEntryAt);
if ($r === null) pass('A5: exit with recent door open → no anomaly');
else fail('A5: exit with recent door open → no anomaly');

// T5: Exit with no last_open_at → anomaly
$r = $dA5->check(makeSession(IotSession::DOOR_CLOSED, IotSession::PRESENCE_ABSENT),
    $stayOccupied, $exitDetectedAt, null, $stayOccupied->firstEntryAt);
if ($r !== null && $r->type === 'A5') pass('A5: exit without door open → detected');
else fail('A5: exit without door open → detected');

// T6: Exit with stale door open (3h ago) → anomaly
$staleOpen = '2026-07-25T08:59:00.000'; // 3h before exit
$r = $dA5->check(makeSession(IotSession::DOOR_CLOSED, IotSession::PRESENCE_ABSENT, $staleOpen),
    $stayOccupied, $exitDetectedAt, $staleOpen, $stayOccupied->firstEntryAt);
if ($r !== null && $r->type === 'A5') pass('A5: exit with stale door open → detected');
else fail('A5: exit with stale door open → detected');

// ============================================================================
// A6 - PresenceAfterExit
// ============================================================================
$dA6 = new PresenceAfterExit();

// T7: Presence after EXITED stay → anomaly
$stayExited = makeStay(Stay::STATUS_EXITED, '2026-07-25T10:00:00.000', '2026-07-25T12:00:00.000');
$r = $dA6->detect(makeSession(IotSession::DOOR_CLOSED, IotSession::PRESENCE_PRESENT), makeEvent('PRESENCE', 'PRESENT'), $stayExited);
if ($r !== null && $r->type === 'A6') pass('A6: presence after exit → detected');
else fail('A6: presence after exit → detected');

// T8: Presence with OCCUPIED stay → no anomaly
$r = $dA6->detect(makeSession(IotSession::DOOR_CLOSED, IotSession::PRESENCE_PRESENT), makeEvent('PRESENCE', 'PRESENT'), $stayOccupied);
if ($r === null) pass('A6: presence with OCCUPIED stay → no anomaly');
else fail('A6: presence with OCCUPIED stay → no anomaly');

// T9: EXITED but exit_detected_at is null → no anomaly
$stayExitedNoTimestamp = makeStay(Stay::STATUS_EXITED, '2026-07-25T10:00:00.000', null);
$r = $dA6->detect(makeSession(IotSession::DOOR_CLOSED, IotSession::PRESENCE_PRESENT), makeEvent('PRESENCE', 'PRESENT'), $stayExitedNoTimestamp);
if ($r === null) pass('A6: EXITED without exit timestamp → no anomaly');
else fail('A6: EXITED without exit timestamp → no anomaly');

// ============================================================================
// A7 - DoorOpenWithoutPresence
// ============================================================================
$dA7 = new DoorOpenWithoutPresence(120);

// T10: Door open with presence → no anomaly
$r = $dA7->detect(makeSession(IotSession::DOOR_OPEN, IotSession::PRESENCE_PRESENT), makeEvent('PROXIMITY', 'OPEN'), null);
if ($r === null) pass('A7: door open with presence → no anomaly');
else fail('A7: door open with presence → no anomaly');

// T11: Door closed → no anomaly
$r = $dA7->detect(makeSession(IotSession::DOOR_CLOSED, IotSession::PRESENCE_ABSENT), makeEvent('PROXIMITY', 'CLOSED'), null);
if ($r === null) pass('A7: door closed → no anomaly');
else fail('A7: door closed → no anomaly');

// T12: Door open + absence but not long enough → no anomaly
$recentOpen = gmdate('Y-m-d H:i:s', time() - 60) . '.000'; // 60s ago, less than 120 threshold
$session = makeSession(IotSession::DOOR_OPEN, IotSession::PRESENCE_ABSENT, $recentOpen);
$r = $dA7->detect($session, makeEvent('PROXIMITY', 'OPEN'), $stayOccupied);
if ($r === null) pass('A7: open+absent <120s → no anomaly');
else fail('A7: open+absent <120s → no anomaly');

// T13: Door open + absence >120s → anomaly
$oldOpen = gmdate('Y-m-d H:i:s', time() - 180) . '.000'; // 180s ago
$session = makeSession(IotSession::DOOR_OPEN, IotSession::PRESENCE_ABSENT, $oldOpen);
$r = $dA7->detect($session, makeEvent('PROXIMITY', 'OPEN'), $stayOccupied);
if ($r !== null && $r->type === 'A7') pass('A7: open+absent >120s → detected');
else fail('A7: open+absent >120s → detected');

// T14: Door open + absence but no last_open_at → no anomaly
$session = makeSession(IotSession::DOOR_OPEN, IotSession::PRESENCE_ABSENT, null);
$r = $dA7->detect($session, makeEvent('PROXIMITY', 'OPEN'), $stayOccupied);
if ($r === null) pass('A7: no last_open_at → no anomaly');
else fail('A7: no last_open_at → no anomaly');

// ============================================================================
// Anomaly model lifecycle
// ============================================================================
echo "\nAnomaly Model\n";

$now = '2026-07-25T12:00:00.000';
$anom = new Anomaly(1, 1, 1, 'A2', 'HIGH', Anomaly::STATUS_OPEN,
    ['door_state' => 'CLOSED'], $now, null, null, null, null, $now, $now);

// T15: Initial state is OPEN
if ($anom->status === Anomaly::STATUS_OPEN) pass('Model: initial status OPEN');
else fail('Model: initial status OPEN');

// T16: Acknowledge → ACKNOWLEDGED
$anom->acknowledge('test-actor', $now);
if ($anom->status === Anomaly::STATUS_ACKNOWLEDGED && $anom->acknowledgedBy === 'test-actor')
    pass('Model: acknowledge → ACKNOWLEDGED');
else fail('Model: acknowledge → ACKNOWLEDGED');

// T17: Auto-dismiss → DISMISSED
$anom->autoDismiss($now);
if ($anom->status === Anomaly::STATUS_DISMISSED && $anom->dismissedBy === 'system')
    pass('Model: autoDismiss → DISMISSED');
else fail('Model: autoDismiss → DISMISSED');

// T18: Acknowledge on non-OPEN → throws
$anom2 = new Anomaly(2, 1, 1, 'A2', 'HIGH', Anomaly::STATUS_DISMISSED,
    [], $now, null, null, null, null, $now, $now);
try {
    $anom2->acknowledge('actor', $now);
    fail('Model: acknowledge on DISMISSED → throws');
} catch (\RuntimeException $e) {
    pass('Model: acknowledge on DISMISSED → throws');
}

// T19: Auto-dismiss is idempotent
try {
    $anom->autoDismiss($now);
    pass('Model: autoDismiss idempotent');
} catch (\Throwable $e) {
    fail('Model: autoDismiss idempotent');
}

// ============================================================================
// AnomalyResult DTO
// ============================================================================
echo "\nAnomalyResult DTO\n";

$ctx = ['door' => 'OPEN', 'presence' => 'ABSENT'];
$result = new AnomalyResult('A7', 'MEDIUM', $ctx);
if ($result->type === 'A7' && $result->severity === 'MEDIUM' && $result->contextData === $ctx)
    pass('DTO: AnomalyResult holds correct data');
else fail('DTO: AnomalyResult holds correct data');

// ============================================================================
// Severity map
// ============================================================================
echo "\nSeverity Map\n";
$map = Anomaly::SEVERITY_MAP;
if ($map['A1'] === 'HIGH' && $map['A3'] === 'CRITICAL' && $map['A8'] === 'LOW')
    pass('Severity: map has correct values');
else fail('Severity: map has correct values');

echo "\nTotal: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
