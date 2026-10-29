<?php
declare(strict_types=1);

/**
 * RoomSensorStateRepositoryTest — F97.3.
 *
 * Verifica que la última posición física del contacto se conserva fuera de
 * iot_sessions y que una confirmación manual fija una barrera de eventos.
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\RoomSensorStateRepository;
use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../../.env');

$passed = 0;
$failed = 0;
$skipped = 0;
function checkState(bool $condition, string $message): void
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . "\n";
}
function skipState(string $message, string $reason): void
{
    global $skipped;
    $skipped++;
    echo "SKIP {$message} — {$reason}\n";
}

echo "RoomSensorStateRepositoryTest\n";

try {
    $pdo = PdoFactory::make();
} catch (Throwable $e) {
    skipState('RoomSensorStateRepositoryTest', 'BD no disponible: ' . $e->getMessage());
    echo "\nTotal: {$passed} passed, {$failed} failed, {$skipped} skipped\n";
    exit(0);
}

$roomTypeId = (int) ($pdo->query('SELECT id FROM room_types ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
if ($roomTypeId <= 0) {
    skipState('RoomSensorStateRepositoryTest', 'sin room_types');
    echo "\nTotal: {$passed} passed, {$failed} failed, {$skipped} skipped\n";
    exit(0);
}

$code = 'F97STATE-' . bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO rooms (code, room_type_id, status) VALUES (:code, :type, 'FREE')")
    ->execute([':code' => $code, ':type' => $roomTypeId]);
$roomId = (int) $pdo->lastInsertId();

try {
    $repo = new RoomSensorStateRepository($pdo);

    $repo->record($roomId, 'PROXIMITY', 'ZIGBEE2MQTT', 'CLOSED', 100,
        '2026-10-29 10:00:00.000', '2026-10-29 10:00:00.100', '0xabc');
    $state = $repo->get($roomId, 'PROXIMITY', 'ZIGBEE2MQTT');
    checkState($state !== null && $state['state_value'] === 'CLOSED'
        && $state['last_event_id'] === 100 && $state['state_source'] === 'event',
        'registra CLOSED con evento y fuente "event"');

    $repo->record($roomId, 'PROXIMITY', 'ZIGBEE2MQTT', 'OPEN', 101,
        '2026-10-29 10:01:00.000', '2026-10-29 10:01:00.100', '0xabc');
    $state = $repo->get($roomId, 'PROXIMITY', 'ZIGBEE2MQTT');
    checkState($state !== null && $state['state_value'] === 'OPEN'
        && $state['last_event_id'] === 101,
        'una transición aplicada actualiza el estado físico');

    $repo->setManual($roomId, 'PROXIMITY', 'ZIGBEE2MQTT', 'CLOSED', 105, '0xabc');
    $state = $repo->get($roomId, 'PROXIMITY', 'ZIGBEE2MQTT');
    checkState($state !== null && $state['state_value'] === 'CLOSED'
        && $state['last_event_id'] === 105 && $state['state_source'] === 'manual_confirm',
        'la confirmación manual fija CLOSED y barrera 105');

    $repo->setUnknown($roomId, 'PROXIMITY', 'ZIGBEE2MQTT', 200, '0xabc');
    $state = $repo->get($roomId, 'PROXIMITY', 'ZIGBEE2MQTT');
    checkState($state !== null && $state['state_value'] === null
        && $state['last_event_id'] === 200 && $state['state_source'] === 'invalidated',
        'invalidar deja el estado desconocido sin retroceder la barrera');

    checkState($repo->maxAppliedEventId($roomId, 'PROXIMITY', 'ZIGBEE2MQTT') === 0,
        'maxAppliedEventId devuelve 0 sin eventos');
} finally {
    $pdo->prepare('DELETE FROM room_sensor_state WHERE room_id = :rid')->execute([':rid' => $roomId]);
    $pdo->prepare('DELETE FROM rooms WHERE id = :rid')->execute([':rid' => $roomId]);
}

echo "\nTotal: {$passed} passed, {$failed} failed, {$skipped} skipped\n";
exit($failed === 0 ? 0 : 1);
