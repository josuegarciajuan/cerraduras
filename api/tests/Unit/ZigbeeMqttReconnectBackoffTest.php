<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Infrastructure\Gateways\Sensor\ZigbeeMqttReconnectBackoff;

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

function eq(string $label, int $actual, int $expected): void
{
    if ($actual === $expected) {
        pass($label);
    } else {
        fail($label . " — expected {$expected}, got {$actual}");
    }
}

eq('F90: first transport failure waits one second', ZigbeeMqttReconnectBackoff::delayFor(1), 1);
eq('F90: backoff doubles on second failure', ZigbeeMqttReconnectBackoff::delayFor(2), 2);
eq('F90: backoff doubles on third failure', ZigbeeMqttReconnectBackoff::delayFor(3), 4);
eq('F90: backoff caps at thirty seconds', ZigbeeMqttReconnectBackoff::delayFor(6), 30);
eq('F90: cap remains stable after repeated failures', ZigbeeMqttReconnectBackoff::delayFor(20), 30);
eq('F90: invalid attempt is treated as first failure', ZigbeeMqttReconnectBackoff::delayFor(0), 1);

echo "\nTotal: {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
