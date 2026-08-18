<?php
declare(strict_types=1);

namespace App\Support\Resilience;

/**
 * CircuitBreaker: lightweight in-memory circuit breaker for external API calls.
 *
 * States:
 *   CLOSED    → normal operation; failures are counted.
 *   OPEN      → circuit is open; all calls are rejected immediately.
 *   HALF_OPEN → after the open timeout, the next call is allowed as a probe.
 *               If it succeeds → CLOSED. If it fails → OPEN again.
 *
 * Usage:
 *   $breaker = new CircuitBreaker('tuya-api', 5, 60);
 *   if (!$breaker->isAvailable()) { return degradedResponse(); }
 *   try {
 *       $result = callExternalApi();
 *       $breaker->reportSuccess();
 *   } catch (\Throwable $e) {
 *       $breaker->reportFailure();
 *   }
 *
 * All state is stored in a static array so multiple gateways (Lock, Switch)
 * can share a single breaker for the same backend API.
 */
final class CircuitBreaker
{
    /** @var array<string, array{name:string, failures:int, openedAt:float, threshold:int, openSec:int}> */
    private static array $states = [];

    private string $name;
    private int $failureThreshold;
    private int $openSeconds;

    /**
     * @param string $name            Unique name for this circuit (e.g. 'tuya-api').
     * @param int    $failureThreshold Number of failures within the window to open the circuit.
     * @param int    $openSeconds      Seconds to stay OPEN before transitioning to HALF_OPEN.
     */
    public function __construct(string $name, int $failureThreshold = 5, int $openSeconds = 60)
    {
        $this->name             = $name;
        $this->failureThreshold = max(1, $failureThreshold);
        $this->openSeconds      = max(1, $openSeconds);

        if (!isset(self::$states[$name])) {
            self::$states[$name] = [
                'name'      => $name,
                'failures'  => 0,
                'openedAt'  => 0.0,
                'threshold' => $this->failureThreshold,
                'openSec'   => $this->openSeconds,
            ];
        }
    }

    /**
     * Returns true if the circuit allows calls to proceed.
     *
     * - CLOSED: always true.
     * - OPEN: false until the open timeout expires, then transitions to HALF_OPEN and returns true.
     * - HALF_OPEN: true (the probe call is allowed).
     */
    public function isAvailable(): bool
    {
        $state = &self::$states[$this->name];

        if ($state['failures'] >= $state['threshold']) {
            // Circuit is OPEN — check if timeout has expired
            if ($state['openedAt'] > 0.0) {
                $elapsed = microtime(true) - $state['openedAt'];
                if ($elapsed < $state['openSec']) {
                    return false; // still OPEN
                }
                // Timeout expired → transition to HALF_OPEN (allow one probe)
                $state['openedAt'] = 0.0;
            }
        }

        return true;
    }

    /**
     * Report a successful call. Resets the failure counter.
     */
    public function reportSuccess(): void
    {
        $state = &self::$states[$this->name];
        $state['failures'] = 0;
        $state['openedAt'] = 0.0;
    }

    /**
     * Report a failed call. If the failure threshold is reached, opens the circuit.
     */
    public function reportFailure(): void
    {
        $state = &self::$states[$this->name];
        $state['failures']++;

        if ($state['failures'] >= $state['threshold'] && $state['openedAt'] <= 0.0) {
            $state['openedAt'] = microtime(true);
        }
    }

    /**
     * Return the current state name for monitoring.
     *
     * @return string 'CLOSED', 'OPEN', or 'HALF_OPEN'
     */
    public function getState(): string
    {
        $state = self::$states[$this->name];

        if ($state['failures'] >= $state['threshold']) {
            if ($state['openedAt'] > 0.0) {
                $elapsed = microtime(true) - $state['openedAt'];
                if ($elapsed < $state['openSec']) {
                    return 'OPEN';
                }
            }
            return 'HALF_OPEN';
        }

        return 'CLOSED';
    }

    /** @return array<string,mixed> State for monitoring/debugging. */
    public function getInfo(): array
    {
        $state = self::$states[$this->name];
        return [
            'name'       => $state['name'],
            'state'      => $this->getState(),
            'failures'   => $state['failures'],
            'threshold'  => $state['threshold'],
            'opened_at'  => $state['openedAt'] > 0.0
                ? gmdate('Y-m-d\TH:i:s\Z', (int) $state['openedAt'])
                : null,
        ];
    }
}
