<?php
declare(strict_types=1);

namespace App\Domain\Anomalies;

use App\Domain\Presence\IotSession;
use App\Domain\Presence\PresenceEvent;
use App\Domain\Stays\Stay;

/**
 * AnomalyDetector: evaluates whether a specific anomaly condition is met
 * given the current IoT session state, the triggering sensor event, and
 * the active stay (if any).
 *
 * Each detector is stateless and implements one anomaly type (A1..A8).
 * Return null when no anomaly is detected.
 */
interface AnomalyDetector
{
    /**
     * @return AnomalyResult|null null when the condition is not met
     */
    public function detect(
        IotSession    $session,
        PresenceEvent $trigger,
        ?Stay         $stay
    ): ?AnomalyResult;
}
