<?php
declare(strict_types=1);

namespace App\Domain\Anomalies\Detectors;

use App\Domain\Anomalies\Anomaly;
use App\Domain\Anomalies\AnomalyDetector;
use App\Domain\Anomalies\AnomalyResult;
use App\Domain\Presence\IotSession;
use App\Domain\Presence\PresenceEvent;
use App\Domain\Stays\Stay;
use App\Support\Clock;

/**
 * A7 — Door open without sustained presence (room unattended and open).
 *
 * Trigger: periodic evaluation (also evaluates on each event for early detection)
 * Condition: door_state = OPEN AND presence_state = ABSENT AND door has been
 *            open for > maxOpenAbsentSeconds (default 120s).
 * Severity: MEDIUM
 *
 * See requirements.md RF-35.A7.
 */
final class DoorOpenWithoutPresence implements AnomalyDetector
{
    private int $maxOpenAbsentSeconds;

    public function __construct(int $maxOpenAbsentSeconds = 120)
    {
        $this->maxOpenAbsentSeconds = $maxOpenAbsentSeconds;
    }

    public function detect(IotSession $session, PresenceEvent $trigger, ?Stay $stay): ?AnomalyResult
    {
        // Condition: door must be OPEN
        if ($session->doorState !== IotSession::DOOR_OPEN) {
            return null;
        }

        // Condition: presence must be ABSENT
        if ($session->presenceState !== IotSession::PRESENCE_ABSENT) {
            return null;
        }

        // Condition: door has been open long enough
        if ($session->lastOpenAt === null) {
            return null;
        }

        $openTs = strtotime($session->lastOpenAt . ' UTC');
        $nowTs  = Clock::nowUtc()->getTimestamp();

        if ($openTs === false || ($nowTs - $openTs) < $this->maxOpenAbsentSeconds) {
            return null; // Not open long enough yet
        }

        return new AnomalyResult(
            type: Anomaly::TYPE_A7,
            severity: Anomaly::SEVERITY_MEDIUM,
            contextData: [
                'door_state'             => $session->doorState,
                'presence_state'         => $session->presenceState,
                'last_open_at'           => $session->lastOpenAt,
                'open_duration_seconds'  => $nowTs - $openTs,
                'threshold_seconds'      => $this->maxOpenAbsentSeconds,
                'stay_id'                => $stay?->id,
                'stay_status'            => $stay?->status,
            ]
        );
    }
}
