<?php
declare(strict_types=1);

namespace App\Domain\Anomalies\Detectors;

use App\Domain\Anomalies\Anomaly;
use App\Domain\Anomalies\AnomalyResult;
use App\Domain\Presence\IotSession;
use App\Domain\Stays\Stay;

/**
 * A5 — Stay transitioned to EXITED without a recent door-open event.
 *
 * This is NOT a standard AnomalyDetector (no sensor event trigger).
 * It is called directly from ExitActionService after the exit has been executed.
 *
 * Condition: last_open_at is null OR is older than 2 hours relative to
 *            the exit_detected_at timestamp, indicating the door sensor
 *            may have failed to detect the guest leaving through the door.
 * Severity: MEDIUM
 *
 * See requirements.md RF-35.A5.
 */
final class ExitWithoutDoorOpen
{
    private int $maxOpenAgeHours;

    public function __construct(int $maxOpenAgeHours = 2)
    {
        $this->maxOpenAgeHours = $maxOpenAgeHours;
    }

    /**
     * Check if the exit occurred without a recent door-open event.
     *
     * @param string $exitDetectedAt  MySQL DATETIME(3) when exit was detected
     * @param string|null $lastOpenAt MySQL DATETIME(3) of last PROXIMITY=OPEN, or null
     * @param string|null $firstEntryAt MySQL DATETIME(3) of first entry
     */
    public function check(
        IotSession $session,
        Stay       $stay,
        string     $exitDetectedAt,
        ?string    $lastOpenAt,
        ?string    $firstEntryAt
    ): ?AnomalyResult {
        // If exitDetectedAt is not set, nothing to compare.
        if ($exitDetectedAt === '') {
            return null;
        }

        $exitTs = strtotime($exitDetectedAt . ' UTC');
        if ($exitTs === false) {
            return null;
        }

        // Condition: last_open_at is null (door never opened) or too old.
        if ($lastOpenAt === null) {
            return $this->buildResult($session, $stay, $exitDetectedAt, null, $firstEntryAt);
        }

        $openTs = strtotime($lastOpenAt . ' UTC');
        if ($openTs === false) {
            return null;
        }

        $maxAgeSeconds = $this->maxOpenAgeHours * 3600;
        if (($exitTs - $openTs) <= $maxAgeSeconds) {
            return null; // Door was opened recently — normal exit
        }

        return $this->buildResult($session, $stay, $exitDetectedAt, $lastOpenAt, $firstEntryAt);
    }

    private function buildResult(
        IotSession $session,
        Stay       $stay,
        string     $exitDetectedAt,
        ?string    $lastOpenAt,
        ?string    $firstEntryAt
    ): AnomalyResult {
        return new AnomalyResult(
            type: Anomaly::TYPE_A5,
            severity: Anomaly::SEVERITY_MEDIUM,
            contextData: [
                'door_state'         => $session->doorState,
                'presence_state'     => $session->presenceState,
                'stay_status'        => $stay->status,
                'exit_detected_at'   => $exitDetectedAt,
                'last_open_at'       => $lastOpenAt,
                'first_entry_at'     => $firstEntryAt,
                'last_close_at'      => $session->lastCloseAt,
                'max_open_age_hours' => $this->maxOpenAgeHours,
            ]
        );
    }
}
