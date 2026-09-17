<?php
declare(strict_types=1);

/**
 * Unit tests for SensorEventDecision (Fase 41, RF-44; contracts.md §2).
 *
 * Fixes the contract of the ordering/idempotency decision:
 *   - fingerprint = sha1(room_id|sensor|value|second), so the same fact re-sent
 *     with a different transport `t` in the same second collapses to one id,
 *     while OPEN and CLOSED in the same second do not collide.
 *   - decide() classifies an event as apply / duplicate / stale / noop.
 *   - A discarded decision still carries a reason, so the caller can persist the
 *     raw event with applied=0 + discard_reason (RF-44.3).
 *
 * Run:
 *   php tests/Unit/SensorEventDecisionTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\IotSession;
use App\Domain\Presence\PresenceEvent;
use App\Domain\Presence\SensorEventDecision;

$PASS = 0; $FAIL = 0;
function pass(string $m): void { global $PASS; $PASS++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $FAIL; $FAIL++; echo "  ❌ {$m}\n"; }

/**
 * Build a session with F41 ordering marks for the door sensor.
 */
function sessionWithDoor(?string $lastDoorEventAt, ?string $lastDoorValue): IotSession
{
    return new IotSession(
        1, 12, null,
        IotSession::DOOR_UNKNOWN,
        IotSession::PRESENCE_UNKNOWN,
        null, null, null, null, '',
        $lastDoorEventAt, null, $lastDoorValue, null
    );
}

function doorEvent(string $occurredAt, string $value = PresenceEvent::VALUE_OPEN): array
{
    return [
        'room_id'        => 12,
        'sensor'         => PresenceEvent::SENSOR_PROXIMITY,
        'value'          => $value,
        'provider'       => 'TUYA',
        'occurred_at'    => $occurredAt,
        'source_event_id'=> null,
        'meta'           => null,
    ];
}

/**
 * F48: session carrying the last applied presence mark (for presence events).
 */
function sessionWithPresence(?string $lastPresenceEventAt, ?string $lastPresenceValue): IotSession
{
    return new IotSession(
        1, 12, null,
        IotSession::DOOR_UNKNOWN,
        IotSession::PRESENCE_ABSENT,
        null, null, null, null, '',
        null, $lastPresenceEventAt, null, $lastPresenceValue
    );
}

function presenceEvent(string $occurredAt, string $value, string $raw): array
{
    return [
        'room_id'        => 12,
        'sensor'         => PresenceEvent::SENSOR_PRESENCE,
        'value'          => $value,
        'provider'       => 'TUYA',
        'occurred_at'    => $occurredAt,
        'source_event_id'=> null,
        'meta'           => ['tuya_raw_val' => $raw],
    ];
}

echo "SensorEventDecision (Fase 41)\n";
echo str_repeat("=", 60) . "\n\n";

// ── fingerprint ──────────────────────────────────────────────────────────
$fp1 = SensorEventDecision::fingerprint(12, 'PROXIMITY', 'OPEN', '2026-04-28T10:00:00Z');
$fp2 = SensorEventDecision::fingerprint(12, 'PROXIMITY', 'OPEN', '2026-04-28T10:00:00.750Z');
if ($fp1 === $fp2) pass('fingerprint: same fact, different t (same second) → same id');
else fail('fingerprint: same fact, different t (same second) should match');

$fp3 = SensorEventDecision::fingerprint(12, 'PROXIMITY', 'CLOSED', '2026-04-28T10:00:00Z');
if ($fp1 !== $fp3) pass('fingerprint: OPEN vs CLOSED in the same second → different id');
else fail('fingerprint: OPEN and CLOSED must not collide');

$fpOtherRoom = SensorEventDecision::fingerprint(13, 'PROXIMITY', 'OPEN', '2026-04-28T10:00:00Z');
if ($fp1 !== $fpOtherRoom) pass('fingerprint: different room → different id');
else fail('fingerprint: different room must not collide');

if (strlen($fp1) === 40 && ctype_xdigit($fp1)) pass('fingerprint: 40 hex chars (SHA-1)');
else fail('fingerprint: expected 40 hex chars, got ' . $fp1);

// ── decide: duplicate (logical identity already known) ───────────────────
$session = sessionWithDoor('2026-04-28 09:59:00.000', 'CLOSED');
$decision = SensorEventDecision::decide(
    doorEvent('2026-04-28T10:00:00Z', 'OPEN'), $session, true
);
if ($decision === SensorEventDecision::DUPLICATE) pass('decide: existing fingerprint → duplicate');
else fail('decide: existing fingerprint should be duplicate, got ' . $decision);

// ── decide: stale (fact older than last applied event of the sensor) ─────
$session = sessionWithDoor('2026-04-28 10:00:10.000', 'OPEN');
$decision = SensorEventDecision::decide(
    doorEvent('2026-04-28T10:00:05Z', 'CLOSED'), $session, false
);
if ($decision === SensorEventDecision::STALE) pass('decide: older than last applied → stale');
else fail('decide: older event should be stale, got ' . $decision);

// An old event cannot revert a newer CLOSED (regression guard).
$session = sessionWithDoor('2026-04-28 10:00:10.000', 'CLOSED');
$decision = SensorEventDecision::decide(
    doorEvent('2026-04-28T10:00:09Z', 'OPEN'), $session, false
);
if ($decision === SensorEventDecision::STALE) pass('decide: stale OPEN cannot revert a newer CLOSED');
else fail('decide: stale OPEN should be discarded, got ' . $decision);

// ── decide: duplicate (same instant + same value) ────────────────────────
$session = sessionWithDoor('2026-04-28 10:00:10.000', 'OPEN');
$decision = SensorEventDecision::decide(
    doorEvent('2026-04-28T10:00:10.400Z', 'OPEN'), $session, false
);
if ($decision === SensorEventDecision::DUPLICATE) pass('decide: same instant + value → duplicate');
else fail('decide: same instant + value should be duplicate, got ' . $decision);

// ── decide: noop (same value, different instant) ─────────────────────────
$session = sessionWithDoor('2026-04-28 10:00:10.000', 'OPEN');
$decision = SensorEventDecision::decide(
    doorEvent('2026-04-28T10:00:12Z', 'OPEN'), $session, false
);
if ($decision === SensorEventDecision::NOOP) pass('decide: value already in force → noop');
else fail('decide: same value should be noop, got ' . $decision);

// ── decide: apply (new transition) ───────────────────────────────────────
$session = sessionWithDoor('2026-04-28 10:00:10.000', 'CLOSED');
$decision = SensorEventDecision::decide(
    doorEvent('2026-04-28T10:00:11Z', 'OPEN'), $session, false
);
if ($decision === SensorEventDecision::APPLY) pass('decide: new transition → apply');
else fail('decide: new transition should apply, got ' . $decision);

// First-ever event (no marks) → apply.
$session = sessionWithDoor(null, null);
$decision = SensorEventDecision::decide(
    doorEvent('2026-04-28T10:00:00Z', 'OPEN'), $session, false
);
if ($decision === SensorEventDecision::APPLY) pass('decide: no prior marks → apply');
else fail('decide: first event should apply, got ' . $decision);

// ── F48 (RF-57): credibilidad de presencia por contexto ─────────────────
// move sin ventana de entrada → no creíble (dispara desde el pasillo).
$session = sessionWithPresence('2026-04-28 10:00:00.000', 'ABSENT');
$decision = SensorEventDecision::decide(
    presenceEvent('2026-04-28T10:00:05Z', 'PRESENT', 'move'), $session, false, false, false
);
if ($decision === SensorEventDecision::NO_CONTEXT) pass('F48: move sin ventana ni estancia → no_context');
else fail('F48: move sin contexto debería ser no_context, got ' . $decision);

// move DENTRO de la ventana de entrada → se aplica (entrada ágil).
$session = sessionWithPresence('2026-04-28 10:00:00.000', 'ABSENT');
$decision = SensorEventDecision::decide(
    presenceEvent('2026-04-28T10:00:05Z', 'PRESENT', 'move'), $session, false, true, false
);
if ($decision === SensorEventDecision::APPLY) pass('F48: move dentro de la ventana de entrada → apply');
else fail('F48: move con ventana debería aplicar, got ' . $decision);

// presence en habitación FREE (sin estancia ni ventana) → no_context (contención).
$session = sessionWithPresence('2026-04-28 10:00:00.000', 'ABSENT');
$decision = SensorEventDecision::decide(
    presenceEvent('2026-04-28T10:00:05Z', 'PRESENT', 'presence'), $session, false, false, false
);
if ($decision === SensorEventDecision::NO_CONTEXT) pass('F48: presence sin estancia ni ventana → no_context (contención)');
else fail('F48: presence sin contexto debería ser no_context, got ' . $decision);

// presence con entrada en curso (ventana) → se aplica.
$session = sessionWithPresence('2026-04-28 10:00:00.000', 'ABSENT');
$decision = SensorEventDecision::decide(
    presenceEvent('2026-04-28T10:00:05Z', 'PRESENT', 'presence'), $session, false, true, false
);
if ($decision === SensorEventDecision::APPLY) pass('F48: presence dentro de la ventana de entrada → apply');
else fail('F48: presence con ventana debería aplicar, got ' . $decision);

// presence con huésped dentro confirmado y SIN ciclo de salida → se aplica.
$session = sessionWithPresence('2026-04-28 10:00:00.000', 'ABSENT');
$decision = SensorEventDecision::decide(
    presenceEvent('2026-04-28T10:00:05Z', 'PRESENT', 'presence'), $session, false, false, true
);
if ($decision === SensorEventDecision::APPLY) pass('F48 v2: presence con estancia confirmada y sin ciclo de salida → apply');
else fail('F48 v2: presence dentro (sin ciclo) debería aplicar, got ' . $decision);

// F48 v2: tras un ciclo de salida (sin ventana ni contexto dentro) → no_context.
// Es el caso real del pasillo: la puerta abrió/cerró (salida) y el radar sigue viendo.
$session = sessionWithPresence('2026-04-28 10:00:00.000', 'ABSENT');
$decision = SensorEventDecision::decide(
    presenceEvent('2026-04-28T10:00:05Z', 'PRESENT', 'move'), $session, false, false, false
);
if ($decision === SensorEventDecision::NO_CONTEXT) pass('F48 v2: move tras ciclo de salida → no_context');
else fail('F48 v2: move tras salida debería ser no_context, got ' . $decision);

// ABSENT (`none`) sin contexto → siempre se aplica (poder limpiar estado).
$session = sessionWithPresence('2026-04-28 10:00:00.000', 'PRESENT');
$decision = SensorEventDecision::decide(
    presenceEvent('2026-04-28T10:00:05Z', 'ABSENT', 'none'), $session, false, false, false
);
if ($decision === SensorEventDecision::APPLY) pass('F48: none (ABSENT) sin contexto → apply (limpia estado)');
else fail('F48: ABSENT debería aplicar sin contexto, got ' . $decision);

// Regresión: el camino clásico (defaults true) no cambia.
$session = sessionWithPresence('2026-04-28 10:00:00.000', 'ABSENT');
$decision = SensorEventDecision::decide(
    presenceEvent('2026-04-28T10:00:05Z', 'PRESENT', 'presence'), $session, false
);
if ($decision === SensorEventDecision::APPLY) pass('F48: defaults preservan el comportamiento previo (apply)');
else fail('F48: con defaults debería aplicar, got ' . $decision);

// Inyección SIMULADA sin contexto → se respeta (herramienta dev/tests).
$simEvent = presenceEvent('2026-04-28T10:00:05Z', 'PRESENT', 'presence');
$simEvent['provider'] = 'SIMULATED';
$session = sessionWithPresence('2026-04-28 10:00:00.000', 'ABSENT');
$decision = SensorEventDecision::decide($simEvent, $session, false, false, false);
if ($decision === SensorEventDecision::APPLY) pass('F48: provider SIMULATED sin contexto → apply (bypass dev/tests)');
else fail('F48: SIMULATED debería aplicar, got ' . $decision);

// Helper puro.
if (SensorEventDecision::presenceCredible('move', false, false) === false
    && SensorEventDecision::presenceCredible('move', true, false) === true
    && SensorEventDecision::presenceCredible('presence', false, true) === true) {
    pass('F48: presenceCredible() respeta move/ventana/estancia');
} else {
    fail('F48: presenceCredible() incorrecto');
}

// ── discarded decisions still carry a reason (auditable) ─────────────────
foreach ([
    SensorEventDecision::DUPLICATE,
    SensorEventDecision::STALE,
    SensorEventDecision::NOOP,
    SensorEventDecision::NO_CONTEXT,
] as $reason) {
    $valid = in_array($reason, ['duplicate', 'stale', 'noop', 'no_context'], true);
    if ($valid) pass("audit: discard_reason '{$reason}' is a valid persisted value");
    else fail("audit: invalid discard_reason '{$reason}'");
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Results: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL > 0 ? 1 : 0);
