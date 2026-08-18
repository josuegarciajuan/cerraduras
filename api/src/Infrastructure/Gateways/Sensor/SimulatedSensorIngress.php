<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Sensor;

use App\Support\Clock;
use App\Support\Errors\BadRequestException;

/**
 * SimulatedSensorIngress: pass-through implementation for dev and testing.
 *
 * Accepts payloads in the canonical format already (same as the
 * POST /presence/events body from contracts.md §2.6) and validates the
 * required fields. Used by both the real /presence/events endpoint (when
 * SIMULATED_MODE=true) and the /sim/* endpoints.
 *
 * See design.md §10, RF-11, TSK-082.
 */
final class SimulatedSensorIngress implements SensorIngressInterface
{
    private const PROVIDER = 'SIMULATED';

    private const VALID_SENSORS = ['PROXIMITY', 'PRESENCE'];
    private const VALID_VALUES  = ['OPEN', 'CLOSED', 'PRESENT', 'ABSENT'];

    public function normalize(array $raw): array
    {
        // room_id
        if (empty($raw['room_id']) || !is_numeric($raw['room_id'])) {
            throw new BadRequestException('Missing or invalid field: room_id');
        }
        $roomId = (int) $raw['room_id'];

        // sensor
        $sensor = strtoupper((string) ($raw['sensor'] ?? ''));
        if (!in_array($sensor, self::VALID_SENSORS, true)) {
            throw new BadRequestException(
                'Invalid sensor value',
                ['allowed' => self::VALID_SENSORS, 'given' => $sensor]
            );
        }

        // value
        $value = strtoupper((string) ($raw['value'] ?? ''));
        if (!in_array($value, self::VALID_VALUES, true)) {
            throw new BadRequestException(
                'Invalid sensor value',
                ['allowed' => self::VALID_VALUES, 'given' => $value]
            );
        }

        // occurred_at: use provided or default to now (UTC ISO-8601)
        $occurredAt = isset($raw['occurred_at']) && is_string($raw['occurred_at'])
            ? $raw['occurred_at']
            : Clock::nowUtc()->format('Y-m-d\TH:i:s\Z');

        // source_event_id: optional
        $sourceEventId = isset($raw['source_event_id']) && is_string($raw['source_event_id'])
            ? $raw['source_event_id']
            : null;

        // meta: optional associative array
        $meta = isset($raw['meta']) && is_array($raw['meta']) ? $raw['meta'] : null;

        return [
            'room_id'         => $roomId,
            'sensor'          => $sensor,
            'value'           => $value,
            'provider'        => self::PROVIDER,
            'occurred_at'     => $occurredAt,
            'source_event_id' => $sourceEventId,
            'meta'            => $meta,
        ];
    }

    public function getProvider(): string
    {
        return self::PROVIDER;
    }
}
