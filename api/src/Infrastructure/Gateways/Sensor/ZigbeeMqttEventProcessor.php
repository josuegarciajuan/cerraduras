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
        private IotSessionService $iotSessions,
        private ?ZigbeeSwitchStateService $switchStates = null
    ) {
        $this->switchStates ??= new ZigbeeSwitchStateService($devices);
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

        $meta = is_array($mappedEvent['meta'] ?? null) ? $mappedEvent['meta'] : [];
        if (($meta['bootstrap'] ?? false) === true) {
            return ['status' => 'discarded', 'reason' => 'bootstrap'];
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
        $expectedKind = match ($sensor) {
            'PROXIMITY' => Device::KIND_PROXIMITY,
            'PRESENCE' => Device::KIND_PRESENCE,
            'SWITCH' => Device::KIND_SWITCH,
            default => null,
        };
        if ($expectedKind === null || $device->kind !== $expectedKind) {
            return ['status' => 'discarded', 'reason' => 'device_kind_mismatch'];
        }

        // A real push proves the device is alive, regardless of the later
        // domain decision (duplicate/stale still means it reported).
        $this->devices->updateLastSeen($device->id);

        if ($sensor === 'SWITCH') {
            $state = $this->switchStates->apply(
                $device,
                (string) ($mappedEvent['value'] ?? ''),
                (string) ($mappedEvent['occurred_at'] ?? '')
            );
            return ['status' => 'processed', 'result' => ['switch' => $state]];
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
            'meta' => $meta,
        ];

        $result = $this->iotSessions->processEvent($event, $correlationId);
        return ['status' => 'processed', 'result' => $result];
    }
}
