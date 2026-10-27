<?php
declare(strict_types=1);

/**
 * bin/provision-zigbee-almacen.php — F91/TSK-91.04
 *
 * Provisiona de forma idempotente y aislada:
 *   - pack `Pack Zigbee` (`PACK_ZIGBEE`)
 *   - sala `almacenZigbee` de tipo `ALMACEN_BEBIDAS`
 *   - tres dispositivos Zigbee con IEEE estable y transporte ZIGBEE2MQTT
 *   - `presence_mode_override=radar_door` solo para esa sala
 *
 * No habilita MQTT, no arranca servicios, no toca Tuya ni el almacén actual.
 * Por defecto solo muestra el plan (--dry-run). Para escribir: --apply.
 *
 * Uso:
 *   php bin/provision-zigbee-almacen.php
 *   php bin/provision-zigbee-almacen.php --apply
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Domain\Devices\Device;
use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

const ROOM_CODE = 'almacenZigbee';
const ROOM_TYPE_CODE = 'ALMACEN_BEBIDAS';
const PACK_CODE = 'PACK_ZIGBEE';
const PACK_NAME = 'Pack Zigbee';
const ROOM_NOTES = 'Piloto Zigbee. No sustituye al almacén actual.';

const DEVICES = [
    [
        'kind' => Device::KIND_PROXIMITY,
        'ieee' => '0xc09b9efffef431fb',
        'label' => 'Zigbee · Puerta · almacenZigbee',
        'friendly' => 'almacen-zb-puerta',
        'model' => 'SNZB-04P',
    ],
    [
        'kind' => Device::KIND_PRESENCE,
        'ieee' => '0xa4c138b2a3c49dbe',
        'label' => 'Zigbee · Presencia · almacenZigbee',
        'friendly' => 'almacen-zb-presencia',
        'model' => 'ZY-M100-S_2',
    ],
    [
        'kind' => Device::KIND_SWITCH,
        'ieee' => '0xf044d3fffecd9e8e',
        'label' => 'Zigbee · Luz · almacenZigbee',
        'friendly' => 'almacen-zb-luz',
        'model' => 'ZBMINIL2',
    ],
];

$apply = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif ($arg === '--dry-run' || $arg === '--help' || $arg === '-h') {
        if ($arg === '--help' || $arg === '-h') {
            echo "Uso: php bin/provision-zigbee-almacen.php [--dry-run|--apply]\n";
            exit(0);
        }
    } else {
        fwrite(STDERR, "[provision-zigbee] argumento desconocido: {$arg}\n");
        fwrite(STDERR, "Uso: php bin/provision-zigbee-almacen.php [--dry-run|--apply]\n");
        exit(2);
    }
}

try {
    $pdo = PdoFactory::make();
} catch (\Throwable $e) {
    fwrite(STDERR, "[provision-zigbee] ERROR de conexión: " . $e->getMessage() . "\n");
    exit(1);
}

$log = static function (string $msg): void {
    echo "[provision-zigbee] {$msg}\n";
};
$fail = static function (string $msg, int $code = 1) use ($log): void {
    $log('ERROR: ' . $msg);
    exit($code);
};

// ---------------------------------------------------------------------------
// Schema guards: this command depends on additive F91 columns.
// ---------------------------------------------------------------------------
foreach ([
    ['devices', 'transport'],
    ['rooms', 'presence_mode_override'],
] as [$table, $column]) {
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
    if ($stmt->fetch(PDO::FETCH_ASSOC) === false) {
        $fail("falta {$table}.{$column}; ejecuta php bin/migrate.php");
    }
}

// ---------------------------------------------------------------------------
// Read current state and validate collisions before touching anything.
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT id, code FROM room_types WHERE code = :c');
$stmt->execute([':c' => ROOM_TYPE_CODE]);
$types = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($types) !== 1) {
    $fail('se esperaba exactamente un room_type ' . ROOM_TYPE_CODE . ', encontrados ' . count($types));
}
$roomTypeId = (int) $types[0]['id'];
$log('room_type ' . ROOM_TYPE_CODE . ' = id ' . $roomTypeId);

$stmt = $pdo->prepare('SELECT id, code, name FROM device_packs WHERE code = :c LIMIT 1');
$stmt->execute([':c' => PACK_CODE]);
$pack = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
if ($pack !== null) {
    if ((string) $pack['name'] !== PACK_NAME) {
        $fail('el pack ' . PACK_CODE . ' existe con otro nombre: ' . $pack['name']);
    }
    $stmt = $pdo->prepare('SELECT id, code FROM rooms WHERE pack_id = :pid AND code <> :rc');
    $stmt->execute([':pid' => (int) $pack['id'], ':rc' => ROOM_CODE]);
    $other = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($other !== false && $other !== null) {
        $fail('el pack ' . PACK_CODE . ' ya está asignado a la sala ' . $other['code']);
    }
}

$stmt = $pdo->prepare('SELECT id, code, room_type_id, pack_id FROM rooms WHERE code = :c LIMIT 1');
$stmt->execute([':c' => ROOM_CODE]);
$room = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
if ($room !== null) {
    if ((int) $room['room_type_id'] !== $roomTypeId) {
        $fail('la sala ' . ROOM_CODE . ' existe con otro tipo (' . $room['room_type_id'] . ')');
    }
    if ($room['pack_id'] !== null && $pack !== null && (int) $room['pack_id'] !== (int) $pack['id']) {
        $fail('la sala ' . ROOM_CODE . ' tiene otro pack asignado (' . $room['pack_id'] . ')');
    }
}

$packId = $pack !== null ? (int) $pack['id'] : null;

// Pack contents must be exactly our devices (or empty while provisioning).
if ($packId !== null) {
    $stmt = $pdo->prepare('SELECT kind, external_id FROM devices WHERE pack_id = :pid');
    $stmt->execute([':pid' => $packId]);
    $existingPackDevices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $expected = [];
    foreach (DEVICES as $d) {
        $expected[$d['kind'] . '|' . $d['ieee']] = true;
    }
    foreach ($existingPackDevices as $row) {
        $key = $row['kind'] . '|' . $row['external_id'];
        if (!isset($expected[$key])) {
            $fail('el pack ' . PACK_CODE . ' contiene un dispositivo ajeno: ' . $key);
        }
    }
}

$deviceActions = [];
foreach (DEVICES as $spec) {
    $stmt = $pdo->prepare(
        'SELECT id, kind, external_id, pack_id, transport, label, meta_json
         FROM devices WHERE kind = :k AND external_id = :x LIMIT 1'
    );
    $stmt->execute([':k' => $spec['kind'], ':x' => $spec['ieee']]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmt = $pdo->prepare('SELECT id, kind FROM devices WHERE external_id = :x LIMIT 1');
    $stmt->execute([':x' => $spec['ieee']]);
    $sameIdOtherKind = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($sameIdOtherKind !== null && (string) $sameIdOtherKind['kind'] !== $spec['kind']) {
        $fail('el IEEE ' . $spec['ieee'] . ' ya existe con otro tipo: ' . $sameIdOtherKind['kind']);
    }

    if ($existing !== null) {
        if ($packId !== null && ((int) $existing['pack_id']) !== $packId) {
            $fail('el dispositivo ' . $spec['ieee'] . ' existe en otro pack (' . $existing['pack_id'] . ')');
        }
        $transport = $existing['transport'] !== null ? (string) $existing['transport'] : null;
        if ($transport !== null && $transport !== Device::TRANSPORT_ZIGBEE2MQTT) {
            $fail('el dispositivo ' . $spec['ieee'] . ' tiene transporte ' . $transport);
        }
        $needsUpdate = $transport === null
            || $existing['label'] === null
            || $existing['meta_json'] === null;
        $deviceActions[] = [
            'action' => $needsUpdate ? 'update' : 'noop',
            'id' => (int) $existing['id'],
            'spec' => $spec,
        ];
    } else {
        if ($packId !== null) {
            $stmt = $pdo->prepare('SELECT id FROM devices WHERE pack_id = :pid AND kind = :k LIMIT 1');
            $stmt->execute([':pid' => $packId, ':k' => $spec['kind']]);
            if ($stmt->fetchColumn() !== false) {
                $fail('el pack ' . PACK_CODE . ' ya tiene otro dispositivo de tipo ' . $spec['kind']);
            }
        }
        $deviceActions[] = ['action' => 'create', 'id' => null, 'spec' => $spec];
    }
}

$log('pack ' . PACK_CODE . ': ' . ($pack === null ? 'crear' : 'usar id ' . $packId));
$log('sala ' . ROOM_CODE . ': ' . ($room === null ? 'crear' : 'usar id ' . (int) $room['id']));
foreach ($deviceActions as $a) {
    $log('dispositivo ' . $a['spec']['kind'] . ' ' . $a['spec']['ieee'] . ': ' . $a['action']);
}

if (!$apply) {
    $log('--dry-run: no se escribe nada. Usa --apply para provisionar.');
    exit(0);
}

// ---------------------------------------------------------------------------
// Apply atomically.
// ---------------------------------------------------------------------------
$pdo->beginTransaction();
try {
    if ($packId === null) {
        $stmt = $pdo->prepare(
            'INSERT INTO device_packs (code, name, created_at, updated_at)
             VALUES (:code, :name, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
        );
        $stmt->execute([':code' => PACK_CODE, ':name' => PACK_NAME]);
        $packId = (int) $pdo->lastInsertId();
    }

    if ($room === null) {
        $stmt = $pdo->prepare(
            "INSERT INTO rooms
                (code, room_type_id, simulated_override, status, notes, presence_mode_override)
             VALUES (:code, :rt, NULL, 'FREE', :notes, 'radar_door')"
        );
        $stmt->execute([
            ':code' => ROOM_CODE,
            ':rt' => $roomTypeId,
            ':notes' => ROOM_NOTES,
        ]);
        $roomId = (int) $pdo->lastInsertId();
    } else {
        $roomId = (int) $room['id'];
        $stmt = $pdo->prepare(
            "UPDATE rooms
                SET room_type_id = :rt,
                    pack_id = :pid,
                    notes = COALESCE(NULLIF(notes, ''), :notes),
                    presence_mode_override = 'radar_door'
              WHERE id = :id"
        );
        $stmt->execute([
            ':rt' => $roomTypeId,
            ':pid' => $packId,
            ':notes' => ROOM_NOTES,
            ':id' => $roomId,
        ]);
    }

    foreach ($deviceActions as $a) {
        $spec = $a['spec'];
        $meta = [
            'provider' => Device::TRANSPORT_ZIGBEE2MQTT,
            'model' => $spec['model'],
            'friendly_name' => $spec['friendly'],
            'topic' => 'zigbee2mqtt/' . $spec['friendly'],
            'ieee' => $spec['ieee'],
        ];
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($a['action'] === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO devices
                    (pack_id, kind, subtype, transport, external_id, label, meta_json)
                 VALUES (:pid, :k, NULL, :tr, :x, :label, :meta)'
            );
            $stmt->execute([
                ':pid' => $packId,
                ':k' => $spec['kind'],
                ':tr' => Device::TRANSPORT_ZIGBEE2MQTT,
                ':x' => $spec['ieee'],
                ':label' => $spec['label'],
                ':meta' => $metaJson,
            ]);
        } elseif ($a['action'] === 'update') {
            $stmt = $pdo->prepare(
                "UPDATE devices
                    SET pack_id = :pid,
                        transport = :tr,
                        label = COALESCE(NULLIF(label, ''), :label),
                        meta_json = COALESCE(meta_json, :meta)
                  WHERE id = :id"
            );
            $stmt->execute([
                ':pid' => $packId,
                ':tr' => Device::TRANSPORT_ZIGBEE2MQTT,
                ':label' => $spec['label'],
                ':meta' => $metaJson,
                ':id' => $a['id'],
            ]);
        }
    }

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $fail('fallo al provisionar: ' . $e->getMessage());
}

$log('OK: pack ' . PACK_CODE . ' (id ' . $packId . '), sala ' . ROOM_CODE . ' (id ' . $roomId . '), '
    . count(DEVICES) . ' dispositivos, presence_mode_override=radar_door.');
$log('MQTT sigue deshabilitado; no se ha arrancado ningún servicio.');
exit(0);
