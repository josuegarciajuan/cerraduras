<?php
declare(strict_types=1);

namespace App\Domain\Anomalies\Detectors;

use App\Domain\Anomalies\Anomaly;
use App\Domain\Anomalies\AnomalyDetector;
use App\Domain\Anomalies\AnomalyResult;
use App\Domain\Presence\IotSession;
use App\Domain\Presence\PresenceEvent;
use App\Domain\Stays\Stay;

/**
 * A6 — Presence detected after a stay has already been exited.
 *
 * Trigger: PRESENCE=PRESENT
 * Condition: The most recent stay for this room is in EXITED status and
 *            exit_detected_at is in the past (not null).
 * Severity: HIGH
 *
 * See requirements.md RF-35.A6.
 */
final class PresenceAfterExit implements AnomalyDetector
{
    public function detect(IotSession $session, PresenceEvent $trigger, ?Stay $stay): ?AnomalyResult
    {
        if ($trigger->sensor !== PresenceEvent::SENSOR_PRESENCE
            || $trigger->value !== PresenceEvent::VALUE_PRESENT
        ) {
            return null;
        }

        // We need the stay to be in EXITED status
        if ($stay === null || $stay->status !== Stay::STATUS_EXITED) {
            return null;
        }

        // Must have an actual exit timestamp
        if ($stay->exitDetectedAt === null) {
            return null;
        }

        return new AnomalyResult(
            type: Anomaly::TYPE_A6,
            severity: Anomaly::SEVERITY_HIGH,
            contextData: [
                'door_state'        => $session->doorState,
                'presence_state'    => $session->presenceState,
                'stay_status'       => $stay->status,
                'stay_id'           => $stay->id,
                'exit_detected_at'  => $stay->exitDetectedAt,
                'last_open_at'      => $session->lastOpenAt,
                'last_close_at'     => $session->lastCloseAt,
            ]
        );
    }
}
