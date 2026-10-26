<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Sensor;

/** Pure retry delay policy used after the MQTT CLI exits. */
final class ZigbeeMqttReconnectBackoff
{
    public static function delayFor(int $consecutiveFailures): int
    {
        $attempt = max(1, $consecutiveFailures);
        return min(30, 2 ** min(5, $attempt - 1));
    }
}
