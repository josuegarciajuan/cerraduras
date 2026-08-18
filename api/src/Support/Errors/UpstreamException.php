<?php
declare(strict_types=1);

namespace App\Support\Errors;

/**
 * UpstreamException: thrown when an external dependency (Tuya IoT, WS-VB6, etc.)
 * cannot fulfill a request due to misconfiguration, missing device registration,
 * or network-level failures.
 *
 * Gateways (TuyaLockGateway, TuyaSwitchGateway) throw this for device-not-found
 * and similar upstream errors. Callers catch it and transform it into a
 * graceful degraded response.
 *
 * The constructor accepts a message and an optional details array (room_id,
 * device_id, etc.) for logging context.
 */
class UpstreamException extends \RuntimeException
{
    /** @var array<string,mixed> */
    private array $details;

    /**
     * @param string $message  Human-readable error description.
     * @param array<string,mixed> $details  Structured context for logging.
     */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message);
        $this->details = $details;
    }

    /** @return array<string,mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }
}
