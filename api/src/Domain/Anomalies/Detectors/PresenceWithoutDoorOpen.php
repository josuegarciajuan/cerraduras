<?php
declare(strict_types=1);

namespace App\Domain\Anomalies\Detectors;

use App\Domain\Anomalies\Anomaly;
use App\Domain\Anomalies\AnomalyDetector;
use App\Domain\Anomalies\AnomalyResult;
use App\Domain\Presence\IotSession;
use App\Domain\Presence\PresenceEvent;
use App\Domain\Stays\Stay;
use PDO;

/**
 * A1 — Presence detected without prior door-open event.
 *
 * Trigger: PRESENCE=PRESENT
 * Condition: No PROXIMITY=OPEN event exists since first_entry_at (or since
 *            the last time the room became FREE if no active stay).
 * Severity: HIGH
 *
 * See requirements.md RF-35.A1.
 */
final class PresenceWithoutDoorOpen implements AnomalyDetector
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function detect(IotSession $session, PresenceEvent $trigger, ?Stay $stay): ?AnomalyResult
    {
        // Only triggered by PRESENCE events
        if ($trigger->sensor !== PresenceEvent::SENSOR_PRESENCE
            || $trigger->value !== PresenceEvent::VALUE_PRESENT
        ) {
            return null;
        }

        // We need an active OCCUPIED stay to have a meaningful baseline.
        if ($stay === null || $stay->status !== Stay::STATUS_OCCUPIED) {
            return null;
        }

        // Determine the cut-off: when was the last time the door SHOULD have been opened?
        // Use first_entry_at as the baseline.
        $since = $stay->firstEntryAt;
        if ($since === null) {
            // No entry recorded yet — this shouldn't happen for OCCUPIED, but guard.
            return null;
        }

        // Query: has there been any PROXIMITY=OPEN event since first_entry_at?
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM presence_events
             WHERE room_id = :rid AND sensor = :sensor AND value = :value
               AND occurred_at >= :since
             LIMIT 1"
        );
        $stmt->execute([
            ':rid'    => $session->roomId,
            ':sensor' => PresenceEvent::SENSOR_PROXIMITY,
            ':value'  => PresenceEvent::VALUE_OPEN,
            ':since'  => $since,
        ]);

        if ($stmt->fetchColumn() !== false) {
            return null; // Normal: door was opened at some point
        }

        return new AnomalyResult(
            type: Anomaly::TYPE_A1,
            severity: Anomaly::SEVERITY_HIGH,
            contextData: [
                'door_state'         => $session->doorState,
                'presence_state'     => $session->presenceState,
                'stay_status'        => $stay->status,
                'first_entry_at'     => $stay->firstEntryAt,
                'last_open_at'       => $session->lastOpenAt,
                'last_close_at'      => $session->lastCloseAt,
            ]
        );
    }
}
