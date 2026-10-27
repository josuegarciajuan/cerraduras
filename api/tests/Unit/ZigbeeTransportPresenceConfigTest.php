<?php
declare(strict_types=1);

/**
 * Unit tests for F91/TSK-91.01:
 *  - explicit device transport (RF-144.4/144.5)
 *  - room-type presence modes and evidence windows (RF-148.1/148.4)
 *  - additive migrations 0127/0128
 *
 * Pure logic; no DB, HTTP, MQTT or hardware.
 *
 * Run:
 *   php tests/Unit/ZigbeeTransportPresenceConfigTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Domain\Rooms\RoomType;
use App\Domain\Rooms\RoomTypeRepositoryInterface;
use App\Domain\Rooms\RoomTypeService;
use App\Support\Errors\UnprocessableException;

$passed = 0;
$failed = 0;

function pass(string $msg): void
{
    global $passed;
    $passed++;
    echo "  ✅ {$msg}\n";
}

function fail(string $msg): void
{
    global $failed;
    $failed++;
    echo "  ❌ {$msg}\n";
}

/**
 * In-memory room-type repository for validation tests. Extra positional
 * arguments from RoomTypeService::create are captured with func_get_args().
 */
final class FakeRoomTypeRepoF91 implements RoomTypeRepositoryInterface
{
    /** @var array<int,RoomType> */
    public array $byId = [];
    /** @var array<string,int> */
    public array $byCode = [];
    /** @var array<string,mixed> */
    public array $lastUpdate = [];
    public ?RoomType $stored = null;

    public function listAll(): array
    {
        return array_values($this->byId);
    }

    public function findById(int $id): ?RoomType
    {
        return $this->byId[$id] ?? null;
    }

    public function findByCode(string $code): ?RoomType
    {
        $id = $this->byCode[$code] ?? null;
        return $id === null ? null : ($this->byId[$id] ?? null);
    }

    public function insert(
        string $code,
        string $name,
        int $graceMinutes,
        int $exitPresenceGapSeconds,
        int $reentryCooldownSeconds,
        int $qrUsageWindowMinutes
    ): int {
        $args = func_get_args();
        $rest = array_slice($args, 6);
        $id = 1;
        $this->stored = new RoomType(
            $id,
            $code,
            $name,
            $graceMinutes,
            $exitPresenceGapSeconds,
            $reentryCooldownSeconds,
            $qrUsageWindowMinutes,
            (int) ($rest[0] ?? 90),
            (int) ($rest[1] ?? 40),
            (int) ($rest[2] ?? 40),
            (int) ($rest[3] ?? 5),
            (string) ($rest[4] ?? 'camera_fusion'),
            (int) ($rest[5] ?? 3),
            (int) ($rest[6] ?? 12),
            (int) ($rest[7] ?? 6),
            (int) ($rest[8] ?? 20),
            (int) ($rest[9] ?? 3),
            (int) ($rest[10] ?? 30),
            (int) ($rest[11] ?? 90),
            (int) ($rest[12] ?? 180)
        );
        $this->byId[$id] = $this->stored;
        $this->byCode[$code] = $id;
        return $id;
    }

    public function update(int $id, array $fields): int
    {
        $this->lastUpdate = $fields;
        return 1;
    }
}

echo "Zigbee transport + presence config (F91/TSK-91.01)\n";
echo str_repeat("=", 60) . "\n\n";

// ── 1. Device transport vocabulary ───────────────────────────────────────
$expectedTransports = ['TUYA', 'ZIGBEE2MQTT', 'LOCAL', 'SIMULATED', 'UNKNOWN'];
if (Device::transports() === $expectedTransports) {
    pass('Device::transports() contiene los cinco valores');
} else {
    fail('Device::transports() inesperado: ' . json_encode(Device::transports()));
}

$valid = [
    [null, true],
    ['', true],
    ['UNKNOWN', true],
    ['ZIGBEE2MQTT', true],
    ['zigbee2mqtt', true],
    ['TUYA', true],
    ['LOCAL', true],
    ['SIMULATED', true],
    ['ZIGBEE', false],
    ['MQTT', false],
];
foreach ($valid as [$value, $expected]) {
    $got = Device::isValidTransport($value);
    if ($got === $expected) {
        pass('isValidTransport(' . var_export($value, true) . ') === ' . var_export($expected, true));
    } else {
        fail('isValidTransport(' . var_export($value, true) . ') === ' . var_export($got, true));
    }
}

if (Device::normalizeTransport('UNKNOWN') === null && Device::normalizeTransport('') === null) {
    pass('normalizeTransport() convierte UNKNOWN/ vacío en null');
} else {
    fail('normalizeTransport() no normaliza UNKNOWN');
}
if (Device::normalizeTransport('zigbee2mqtt') === 'ZIGBEE2MQTT') {
    pass('normalizeTransport() normaliza a mayúsculas');
} else {
    fail('normalizeTransport() no normaliza mayúsculas');
}

// ── 2. Device toArray: UNKNOWN when null, explicit otherwise ─────────────
$unknown = new Device(1, 2, Device::KIND_PROXIMITY, 'dev-1', null, null, null);
$unknownArr = $unknown->toArray();
if (($unknownArr['transport'] ?? null) === 'UNKNOWN') {
    pass('Device sin transport expone UNKNOWN en toArray()');
} else {
    fail('Device sin transport no expone UNKNOWN: ' . json_encode($unknownArr));
}
$zigbee = new Device(2, 2, Device::KIND_PROXIMITY, 'dev-2', null, null, null, null, false, null, null, 'ZIGBEE2MQTT');
if (($zigbee->toArray()['transport'] ?? null) === 'ZIGBEE2MQTT') {
    pass('Device conserva transport ZIGBEE2MQTT');
} else {
    fail('Device perdió transport ZIGBEE2MQTT');
}

// ── 3. RoomType defaults and vocabulary ──────────────────────────────────
$rt = new RoomType(1, 'ALMACEN_BEBIDAS', 'Almacén', 5, 15, 20, 30);
$rtArr = $rt->toArray();
$defaults = [
    'presence_mode' => 'camera_fusion',
    'presence_confirm_reports' => 3,
    'presence_confirm_window_seconds' => 12,
    'presence_min_span_seconds' => 6,
    'presence_hold_seconds' => 20,
    'presence_absent_reports' => 3,
    'presence_absent_window_seconds' => 30,
    'presence_door_window_seconds' => 90,
    'presence_stale_seconds' => 180,
];
$defaultsOk = true;
foreach ($defaults as $key => $value) {
    if (($rtArr[$key] ?? null) !== $value) {
        $defaultsOk = false;
        fail("default {$key} = " . var_export($rtArr[$key] ?? null, true) . ", esperado " . var_export($value, true));
    }
}
if ($defaultsOk) {
    pass('RoomType: defaults de presencia y toArray() correctos');
}

$modes = ['camera_fusion', 'radar_door', 'radar_only_shadow', 'radar_only', 'mixed'];
if (RoomType::presenceModes() === $modes) {
    pass('RoomType::presenceModes() contiene los cinco modos');
} else {
    fail('RoomType::presenceModes() inesperado');
}
if (RoomType::isValidPresenceMode('radar_only') && !RoomType::isValidPresenceMode('radar')) {
    pass('isValidPresenceMode() acepta radar_only y rechaza radar');
} else {
    fail('isValidPresenceMode() incorrecto');
}

$custom = new RoomType(2, 'X', 'X', 5, 15, 20, 30, 90, 40, 40, 5, 'radar_door', 4, 15, 7, 25, 4, 40, 120, 240);
if ($custom->presenceMode === 'radar_door' && $custom->presenceConfirmReports === 4
    && $custom->presenceStaleSeconds === 240) {
    pass('RoomType acepta configuración de presencia personalizada');
} else {
    fail('RoomType no conserva configuración personalizada');
}

// ── 4. Service validation: defaults and custom values ────────────────────
$repo = new FakeRoomTypeRepoF91();
$service = new RoomTypeService($repo);
$created = $service->create('STANDARD', 'Standard', 5, 15, 20, 30);
if ($created->presenceMode === 'camera_fusion'
    && $created->presenceConfirmReports === 3
    && $created->presenceStaleSeconds === 180) {
    pass('RoomTypeService::create() aplica defaults de presencia');
} else {
    fail('RoomTypeService::create() no aplica defaults: ' . json_encode($created->toArray()));
}

$repo2 = new FakeRoomTypeRepoF91();
$service2 = new RoomTypeService($repo2);
$created2 = $service2->create(
    code: 'ZIGBEE_ROOM',
    name: 'Zigbee room',
    graceMinutes: 5,
    exitPresenceGapSeconds: 15,
    reentryCooldownSeconds: 20,
    qrUsageWindowMinutes: 30,
    presenceMode: 'radar_door',
    presenceConfirmReports: 4,
    presenceConfirmWindowSeconds: 15,
    presenceMinSpanSeconds: 7,
    presenceHoldSeconds: 25,
    presenceAbsentReports: 4,
    presenceAbsentWindowSeconds: 40,
    presenceDoorWindowSeconds: 120,
    presenceStaleSeconds: 240
);
if ($created2->presenceMode === 'radar_door' && $created2->presenceConfirmReports === 4
    && $created2->presenceStaleSeconds === 240) {
    pass('RoomTypeService::create() propaga configuración de presencia');
} else {
    fail('RoomTypeService::create() no propaga configuración: ' . json_encode($created2->toArray()));
}

$invalidCases = [
    ['presenceMode' => 'radar', 'presenceConfirmReports' => 3],
    ['presenceMode' => 'radar_only', 'presenceConfirmReports' => 0],
    ['presenceMode' => 'radar_only', 'presenceConfirmReports' => 21],
    ['presenceMode' => 'radar_only', 'presenceConfirmWindowSeconds' => 0],
    ['presenceMode' => 'radar_only', 'presenceStaleSeconds' => 29],
    ['presenceMode' => 'radar_only', 'presenceStaleSeconds' => 86401],
];
foreach ($invalidCases as $i => $case) {
    $repoX = new FakeRoomTypeRepoF91();
    $serviceX = new RoomTypeService($repoX);
    $args = array_merge([
        'code' => 'X' . $i,
        'name' => 'X',
        'graceMinutes' => 5,
        'exitPresenceGapSeconds' => 15,
        'reentryCooldownSeconds' => 20,
        'qrUsageWindowMinutes' => 30,
    ], $case);
    $threw = false;
    try {
        $serviceX->create(...$args);
    } catch (UnprocessableException $e) {
        $threw = true;
    }
    if ($threw) {
        pass('create() rechaza configuración inválida #' . $i);
    } else {
        fail('create() aceptó configuración inválida #' . $i . ': ' . json_encode($case));
    }
}

$repo3 = new FakeRoomTypeRepoF91();
$service3 = new RoomTypeService($repo3);
$service3->create('UPD', 'Update', 5, 15, 20, 30);
$service3->update(1, ['presence_mode' => 'radar_only_shadow', 'presence_stale_seconds' => 300]);
if (($repo3->lastUpdate['presence_mode'] ?? null) === 'radar_only_shadow'
    && ($repo3->lastUpdate['presence_stale_seconds'] ?? null) === 300) {
    pass('update() propaga modo y frescura de presencia');
} else {
    fail('update() no propaga configuración: ' . json_encode($repo3->lastUpdate));
}

$repo4 = new FakeRoomTypeRepoF91();
$service4 = new RoomTypeService($repo4);
$service4->create('UPD2', 'Update2', 5, 15, 20, 30);
$threw = false;
try {
    $service4->update(1, ['presence_mode' => 'invalid']);
} catch (UnprocessableException $e) {
    $threw = true;
}
if ($threw) {
    pass('update() rechaza presence_mode inválido');
} else {
    fail('update() aceptó presence_mode inválido');
}

// ── 5. Additive migrations ───────────────────────────────────────────────
$migrationTransport = file_get_contents(__DIR__ . '/../../migrations/0127_device_transport.sql');
if (str_contains($migrationTransport, 'ADD COLUMN IF NOT EXISTS `transport` VARCHAR(16) NULL')
    && str_contains($migrationTransport, 'No se hace backfill')) {
    pass('0127 declara devices.transport nullable sin backfill');
} else {
    fail('0127 no contiene la columna transport esperada');
}

$migrationPresence = file_get_contents(__DIR__ . '/../../migrations/0128_room_type_presence_modes.sql');
$requiredColumns = [
    'presence_mode', 'presence_confirm_reports', 'presence_confirm_window_seconds',
    'presence_min_span_seconds', 'presence_hold_seconds', 'presence_absent_reports',
    'presence_absent_window_seconds', 'presence_door_window_seconds', 'presence_stale_seconds',
];
$missing = [];
foreach ($requiredColumns as $col) {
    if (!str_contains($migrationPresence, '`' . $col . '`')) {
        $missing[] = $col;
    }
}
if ($missing === []) {
    pass('0128 declara las nueve columnas de presencia');
} else {
    fail('0128 faltan columnas: ' . implode(', ', $missing));
}
if (str_contains($migrationPresence, "DEFAULT 'camera_fusion'")) {
    pass('0128 conserva camera_fusion como default');
} else {
    fail('0128 no conserva camera_fusion como default');
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
