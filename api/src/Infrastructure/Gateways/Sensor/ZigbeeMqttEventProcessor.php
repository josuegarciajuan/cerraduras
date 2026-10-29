<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Sensor;

use App\Domain\Devices\Device;
use App\Domain\Devices\DeviceRepositoryInterface;
use App\Domain\Presence\DoorPolarity;
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
     * Persist a Zigbee2MQTT availability message as connectivity evidence only
     * (F92/RF-153.3). It never touches presence, door state or visits.
     *
     * @param array<string,mixed> $mapped Output from ZigbeeMqttMessageMapper.
     * @return array{status:string,reason?:string,state?:string}
     */
    public function applyAvailability(array $mapped): array
    {
        $diagnostic = is_array($mapped['diagnostic'] ?? null) ? $mapped['diagnostic'] : null;
        if ($diagnostic === null || ($diagnostic['kind'] ?? null) !== 'availability') {
            return ['status' => 'ignored', 'reason' => 'not_availability'];
        }
        $state = strtolower(trim((string) ($diagnostic['state'] ?? '')));
        if (!in_array($state, ['online', 'offline'], true)) {
            return ['status' => 'discarded', 'reason' => 'invalid_availability'];
        }
        $externalId = (string) ($diagnostic['device_external_id'] ?? '');
        if ($externalId === '') {
            return ['status' => 'discarded', 'reason' => 'unknown_device'];
        }
        $device = $this->devices->findByExternalId($externalId);
        if ($device === null) {
            return ['status' => 'discarded', 'reason' => 'unknown_device'];
        }
        if ($device->transport !== Device::TRANSPORT_ZIGBEE2MQTT) {
            return ['status' => 'discarded', 'reason' => 'wrong_transport'];
        }
        $this->devices->updateOnlineState($device->id, $state === 'online');
        return ['status' => 'processed', 'state' => $state];
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
        $isBootstrap = (($meta['bootstrap'] ?? false) === true);

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

        // F95/RF-162: normaliza la polaridad del contacto POR DISPOSITIVO antes
        // de tocar el dominio. La auditoría conserva el valor canónico previo y
        // la polaridad aplicada; el valor crudo ya está en meta.zigbee_value.
        if ($sensor === 'PROXIMITY') {
            $rawValue = (string) ($mappedEvent['value'] ?? '');
            $meta = is_array($mappedEvent['meta'] ?? null) ? $mappedEvent['meta'] : [];
            $meta['canonical_before_polarity'] = $rawValue;
            $meta['door_polarity'] = DoorPolarity::value($device->meta);
            $mappedEvent['meta'] = $meta;
            $mappedEvent['value'] = DoorPolarity::canonical($device->meta, $rawValue);
        }

        // A retained/bootstrap message seeds state but is not a live transition.
        if ($isBootstrap && $sensor === 'PRESENCE') {
            return ['status' => 'discarded', 'reason' => 'bootstrap'];
        }

        if ($sensor === 'SWITCH') {
            $state = $this->switchStates->apply(
                $device,
                (string) ($mappedEvent['value'] ?? ''),
                (string) ($mappedEvent['occurred_at'] ?? ''),
                !$isBootstrap
            );
            return ['status' => $isBootstrap ? 'discarded' : 'processed', 'reason' => $isBootstrap ? 'bootstrap' : null, 'result' => ['switch' => $state]];
        }

        if ($device->packId === null) {
            return ['status' => 'discarded', 'reason' => 'device_without_pack'];
        }

        $roomId = $this->devices->resolveRoomId($device->id);
        if ($roomId === null || $roomId <= 0) {
            return ['status' => 'discarded', 'reason' => 'room_not_found'];
        }

        if ($isBootstrap && $sensor === 'PROXIMITY') {
            $result = $this->iotSessions->applyDoorBootstrap([
                'room_id' => $roomId,
                'sensor' => $sensor,
                'value' => (string) ($mappedEvent['value'] ?? ''),
                'provider' => PresenceEvent::PROVIDER_ZIGBEE2MQTT,
                'occurred_at' => (string) ($mappedEvent['occurred_at'] ?? ''),
                'source_event_id' => (string) ($mappedEvent['source_event_id'] ?? ''),
                'meta' => $meta,
            ], $correlationId);
            return ['status' => 'discarded', 'reason' => 'bootstrap', 'result' => $result];
        }

        // A real push proves the device is alive, regardless of the later
        // domain decision (duplicate/stale still means it reported).
        $this->devices->updateLastSeen($device->id);

        // F97.3: identidad del sensor en el hecho auditado. Permite que la
        // proyección física quede ligada al dispositivo correcto.
        $meta['device_external_id'] = $externalId;

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
