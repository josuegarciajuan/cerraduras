<?php
declare(strict_types=1);

/**
 * RemoteDoorOpenPolicyTest — F116/RF-293.
 *
 * Pure checks: capability advertised by firmware, version gate, payload.
 * No DB, no HTTP, no hardware.
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Warehouse\RemoteDoorOpenPolicy;

$passed = 0;
$failed = 0;
function checkRemoteOpen(bool $condition, string $message): void
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . "\n";
}

// Capability + version
checkRemoteOpen(
    RemoteDoorOpenPolicy::isCapable([RemoteDoorOpenPolicy::META_KEY => true], '2.7-ota'),
    'acepta 2.7-ota con la capacidad anunciada'
);
checkRemoteOpen(
    RemoteDoorOpenPolicy::isCapable([RemoteDoorOpenPolicy::META_KEY => true], '2.8'),
    'acepta 2.8 (versión posterior)'
);
checkRemoteOpen(
    RemoteDoorOpenPolicy::isCapable([RemoteDoorOpenPolicy::META_KEY => true], '3.0.1'),
    'acepta 3.0.1 (major posterior)'
);
checkRemoteOpen(
    !RemoteDoorOpenPolicy::isCapable([RemoteDoorOpenPolicy::META_KEY => true], '2.6-ota'),
    'rechaza 2.6-ota (firmware sin comando open)'
);
checkRemoteOpen(
    !RemoteDoorOpenPolicy::isCapable([], '2.7-ota'),
    'rechaza un RPI que no anuncia la capacidad'
);
checkRemoteOpen(
    !RemoteDoorOpenPolicy::isCapable([RemoteDoorOpenPolicy::META_KEY => false], '2.7-ota'),
    'rechaza la capacidad explícitamente desactivada'
);
checkRemoteOpen(
    !RemoteDoorOpenPolicy::isCapable([RemoteDoorOpenPolicy::META_KEY => true], null),
    'rechaza versión desconocida'
);
checkRemoteOpen(
    !RemoteDoorOpenPolicy::isCapable([RemoteDoorOpenPolicy::META_KEY => true], 'ota'),
    'rechaza una versión no numérica'
);

// Payload shape
$payload = RemoteDoorOpenPolicy::payload(14, 'panel', '2026-11-05T15:00:00Z');
checkRemoteOpen(
    ($payload['room_id'] ?? null) === 14
    && ($payload['requested_by'] ?? null) === 'panel'
    && ($payload['source'] ?? null) === 'timbre'
    && ($payload['protocol'] ?? null) === 'remote-open-v1',
    'payload con sala, actor, origen y protocolo'
);

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
