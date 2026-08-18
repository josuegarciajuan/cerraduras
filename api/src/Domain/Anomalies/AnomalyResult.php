<?php
declare(strict_types=1);

namespace App\Domain\Anomalies;

/**
 * AnomalyResult: DTO returned by a detector when an anomaly condition is met.
 *
 * Pure data — no side-effects, no I/O.
 */
final class AnomalyResult
{
    /**
     * @param string            $type        Anomaly catalog code (A1..A8)
     * @param string            $severity    LOW|MEDIUM|HIGH|CRITICAL
     * @param array<string,mixed> $contextData IoT/sensor snapshot at detection time
     */
    public function __construct(
        public readonly string $type,
        public readonly string $severity,
        public readonly array  $contextData,
    ) {}
}
