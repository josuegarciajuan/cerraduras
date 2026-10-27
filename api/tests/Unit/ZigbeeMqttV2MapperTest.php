<?php
declare(strict_types=1);

/**
 * F91/TSK-91.06 — MQTT map v2: contact, presence and SWITCH.
 *
 * Run:
 *   php tests/Unit/ZigbeeMqttV2MapperTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Gateways\Sensor\ZigbeeMqttMessageMapper;

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }
function check(string $m, bool $c): void { $c ? pass($m) : fail($m); }
function same(string $m, mixed $a, mixed $e): void
{
    if ($a === $e) { pass($m); return; }
    fail($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}

$config = [
    'version' => 2,
    'devices' => [
        'zigbee2mqtt/almacen-zb-puerta' => [
            'external_id' => '0xc09b9efffef431fb',
            'entities' => [
                'contact' => ['sensor' => 'PROXIMITY', 'values' => ['true' => 'OPEN', 'false' => 'CLOSED']],
            ],
        ],
        'zigbee2mqtt/almacen-zb-presencia' => [
            'external_id' => '0xa4c138b2a3c49dbe',
            'entities' => [
                'presence' => ['sensor' => 'PRESENCE', 'values' => ['true' => 'PRESENT', 'false' => 'ABSENT']],
            ],
        ],
        'zigbee2mqtt/almacen-zb-luz' => [
            'external_id' => '0xf044d3fffecd9e8e',
            'entities' => [
                'state' => ['sensor' => 'SWITCH', 'values' => ['ON' => 'ON', 'OFF' => 'OFF']],
            ],
        ],
    ],
];

$mapper = new ZigbeeMqttMessageMapper($config, 'zigbee2mqtt');
$at = new DateTimeImmutable('2026-10-27T10:00:00.123Z');

echo "Zigbee MQTT map v2 (F91/TSK-91.06)\n";
echo str_repeat("=", 60) . "\n\n";

$door = $mapper->mapMessage('zigbee2mqtt/almacen-zb-puerta', '{"contact":true,"linkquality":88}', $at);
same('v2 contact true → OPEN', $door['events'][0]['value'] ?? null, 'OPEN');
same('v2 contact event is PROXIMITY', $door['events'][0]['sensor'] ?? null, 'PROXIMITY');
same('v2 contact entity audited', $door['events'][0]['meta']['entity'] ?? null, 'contact');

$closed = $mapper->mapMessage('zigbee2mqtt/almacen-zb-puerta', '{"contact":false}', $at);
same('v2 contact false → CLOSED', $closed['events'][0]['value'] ?? null, 'CLOSED');

$presence = $mapper->mapMessage('zigbee2mqtt/almacen-zb-presencia', '{"presence":true,"illuminance":40}', $at);
same('v2 presence true → PRESENT', $presence['events'][0]['value'] ?? null, 'PRESENT');
same('v2 presence event is PRESENCE', $presence['events'][0]['sensor'] ?? null, 'PRESENCE');
same('v2 presence ignores illuminance', count($presence['events']), 1);

$switch = $mapper->mapMessage('zigbee2mqtt/almacen-zb-luz', '{"state":"ON","power_on_behavior":"off"}', $at);
same('v2 state ON → SWITCH ON', $switch['events'][0]['value'] ?? null, 'ON');
same('v2 state event is SWITCH', $switch['events'][0]['sensor'] ?? null, 'SWITCH');
same('v2 state ignores power_on_behavior', count($switch['events']), 1);

$bootstrap = $mapper->mapMessage('zigbee2mqtt/almacen-zb-puerta', '{"contact":true}', $at, true);
same('bootstrap marker propagates to meta', $bootstrap['events'][0]['meta']['bootstrap'] ?? null, true);

$first = $mapper->mapMessage('zigbee2mqtt/almacen-zb-puerta', '{"contact":true}', $at)['events'][0]['source_event_id'];
$later = $mapper->mapMessage(
    'zigbee2mqtt/almacen-zb-puerta',
    '{"contact":true}',
    new DateTimeImmutable('2026-10-27T10:00:00.999Z')
)['events'][0]['source_event_id'];
check('v2 source_event_id includes milliseconds', $first !== $later);

// v2 strictness: state cannot be mapped to a door sensor.
$badState = $config;
$badState['devices']['zigbee2mqtt/almacen-zb-luz']['entities']['state']['sensor'] = 'PROXIMITY';
try {
    new ZigbeeMqttMessageMapper($badState, 'zigbee2mqtt');
    fail('v2 rejects state mapped to PROXIMITY');
} catch (InvalidArgumentException) {
    pass('v2 rejects state mapped to PROXIMITY');
}

// v2 strictness: production maps cannot use *-oficina names.
$badTopic = $config;
$badTopic['devices']['zigbee2mqtt/almacen-zb-puerta-oficina'] = $badTopic['devices']['zigbee2mqtt/almacen-zb-puerta'];
unset($badTopic['devices']['zigbee2mqtt/almacen-zb-puerta']);
try {
    new ZigbeeMqttMessageMapper($badTopic, 'zigbee2mqtt');
    fail('v2 rejects *-oficina topics');
} catch (InvalidArgumentException) {
    pass('v2 rejects *-oficina topics');
}

// v1 remains supported for legacy maps.
$v1 = ['devices' => ['zigbee2mqtt/legacy' => [
    'external_id' => 'legacy',
    'entities' => ['state' => ['sensor' => 'PROXIMITY', 'values' => ['ON' => 'OPEN', 'OFF' => 'CLOSED']]],
]]];
try {
    $legacy = new ZigbeeMqttMessageMapper($v1, 'zigbee2mqtt');
    $legacyEvent = $legacy->mapMessage('zigbee2mqtt/legacy', '{"state":"ON"}', $at);
    same('v1 legacy state→PROXIMITY still works', $legacyEvent['events'][0]['value'] ?? null, 'OPEN');
} catch (InvalidArgumentException) {
    fail('v1 legacy map was rejected');
}

echo "\nTotal: {$passed} passed, {$failed} failed\n\n";
exit($failed === 0 ? 0 : 1);
