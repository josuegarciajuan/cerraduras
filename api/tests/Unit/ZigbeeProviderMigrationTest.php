<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\PresenceEvent;

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

function check(string $label, bool $condition): void
{
    $condition ? pass($label) : fail($label);
}

check(
    'F90: domain declares the Zigbee2MQTT audit provider',
    defined(PresenceEvent::class . '::PROVIDER_ZIGBEE2MQTT')
        && constant(PresenceEvent::class . '::PROVIDER_ZIGBEE2MQTT') === 'ZIGBEE2MQTT'
);

$migrationPath = dirname(__DIR__, 2) . '/migrations/0126_presence_provider_zigbee2mqtt.sql';
$migration = is_file($migrationPath) ? file_get_contents($migrationPath) : false;
check('F90: provider migration exists', is_string($migration));
if (is_string($migration)) {
    preg_match_all('/ALTER TABLE\s+`([^`]+)`/i', $migration, $alteredTables);
    check('F90: migration alters only presence_events provider',
        ($alteredTables[1] ?? []) === ['presence_events']);
    check('F90: migration preserves existing provider values',
        str_contains($migration, "'TUYA','ESP32','LOCAL','SIMULATED','ZIGBEE2MQTT'"));
}

echo "\nTotal: {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
