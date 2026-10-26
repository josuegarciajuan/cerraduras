<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Sensor;

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use App\Domain\Presence\PresenceEvent;
use App\Domain\Presence\IotSessionService;
use InvalidArgumentException;

/** Resolves configured Zigbee identities and delegates state changes to the domain writer. */
final class ZigbeeMqttEventProcessor
{
    public function __construct(
        private DeviceRepositoryInterface $devices,
        private IotSessionService $iotSessions
    ) {
    }

    /**
     * @param array<string,mixed> $mappedEvent Output from ZigbeeMqttMessageMapper.
     * @return array{status:string,reason?:string,result?:array<string,mixed>}
     */
    public function process(array $mappedEvent, string $correlationId): array
    {
        if (($mappedEvent['provider'] ?? null) !== PresenceEvent::PROVIDER_ZIGBEE2MQTT) {
            throw new InvalidArgumentException('Unexpected event provider');
        }

        $externalId = $mappedEvent['device_external_id'] ?? null;
        $sensor = $mappedEvent['sensor'] ?? null;
        if (!is_string($externalId) || !is_string($sensor)) {
            throw new InvalidArgumentException('Mapped event is missing device identity or sensor');
        }

        $device = $this->devices->findByExternalId($externalId);
        if ($device === null) {
            return ['status' => 'discarded', 'reason' => 'unknown_device'];
        }
        $expectedKind = $sensor === 'PROXIMITY' ? Device::KIND_PROXIMITY
            : ($sensor === 'PRESENCE' ? Device::KIND_PRESENCE : null);
        if ($expectedKind === null || $device->kind !== $expectedKind) {
            return ['status' => 'discarded', 'reason' => 'device_kind_mismatch'];
        }
        if ($device->packId === null) {
            return ['status' => 'discarded', 'reason' => 'device_without_pack'];
        }

        $roomId = $this->devices->resolveRoomId($device->id);
        if ($roomId === null || $roomId <= 0) {
            return ['status' => 'discarded', 'reason' => 'room_not_found'];
        }

        $event = [
            'room_id' => $roomId,
            'sensor' => $sensor,
            'value' => (string) ($mappedEvent['value'] ?? ''),
            'provider' => PresenceEvent::PROVIDER_ZIGBEE2MQTT,
            'occurred_at' => (string) ($mappedEvent['occurred_at'] ?? ''),
            'source_event_id' => (string) ($mappedEvent['source_event_id'] ?? ''),
            'meta' => is_array($mappedEvent['meta'] ?? null) ? $mappedEvent['meta'] : [],
        ];

        $result = $this->iotSessions->processEvent($event, $correlationId);
        return ['status' => 'processed', 'result' => $result];
    }
}
