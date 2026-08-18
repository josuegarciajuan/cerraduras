<?php
declare(strict_types=1);

namespace App\Domain\Anomalies;

use App\Domain\Presence\IotSession;
use App\Domain\Presence\PresenceEvent;
use App\Domain\Stays\Stay;

/**
 * AnomalyPipeline: evaluates all registered detectors against a sensor event.
 *
 * Non-blocking: if any individual detector throws, the error is logged and
 * the pipeline continues processing the remaining detectors.
 *
 * See design.md §2.2 (F35).
 */
final class AnomalyPipeline
{
    /** @var list<AnomalyDetector> */
    private array $detectors;

    /**
     * @param list<AnomalyDetector> $detectors
     */
    public function __construct(array $detectors)
    {
        $this->detectors = $detectors;
    }

    /**
     * Evaluate all detectors and return any positive results.
     *
     * @return list<AnomalyResult> detected anomalies (empty if none)
     */
    public function evaluate(IotSession $session, PresenceEvent $trigger, ?Stay $stay): array
    {
        $results = [];
        foreach ($this->detectors as $detector) {
            try {
                $result = $detector->detect($session, $trigger, $stay);
                if ($result !== null) {
                    $results[] = $result;
                }
            } catch (\Throwable $e) {
                // Non-blocking: log and continue
                $detectorClass = get_class($detector);
                error_log("[AnomalyPipeline] Detector {$detectorClass} failed: {$e->getMessage()}");
            }
        }
        return $results;
    }
}
