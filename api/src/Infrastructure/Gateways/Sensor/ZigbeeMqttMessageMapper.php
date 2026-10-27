<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Sensor;

use App\Domain\Presence\PresenceEvent;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

/**
 * Maps configured Zigbee2MQTT standard entities to canonical room sensor events.
 *
 * This class is transport- and database-independent. It intentionally contains
 * no model-specific assumptions: each device explicitly maps accepted values.
 */
final class ZigbeeMqttMessageMapper
{
    public const MAX_PAYLOAD_BYTES = 65536;
    private const ENTITIES = ['contact', 'occupancy', 'presence', 'state'];
    private const PROVIDER = PresenceEvent::PROVIDER_ZIGBEE2MQTT;

    private int $version;

    /** @var array<string,array{external_id:string,entities:array<string,array{sensor:string,values:array<string,string>}>}> */
    private array $devices;

    public function __construct(array $config, private string $topicPrefix = 'zigbee2mqtt')
    {
        if (!$this->isValidTopic($topicPrefix)) {
            throw new InvalidArgumentException('Invalid MQTT topic prefix');
        }
        $version = $config['version'] ?? 1;
        if (!is_int($version) || !in_array($version, [1, 2], true)) {
            throw new InvalidArgumentException('Unsupported device map version');
        }
        $this->version = $version;
        if (!isset($config['devices']) || !is_array($config['devices']) || $config['devices'] === []) {
            throw new InvalidArgumentException('Device map must contain at least one device');
        }

        $this->devices = [];
        foreach ($config['devices'] as $topic => $deviceConfig) {
            if (!is_string($topic) || !$this->isValidTopic($topic)
                || !str_starts_with($topic, $topicPrefix . '/')
                || !is_array($deviceConfig)
            ) {
                throw new InvalidArgumentException('Invalid device topic mapping');
            }

            $externalId = $deviceConfig['external_id'] ?? null;
            $entities = $deviceConfig['entities'] ?? null;
            if (!is_string($externalId) || trim($externalId) === '' || strlen($externalId) > 128
                || !is_array($entities)
            ) {
                throw new InvalidArgumentException('Device mapping requires external_id and entities');
            }

            if ($this->version === 2 && str_ends_with(strtolower($topic), '-oficina')) {
                throw new InvalidArgumentException('Production map cannot use *-oficina topics');
            }

            $normalizedEntities = [];
            foreach ($entities as $entity => $mapping) {
                if (!is_string($entity) || !in_array($entity, self::ENTITIES, true)
                    || !is_array($mapping)
                ) {
                    throw new InvalidArgumentException('Unsupported Zigbee2MQTT entity mapping');
                }

                $sensor = $mapping['sensor'] ?? null;
                $values = $mapping['values'] ?? null;
                $allowedValues = self::allowedValuesForSensor($sensor);
                if ($allowedValues === [] || !is_array($values) || $values === []) {
                    throw new InvalidArgumentException('Invalid canonical sensor mapping');
                }
                if ($this->version === 2) {
                    $expectedSensor = match ($entity) {
                        'contact' => 'PROXIMITY',
                        'occupancy', 'presence' => 'PRESENCE',
                        'state' => 'SWITCH',
                        default => null,
                    };
                    if ($sensor !== $expectedSensor) {
                        throw new InvalidArgumentException('Entity/sensor mismatch in v2 map');
                    }
                }

                $normalizedValues = [];
                foreach ($values as $inputValue => $canonicalValue) {
                    if ((!is_string($inputValue) && !is_int($inputValue))
                        || !is_string($canonicalValue)
                        || !in_array($canonicalValue, $allowedValues, true)
                    ) {
                        throw new InvalidArgumentException('Invalid entity value mapping');
                    }
                    $key = strtolower(trim((string) $inputValue));
                    if ($key === '' || isset($normalizedValues[$key])) {
                        throw new InvalidArgumentException('Duplicate or empty entity value mapping');
                    }
                    $normalizedValues[$key] = $canonicalValue;
                }

                $normalizedEntities[$entity] = [
                    'sensor' => $sensor,
                    'values' => $normalizedValues,
                ];
            }

            $this->devices[$topic] = [
                'external_id' => trim($externalId),
                'entities' => $normalizedEntities,
            ];
        }
    }

    /**
     * @return array{
     *   events:list<array{device_external_id:string,sensor:string,value:string,provider:string,occurred_at:string,source_event_id:string,meta:array<string,mixed>}>,
     *   diagnostic:array{kind:string,state:string,device_external_id?:string}|null,
     *   discard_reason:string|null
     * }
     */
    public function mapMessage(string $topic, string $payload, ?DateTimeInterface $receivedAt = null, bool $bootstrap = false): array
    {
        $empty = ['events' => [], 'diagnostic' => null, 'discard_reason' => null];
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            $empty['discard_reason'] = 'payload_too_large';
            return $empty;
        }

        $availability = str_ends_with($topic, '/availability');
        $baseTopic = $availability ? substr($topic, 0, -strlen('/availability')) : $topic;
        $device = $this->devices[$baseTopic] ?? null;
        if ($device === null) {
            $empty['discard_reason'] = 'unknown_topic';
            return $empty;
        }

        if ($availability) {
            $state = strtolower(trim($payload));
            if (!in_array($state, ['online', 'offline'], true)) {
                $empty['discard_reason'] = 'invalid_availability';
                return $empty;
            }
            $empty['diagnostic'] = [
                'kind' => 'availability',
                'state' => $state,
                'device_external_id' => $device['external_id'],
            ];
            return $empty;
        }

        try {
            $decoded = json_decode($payload, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $empty['discard_reason'] = 'invalid_json';
            return $empty;
        }
        if (!$decoded instanceof \stdClass) {
            $empty['discard_reason'] = 'invalid_payload_shape';
            return $empty;
        }

        $receivedAt = $receivedAt === null
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : DateTimeImmutable::createFromInterface($receivedAt)->setTimezone(new DateTimeZone('UTC'));
        $occurredAt = $receivedAt->format('Y-m-d\TH:i:s.v\Z');
        $receivedSecond = $receivedAt->format('Y-m-d\TH:i:s\Z');
        $identityTime = $this->version === 2 ? $occurredAt : $receivedSecond;

        $events = [];
        $unmappedValue = false;
        foreach (get_object_vars($decoded) as $entity => $rawValue) {
            $mapping = $device['entities'][$entity] ?? null;
            if ($mapping === null) {
                continue;
            }
            if (!is_scalar($rawValue)) {
                $unmappedValue = true;
                continue;
            }

            $valueKey = $this->normalizeScalar($rawValue);
            $canonicalValue = $mapping['values'][$valueKey] ?? null;
            if ($canonicalValue === null) {
                $unmappedValue = true;
                continue;
            }

            $events[] = [
                'device_external_id' => $device['external_id'],
                'sensor' => $mapping['sensor'],
                'value' => $canonicalValue,
                'provider' => self::PROVIDER,
                'occurred_at' => $occurredAt,
                'source_event_id' => 'z2m-' . hash('sha256', $topic . "\0" . $payload . "\0" . $entity . "\0" . $identityTime),
                'meta' => [
                    'source' => 'zigbee2mqtt',
                    'topic' => $baseTopic,
                    'entity' => $entity,
                    'zigbee_value' => $rawValue,
                    'bootstrap' => $bootstrap,
                ],
            ];
        }

        if ($events === []) {
            $empty['discard_reason'] = $unmappedValue ? 'unmapped_value' : 'no_mapped_entities';
            return $empty;
        }
        $empty['events'] = $events;
        return $empty;
    }

    /** @return list<string> */
    private static function allowedValuesForSensor(mixed $sensor): array
    {
        return match ($sensor) {
            'PROXIMITY' => ['OPEN', 'CLOSED'],
            'PRESENCE' => ['PRESENT', 'ABSENT'],
            'SWITCH' => ['ON', 'OFF'],
            default => [],
        };
    }

    private function isValidTopic(string $topic): bool
    {
        return $topic !== ''
            && strlen($topic) <= 200
            && preg_match('/^[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*$/', $topic) === 1;
    }

    private function normalizeScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return strtolower(trim((string) $value));
    }
}
