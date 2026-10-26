<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Sensor;

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
    private const ENTITIES = ['contact', 'occupancy', 'state'];
    private const PROVIDER = 'ZIGBEE2MQTT';

    /** @var array<string,array{external_id:string,entities:array<string,array{sensor:string,values:array<string,string>}>}> */
    private array $devices;

    public function __construct(array $config, private string $topicPrefix = 'zigbee2mqtt')
    {
        if (!$this->isValidTopic($topicPrefix)) {
            throw new InvalidArgumentException('Invalid MQTT topic prefix');
        }
        if (!isset($config['devices']) || !is_array($config['devices']) || $config['devices'] === []) {
            if (($config['devices'] ?? null) !== []) {
                throw new InvalidArgumentException('Device map must contain a devices object');
            }
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

            $normalizedEntities = [];
            foreach ($entities as $entity => $mapping) {
                if (!is_string($entity) || !in_array($entity, self::ENTITIES, true)
                    || !is_array($mapping)
                ) {
                    throw new InvalidArgumentException('Unsupported Zigbee2MQTT entity mapping');
                }

                $sensor = $mapping['sensor'] ?? null;
                $values = $mapping['values'] ?? null;
                $allowedValues = $sensor === 'PROXIMITY' ? ['OPEN', 'CLOSED']
                    : ($sensor === 'PRESENCE' ? ['PRESENT', 'ABSENT'] : []);
                if ($allowedValues === [] || !is_array($values) || $values === []) {
                    throw new InvalidArgumentException('Invalid canonical sensor mapping');
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
     *   diagnostic:array{kind:string,state:string}|null,
     *   discard_reason:string|null
     * }
     */
    public function mapMessage(string $topic, string $payload, ?DateTimeInterface $receivedAt = null): array
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
            $empty['diagnostic'] = ['kind' => 'availability', 'state' => $state];
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
                'source_event_id' => 'z2m-' . hash('sha256', $topic . "\0" . $payload . "\0" . $entity . "\0" . $receivedSecond),
                'meta' => [
                    'source' => 'zigbee2mqtt',
                    'topic' => $baseTopic,
                    'entity' => $entity,
                    'zigbee_value' => $rawValue,
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
