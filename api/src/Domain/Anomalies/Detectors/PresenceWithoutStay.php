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
 * A2 — Presence detected in a room without an active stay.
 *
 * Trigger: PRESENCE=PRESENT
 * Condition: Room has no stay in OCCUPIED or EXITED status (room is effectively FREE).
 * Severity: HIGH
 *
 * See requirements.md RF-35.A2.
 */
final class PresenceWithoutStay implements AnomalyDetector
{
    public function detect(IotSession $session, PresenceEvent $trigger, ?Stay $stay): ?AnomalyResult
    {
        if ($trigger->sensor !== PresenceEvent::SENSOR_PRESENCE
            || $trigger->value !== PresenceEvent::VALUE_PRESENT
        ) {
            return null;
        }

        // If there's an active/occupied stay, this is normal.
        if ($stay !== null && in_array($stay->status, [Stay::STATUS_OCCUPIED, Stay::STATUS_EXITED], true)) {
            return null;
        }

        return new AnomalyResult(
            type: Anomaly::TYPE_A2,
            severity: Anomaly::SEVERITY_HIGH,
            contextData: [
                'door_state'       => $session->doorState,
                'presence_state'   => $session->presenceState,
                'stay_id'          => $stay?->id,
                'stay_status'      => $stay?->status,
                'last_open_at'     => $session->lastOpenAt,
                'last_close_at'    => $session->lastCloseAt,
            ]
        );
    }
}
