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
use PDO;

/**
 * A4 — Presence persistent without any door activity for a prolonged period.
 *
 * Trigger: periodic evaluation (also reacts to PRESENCE events for early detection)
 * Condition: presence_state = PRESENT continuously for > (duracion_minutos + margin_hours),
 *            with zero PROXIMITY events in that interval.
 * Severity: HIGH
 *
 * See requirements.md RF-35.A4.
 */
final class PresenceWithoutDoorActivity implements AnomalyDetector
{
    private PDO $pdo;
    private int $marginHours;

    public function __construct(PDO $pdo, int $marginHours = 2)
    {
        $this->pdo = $pdo;
        $this->marginHours = $marginHours;
    }

    public function detect(IotSession $session, PresenceEvent $trigger, ?Stay $stay): ?AnomalyResult
    {
        // Only relevant when presence is detected
        if ($session->presenceState !== IotSession::PRESENCE_PRESENT) {
            return null;
        }

        // Need an OCCUPIED stay to know the expected duration
        if ($stay === null || $stay->status !== Stay::STATUS_OCCUPIED) {
            return null;
        }

        if ($stay->firstEntryAt === null) {
            return null;
        }

        // Calculate minimum period before we consider this anomalous:
        // duracion_minutos + margin_hours (converted to seconds)
        $thresholdSeconds = ($stay->duracionMinutos * 60) + ($this->marginHours * 3600);
        $firstEntryTs = strtotime($stay->firstEntryAt . ' UTC');
        $nowTs = Clock::nowUtc()->getTimestamp();

        if ($firstEntryTs === false || ($nowTs - $firstEntryTs) < $thresholdSeconds) {
            return null; // Not long enough yet
        }

        // Check: has there been ANY PROXIMITY event since first_entry_at?
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM presence_events
             WHERE room_id = :rid AND sensor = :sensor AND occurred_at >= :since
             LIMIT 1"
        );
        $stmt->execute([
            ':rid'    => $session->roomId,
            ':sensor' => PresenceEvent::SENSOR_PROXIMITY,
            ':since'  => $stay->firstEntryAt,
        ]);

        if ($stmt->fetchColumn() !== false) {
            return null; // There has been door activity — normal
        }

        return new AnomalyResult(
            type: Anomaly::TYPE_A4,
            severity: Anomaly::SEVERITY_HIGH,
            contextData: [
                'door_state'            => $session->doorState,
                'presence_state'        => $session->presenceState,
                'stay_status'           => $stay->status,
                'duracion_minutos'      => $stay->duracionMinutos,
                'first_entry_at'        => $stay->firstEntryAt,
                'hours_since_entry'     => round(($nowTs - $firstEntryTs) / 3600, 1),
                'margin_hours'          => $this->marginHours,
                'last_open_at'          => $session->lastOpenAt,
                'last_close_at'         => $session->lastCloseAt,
            ]
        );
    }
}
