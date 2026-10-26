<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Gateways\Sensor\ZigbeeMqttMessageMapper;

$PASS = 0;
$FAIL = 0;

function pass(string $message): void
{
    global $PASS;
    $PASS++;
    echo "  ✅ {$message}\n";
}

function fail(string $message): void
{
    global $FAIL;
    $FAIL++;
    echo "  ❌ {$message}\n";
}

function same(string $label, mixed $actual, mixed $expected): void
{
    if ($actual === $expected) {
        pass($label);
        return;
    }
    fail($label . ' — expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function check(string $label, bool $condition): void
{
    $condition ? pass($label) : fail($label);
}

function mapperConfig(): array
{
    return [
        'devices' => [
            'zigbee2mqtt/door-contact' => [
                'external_id' => 'ieee-door-contact',
                'entities' => [
                    'contact' => [
                        'sensor' => 'PROXIMITY',
                        'values' => ['true' => 'CLOSED', 'false' => 'OPEN'],
                    ],
                    'occupancy' => [
                        'sensor' => 'PRESENCE',
                        'values' => ['true' => 'PRESENT', 'false' => 'ABSENT'],
                    ],
                    'state' => [
                        'sensor' => 'PROXIMITY',
                        'values' => ['ON' => 'OPEN', 'OFF' => 'CLOSED'],
                    ],
                ],
            ],
        ],
    ];
}

$mapper = new ZigbeeMqttMessageMapper(mapperConfig(), 'zigbee2mqtt');
$at = new DateTimeImmutable('2026-10-26T12:34:56.789Z');

// Configured properties map explicitly; unknown properties are ignored.
$mapped = $mapper->mapMessage(
    'zigbee2mqtt/door-contact',
    '{"contact":false,"occupancy":true,"linkquality":88}',
    $at
);
same('F90: mapped event count from configured standard entities', count($mapped['events']), 2);
same('F90: contact mapping is device-configured', $mapped['events'][0]['sensor'] ?? null, 'PROXIMITY');
same('F90: contact false value uses configured canonical value', $mapped['events'][0]['value'] ?? null, 'OPEN');
same('F90: occupancy maps independently', $mapped['events'][1]['value'] ?? null, 'PRESENT');
same('F90: canonical provider is Zigbee2MQTT', $mapped['events'][0]['provider'] ?? null, 'ZIGBEE2MQTT');
same('F90: received timestamp is UTC with milliseconds', $mapped['events'][0]['occurred_at'] ?? null, '2026-10-26T12:34:56.789Z');
same('F90: event does not retain whole raw payload', $mapped['events'][0]['meta']['raw'] ?? null, null);

// Entity string values are also explicit; case matching does not infer semantics.
$state = $mapper->mapMessage('zigbee2mqtt/door-contact', '{"state":"on"}', $at);
same('F90: explicit state mapping is case-insensitive', $state['events'][0]['value'] ?? null, 'OPEN');

// Availability remains diagnostic and cannot become a room absence event.
$availability = $mapper->mapMessage('zigbee2mqtt/door-contact/availability', 'offline', $at);
same('F90: availability produces no domain event', $availability['events'] ?? null, []);
same('F90: availability is diagnostic only', $availability['diagnostic']['state'] ?? null, 'offline');

// Stable per-second identity collapses immediate QoS duplicates but distinguishes later repeats.
$first = $mapper->mapMessage('zigbee2mqtt/door-contact', '{"contact":false}', $at)['events'][0]['source_event_id'] ?? null;
$duplicate = $mapper->mapMessage(
    'zigbee2mqtt/door-contact',
    '{"contact":false}',
    new DateTimeImmutable('2026-10-26T12:34:56.999Z')
)['events'][0]['source_event_id'] ?? null;
$later = $mapper->mapMessage(
    'zigbee2mqtt/door-contact',
    '{"contact":false}',
    new DateTimeImmutable('2026-10-26T12:34:57.000Z')
)['events'][0]['source_event_id'] ?? null;
same('F90: identical delivery in same second has stable id', $duplicate, $first);
check('F90: repeated transition in a later second has a distinct id', is_string($first) && $first !== $later);
check('F90: event id fits existing VARCHAR(128)', is_string($first) && strlen($first) < 128);

// Unconfigured or invalid input is rejected without producing a canonical event.
$unknownTopic = $mapper->mapMessage('zigbee2mqtt/unconfigured', '{"contact":true}', $at);
same('F90: unknown topic has no event', $unknownTopic['events'] ?? null, []);
same('F90: unknown topic is diagnosable', $unknownTopic['discard_reason'] ?? null, 'unknown_topic');
$unknownValue = $mapper->mapMessage('zigbee2mqtt/door-contact', '{"contact":"ajar"}', $at);
same('F90: unmapped value has no event', $unknownValue['events'] ?? null, []);
same('F90: unmapped value is diagnosable', $unknownValue['discard_reason'] ?? null, 'unmapped_value');
$invalidJson = $mapper->mapMessage('zigbee2mqtt/door-contact', '{bad json', $at);
same('F90: malformed JSON is discarded', $invalidJson['discard_reason'] ?? null, 'invalid_json');
$oversized = $mapper->mapMessage('zigbee2mqtt/door-contact', str_repeat(' ', 65537), $at);
same('F90: payload over 64 KiB is discarded', $oversized['discard_reason'] ?? null, 'payload_too_large');
$notObject = $mapper->mapMessage('zigbee2mqtt/door-contact', '[true]', $at);
same('F90: JSON array is not accepted as a device object', $notObject['discard_reason'] ?? null, 'invalid_payload_shape');

// Bad configuration fails closed before any transport can start.
$invalidConfig = mapperConfig();
$invalidConfig['devices']['zigbee2mqtt/door-contact']['entities']['model_dp'] = [
    'sensor' => 'PRESENCE',
    'values' => ['occupied' => 'PRESENT'],
];
try {
    new ZigbeeMqttMessageMapper($invalidConfig, 'zigbee2mqtt');
    fail('F90: non-standard entity mapping is rejected');
} catch (InvalidArgumentException) {
    pass('F90: non-standard entity mapping is rejected');
}

try {
    new ZigbeeMqttMessageMapper(['devices' => []], 'zigbee2mqtt');
    fail('F90: empty live device map is rejected');
} catch (InvalidArgumentException) {
    pass('F90: empty live device map is rejected');
}

echo "\nTotal: {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
