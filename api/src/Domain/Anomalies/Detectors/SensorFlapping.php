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
 * A8 — Sensor flapping (rapid state oscillation indicating hardware malfunction).
 *
 * Trigger: every sensor event
 * Condition: The same sensor has changed value >= flappingThreshold times
 *            within the last flappingWindowSeconds.
 * Severity: LOW
 *
 * See requirements.md RF-35.A8.
 */
final class SensorFlapping implements AnomalyDetector
{
    private PDO $pdo;
    private int $flappingThreshold;
    private int $flappingWindowSeconds;

    public function __construct(
        PDO  $pdo,
        int  $flappingThreshold = 5,
        int  $flappingWindowSeconds = 30
    ) {
        $this->pdo = $pdo;
        $this->flappingThreshold = $flappingThreshold;
        $this->flappingWindowSeconds = $flappingWindowSeconds;
    }

    public function detect(IotSession $session, PresenceEvent $trigger, ?Stay $stay): ?AnomalyResult
    {
        $windowStart = gmdate('Y-m-d H:i:s', time() - $this->flappingWindowSeconds);

        // Count how many events of this sensor type occurred in the window.
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM presence_events
             WHERE room_id = :rid AND sensor = :sensor AND occurred_at >= :since"
        );
        $stmt->execute([
            ':rid'    => $session->roomId,
            ':sensor' => $trigger->sensor,
            ':since'  => $windowStart,
        ]);
        $count = (int) $stmt->fetchColumn();

        if ($count < $this->flappingThreshold) {
            return null; // Not flapping
        }

        return new AnomalyResult(
            type: Anomaly::TYPE_A8,
            severity: Anomaly::SEVERITY_LOW,
            contextData: [
                'sensor'                 => $trigger->sensor,
                'event_count'            => $count,
                'flapping_threshold'     => $this->flappingThreshold,
                'flapping_window_seconds' => $this->flappingWindowSeconds,
                'latest_value'           => $trigger->value,
            ]
        );
    }
}
