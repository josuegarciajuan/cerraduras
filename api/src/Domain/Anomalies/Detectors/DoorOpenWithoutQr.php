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
 * A3 — Door opened without a valid QR scan and without an active stay.
 *
 * Trigger: PROXIMITY=OPEN
 * Condition: No OCCUPIED stay AND no QR_VALIDATE access_event in the last N seconds.
 * Excludes administrative LOCK_OPEN commands (handled separately).
 * Severity: CRITICAL
 *
 * See requirements.md RF-35.A3.
 */
final class DoorOpenWithoutQr implements AnomalyDetector
{
    private PDO $pdo;
    private int $windowSeconds;

    public function __construct(PDO $pdo, int $windowSeconds = 30)
    {
        $this->pdo = $pdo;
        $this->windowSeconds = $windowSeconds;
    }

    public function detect(IotSession $session, PresenceEvent $trigger, ?Stay $stay): ?AnomalyResult
    {
        if ($trigger->sensor !== PresenceEvent::SENSOR_PROXIMITY
            || $trigger->value !== PresenceEvent::VALUE_OPEN
        ) {
            return null;
        }

        // If there's an OCCUPIED stay, the door opening is normal (guest entering).
        if ($stay !== null && $stay->status === Stay::STATUS_OCCUPIED) {
            return null;
        }

        // Check for a recent QR_VALIDATE access event.
        $windowStart = gmdate('Y-m-d H:i:s', time() - $this->windowSeconds);
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM access_events
             WHERE room_id = :rid AND kind = :kind AND occurred_at >= :since
             LIMIT 1"
        );
        $stmt->execute([
            ':rid'   => $session->roomId,
            ':kind'  => 'QR_VALIDATE',
            ':since' => $windowStart,
        ]);

        if ($stmt->fetchColumn() !== false) {
            return null; // QR was scanned recently — normal
        }

        return new AnomalyResult(
            type: Anomaly::TYPE_A3,
            severity: Anomaly::SEVERITY_CRITICAL,
            contextData: [
                'door_state'        => $session->doorState,
                'presence_state'    => $session->presenceState,
                'stay_id'           => $stay?->id,
                'stay_status'       => $stay?->status,
                'last_open_at'      => $session->lastOpenAt,
                'qr_window_seconds' => $this->windowSeconds,
            ]
        );
    }
}
