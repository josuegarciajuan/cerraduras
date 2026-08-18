<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Sensor;

/**
 * SensorIngressInterface: normalizes incoming sensor payloads to the format
 * expected by the domain layer (presence_events table / PresenceService).
 *
 * See design.md §2.4, RF-5, TSK-082.
 *
 * Implementations:
 *   SimulatedSensorIngress — for dev / MVP testing (no Tuya required).
 *   TuyaSensorIngress      — real Tuya IoT adapter (F19).
 *
 * The interface is deliberately thin: the domain service decides what to do
 * with the normalized event. The ingress only handles transport/format
 * differences.
 */
interface SensorIngressInterface
{
    /**
     * Normalize a raw sensor payload into a canonical presence event array.
     *
     * @param array<string,mixed> $raw  The incoming payload (from HTTP body or
     *                                  Tuya webhook, depending on implementation).
     *
     * @return array{
     *   room_id: int,
     *   sensor: string,
     *   value: string,
     *   provider: string,
     *   occurred_at: string,
     *   source_event_id: string|null,
     *   meta: array<string,mixed>|null
     * }
     */
    public function normalize(array $raw): array;

    /**
     * Return the provider string stored in presence_events.provider.
     */
    public function getProvider(): string;
}
