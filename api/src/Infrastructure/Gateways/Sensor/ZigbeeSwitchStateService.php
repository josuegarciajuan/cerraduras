<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Sensor;

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use InvalidArgumentException;

/**
 * Persists the confirmed state of a Zigbee SWITCH (F91/RF-147).
 *
 * Only an actual state report reaches this service; a command PUBACK is not a
 * physical confirmation. The merge keeps any existing meta (commands, model).
 */
final class ZigbeeSwitchStateService
{
    public function __construct(private DeviceRepositoryInterface $devices)
    {
    }

    /**
     * @return array{state:string,state_at:string}
     */
    public function apply(Device $device, string $state, string $occurredAt): array
    {
        if (!in_array($state, ['ON', 'OFF'], true)) {
            throw new InvalidArgumentException('Invalid SWITCH state');
        }

        $meta = is_array($device->meta) ? $device->meta : [];

        // A real report proves liveness even when it repeats the same value.
        $this->devices->updateLastSeen($device->id);

        // Z2M emits 2–3 state reports per command. Once the confirmed state is
        // already the same, repeated reports must not rewrite meta (dedupe).
        if (($meta['switch_state'] ?? null) === $state) {
            return [
                'state' => $state,
                'state_at' => (string) ($meta['switch_state_at'] ?? $occurredAt),
                'duplicate' => true,
            ];
        }

        $meta['switch_state'] = $state;
        $meta['switch_state_at'] = $occurredAt;
        $meta['switch_state_source'] = 'ZIGBEE2MQTT';

        $this->devices->update($device->id, [
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return ['state' => $state, 'state_at' => $occurredAt, 'duplicate' => false];
    }
}
