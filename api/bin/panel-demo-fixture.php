<?php
declare(strict_types=1);

/**
 * bin/panel-demo-fixture.php — F120/RF-329
 *
 * Crea o elimina datos de demostración del panel, claramente identificables.
 *
 *   php bin/panel-demo-fixture.php --create
 *   php bin/panel-demo-fixture.php --purge
 *
 * Reglas:
 *   - Solo toca salas marcadas con notes = 'DEMO_F120' y código DEMO-*.
 *   - No asigna packs, dispositivos ni automatizaciones físicas.
 *   - No crea ficheros de vídeo: las grabaciones demo son metadatos DISCARDED.
 *   - Idempotente: --create omite lo que ya existe; --purge borra solo lo marcado.
 *   - Cero llamadas a Tuya.
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$MARK = 'DEMO_F120';
$mode = $argv[1] ?? '';

if (!in_array($mode, ['--create', '--purge'], true)) {
    fwrite(STDERR, "Uso: php bin/panel-demo-fixture.php --create|--purge\n");
    exit(2);
}

$pdo = PdoFactory::make();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/** @return int */
function roomTypeId(PDO $pdo, string $code): int
{
    $stmt = $pdo->prepare('SELECT id FROM room_types WHERE code = :c LIMIT 1');
    $stmt->execute([':c' => $code]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        throw new RuntimeException("No existe el tipo de sala {$code}");
    }
    return (int) $id;
}

function upsertRoom(PDO $pdo, string $code, int $typeId, string $mark): array
{
    $stmt = $pdo->prepare('SELECT id FROM rooms WHERE code = :c LIMIT 1');
    $stmt->execute([':c' => $code]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return ['id' => (int) $id, 'created' => false];
    }
    $stmt = $pdo->prepare(
        "INSERT INTO rooms (code, room_type_id, status, notes)
         VALUES (:c, :t, 'FREE', :n)"
    );
    $stmt->execute([':c' => $code, ':t' => $typeId, ':n' => $mark]);
    return ['id' => (int) $pdo->lastInsertId(), 'created' => true];
}

function demoStamp(): string
{
    return gmdate('Y-m-d H:i:s') . '.000';
}

function createStandardDemo(PDO $pdo, int $roomId, string $mark): array
{
    $created = [];

    // Estancia demo abierta, con QR y deuda pendiente.
    $stmt = $pdo->prepare(
        "SELECT id FROM stays WHERE room_id = :r AND vb6_codhab_raw = :m AND status IN ('RESERVED','OCCUPIED','OVERSTAY') LIMIT 1"
    );
    $stmt->execute([':r' => $roomId, ':m' => $mark]);
    $stayId = $stmt->fetchColumn();
    if ($stayId === false) {
        $stmt = $pdo->prepare(
            "INSERT INTO stays (room_id, status, duracion_minutos, reserved_at, first_entry_at, vb6_codhab_raw)
             VALUES (:r, 'OCCUPIED', 60, :at1, :at2, :m)"
        );
        $stmt->execute([':r' => $roomId, ':at1' => demoStamp(), ':at2' => demoStamp(), ':m' => $mark]);
        $stayId = (int) $pdo->lastInsertId();
        $created[] = "stay#{$stayId}";
    }
    $stayId = (int) $stayId;

    $stmt = $pdo->prepare('SELECT id FROM qr_credentials WHERE stay_id = :s LIMIT 1');
    $stmt->execute([':s' => $stayId]);
    if ($stmt->fetchColumn() === false) {
        $jti = sprintf('demo-%04d-%s', $stayId, bin2hex(random_bytes(6)));
        $stmt = $pdo->prepare(
            "INSERT INTO qr_credentials (stay_id, room_id, jti, token_hash, issued_at, expires_at, first_used_at, valid_until)
             VALUES (:s, :r, :j, :h, :issued, :exp, :used, :valid)"
        );
        $stmt->execute([
            ':s' => $stayId, ':r' => $roomId, ':j' => $jti,
            ':h' => hash('sha256', $jti), ':issued' => demoStamp(),
            ':exp' => gmdate('Y-m-d H:i:s', time() + 3600) . '.000',
            ':used' => demoStamp(),
            ':valid' => gmdate('Y-m-d H:i:s', time() + 1800) . '.000',
        ]);
        $created[] = 'qr';
    }

    $stmt = $pdo->prepare('SELECT id FROM access_events WHERE stay_id = :s AND reason = :m LIMIT 1');
    $stmt->execute([':s' => $stayId, ':m' => $mark]);
    if ($stmt->fetchColumn() === false) {
        $stmt = $pdo->prepare(
            "INSERT INTO access_events (room_id, stay_id, kind, result, reason, provider, correlation_id, occurred_at)
             VALUES (:r, :s, 'QR_VALIDATE', 'OK', :m, 'SIMULATED', :c, :at)"
        );
        $stmt->execute([
            ':r' => $roomId, ':s' => $stayId, ':m' => $mark,
            ':c' => 'DEMO120' . bin2hex(random_bytes(9)),
            ':at' => demoStamp(),
        ]);
        $created[] = 'access';
    }

    $stmt = $pdo->prepare('SELECT id FROM debts WHERE stay_id = :s LIMIT 1');
    $stmt->execute([':s' => $stayId]);
    if ($stmt->fetchColumn() === false) {
        $stmt = $pdo->prepare(
            "INSERT INTO debts (stay_id, room_id, exceso_minutos, amount_eur_snapshot, status, attempts, vb6_ack_ref)
             VALUES (:s, :r, 25, 6, 'PENDING_SYNC', 0, :m)"
        );
        $stmt->execute([':s' => $stayId, ':r' => $roomId, ':m' => $mark]);
        $created[] = 'debt';
    }

    $stmt = $pdo->prepare('SELECT id FROM anomalies WHERE room_id = :r AND context_data LIKE :m LIMIT 1');
    $stmt->execute([':r' => $roomId, ':m' => '%' . $mark . '%']);
    if ($stmt->fetchColumn() === false) {
        $stmt = $pdo->prepare(
            "INSERT INTO anomalies (room_id, stay_id, anomaly_type, severity, status, context_data, detected_at)
             VALUES (:r, :s, 'A2', 'HIGH', 'OPEN', :ctx, :at)"
        );
        $stmt->execute([
            ':r' => $roomId, ':s' => $stayId,
            ':ctx' => json_encode(['demo_fixture' => $mark, 'note' => 'Presencia sin estancia (demo)'], JSON_UNESCAPED_UNICODE),
            ':at' => demoStamp(),
        ]);
        $created[] = 'anomaly';
    }

    return $created;
}

function createWarehouseDemo(PDO $pdo, int $roomId, string $mark): array
{
    $created = [];

    $stmt = $pdo->prepare("SELECT id FROM workers WHERE notes = :m LIMIT 1");
    $stmt->execute([':m' => $mark]);
    $workerId = $stmt->fetchColumn();
    if ($workerId === false) {
        $roleId = (int) ($pdo->query('SELECT id FROM worker_roles ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
        if ($roleId <= 0) {
            throw new RuntimeException('No hay worker_roles para el fixture demo');
        }
        $demoJti = 'demo-' . bin2hex(random_bytes(12));
        $stmt = $pdo->prepare(
            'INSERT INTO workers (name, role_id, qr_token_hash, qr_jti, active, notes)
             VALUES (:n, :role, :hash, :jti, 1, :m)'
        );
        $stmt->execute([
            ':n' => 'DEMO F120 Empleado', ':role' => $roleId,
            ':hash' => hash('sha256', $demoJti), ':jti' => $demoJti, ':m' => $mark,
        ]);
        $workerId = (int) $pdo->lastInsertId();
        $created[] = "worker#{$workerId}";
    }
    $workerId = (int) $workerId;

    $stmt = $pdo->prepare('SELECT id FROM worker_sessions WHERE worker_id = :w AND room_id = :r LIMIT 1');
    $stmt->execute([':w' => $workerId, ':r' => $roomId]);
    $sessionId = $stmt->fetchColumn();
    if ($sessionId === false) {
        $stmt = $pdo->prepare(
            "INSERT INTO worker_sessions (worker_id, room_id, entered_at, exited_at, exit_kind, correlation_id)
             VALUES (:w, :r, :at, :out, 'AUTO', :c)"
        );
        $stmt->execute([
            ':w' => $workerId, ':r' => $roomId,
            ':at' => gmdate('Y-m-d H:i:s', time() - 1800) . '.000',
            ':out' => gmdate('Y-m-d H:i:s', time() - 1200) . '.000',
            ':c' => 'DEMO120worker' . bin2hex(random_bytes(6)),
        ]);
        $sessionId = (int) $pdo->lastInsertId();
        $created[] = "session#{$sessionId}";
    }
    $sessionId = (int) $sessionId;

    $stmt = $pdo->prepare('SELECT id FROM access_events WHERE worker_session_id = :s AND reason = :m LIMIT 1');
    $stmt->execute([':s' => $sessionId, ':m' => $mark]);
    if ($stmt->fetchColumn() === false) {
        $stmt = $pdo->prepare(
            "INSERT INTO access_events (room_id, kind, result, reason, provider, correlation_id, worker_session_id, occurred_at)
             VALUES (:r, 'QR_VALIDATE', 'OK', :m, 'SIMULATED', :c, :s, :at)"
        );
        $stmt->execute([
            ':r' => $roomId, ':m' => $mark,
            ':c' => 'DEMO120' . bin2hex(random_bytes(9)),
            ':s' => $sessionId,
            ':at' => gmdate('Y-m-d H:i:s', time() - 1800) . '.000',
        ]);
        $created[] = 'worker_access';
    }

    $stmt = $pdo->prepare('SELECT id FROM warehouse_visits WHERE room_id = :r AND denied_reason = :m LIMIT 1');
    $stmt->execute([':r' => $roomId, ':m' => $mark]);
    $visitId = $stmt->fetchColumn();
    if ($visitId === false) {
        $stmt = $pdo->prepare(
            "INSERT INTO warehouse_visits
                (room_id, worker_id, worker_session_id, entry_trigger, outcome, denied_reason, qr_at, entered_at, exited_at, created_at)
             VALUES (:r, :w, :s, 'QR', 'ENTERED', :m, :qr1, :in, :out, :qr2)"
        );
        $stmt->execute([
            ':r' => $roomId, ':w' => $workerId, ':s' => $sessionId, ':m' => $mark,
            ':qr1' => gmdate('Y-m-d H:i:s', time() - 1800) . '.000',
            ':qr2' => gmdate('Y-m-d H:i:s', time() - 1800) . '.000',
            ':in' => gmdate('Y-m-d H:i:s', time() - 1790) . '.000',
            ':out' => gmdate('Y-m-d H:i:s', time() - 1200) . '.000',
        ]);
        $visitId = (int) $pdo->lastInsertId();
        $created[] = "visit#{$visitId}";
    }
    $visitId = (int) $visitId;

    // No hay cámara real asociada al fixture: se registra una fila sin file_path
    // solo si existe algún dispositivo CAMERA en la BD, para poder ver el estado.
    $cameraId = $pdo->query("SELECT id FROM devices WHERE kind = 'CAMERA' ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($cameraId !== false) {
        $stmt = $pdo->prepare('SELECT id FROM camera_recordings WHERE visit_id = :v LIMIT 1');
        $stmt->execute([':v' => $visitId]);
        if ($stmt->fetchColumn() === false) {
            $stmt = $pdo->prepare(
                "INSERT INTO camera_recordings
                    (visit_id, room_id, device_id, position, episode, `trigger`, status,
                     requested_at, stopped_at, duration_s, size_bytes, error)
                 VALUES (:v, :r, :d, 'INTERIOR', 'ENTRY', 'QR', 'DISCARDED', :at1, :at2, 45, 0, :m)"
            );
            $stmt->execute([
                ':v' => $visitId, ':r' => $roomId, ':d' => (int) $cameraId,
                ':at1' => demoStamp(), ':at2' => demoStamp(), ':m' => $mark,
            ]);
            $created[] = 'recording';
        }
    }

    return $created;
}

function createTimbradoDemo(PDO $pdo, int $roomId, string $mark): array
{
    $stmt = $pdo->prepare('SELECT id FROM access_events WHERE room_id = :r AND reason = :m LIMIT 1');
    $stmt->execute([':r' => $roomId, ':m' => $mark]);
    if ($stmt->fetchColumn() !== false) {
        return [];
    }
    $stmt = $pdo->prepare(
        "INSERT INTO access_events (room_id, kind, result, reason, provider, correlation_id, occurred_at)
         VALUES (:r, 'OPEN', 'OK', :m, 'SIMULATED', :c, :at)"
    );
    $stmt->execute([
        ':r' => $roomId, ':m' => $mark,
        ':c' => 'DEMO120' . bin2hex(random_bytes(9)),
        ':at' => demoStamp(),
    ]);
    return ['access'];
}

try {
    $pdo->beginTransaction();

    if ($mode === '--create') {
        $created = [];
        $skipped = [];

        $standard = upsertRoom($pdo, 'DEMO-STANDARD', roomTypeId($pdo, 'STANDARD'), $MARK);
        $standard['created'] ? $created[] = 'room DEMO-STANDARD' : $skipped[] = 'DEMO-STANDARD';
        foreach (createStandardDemo($pdo, $standard['id'], $MARK) as $x) { $created[] = "{$x} (DEMO-STANDARD)"; }

        $almacen = upsertRoom($pdo, 'DEMO-ALMACEN', roomTypeId($pdo, 'ALMACEN_BEBIDAS'), $MARK);
        $almacen['created'] ? $created[] = 'room DEMO-ALMACEN' : $skipped[] = 'DEMO-ALMACEN';
        foreach (createWarehouseDemo($pdo, $almacen['id'], $MARK) as $x) { $created[] = "{$x} (DEMO-ALMACEN)"; }

        $timbrado = upsertRoom($pdo, 'DEMO-TIMBRADO', roomTypeId($pdo, 'TIMBRADO'), $MARK);
        $timbrado['created'] ? $created[] = 'room DEMO-TIMBRADO' : $skipped[] = 'DEMO-TIMBRADO';
        foreach (createTimbradoDemo($pdo, $timbrado['id'], $MARK) as $x) { $created[] = "{$x} (DEMO-TIMBRADO)"; }

        $pdo->commit();
        echo json_encode(['ok' => true, 'mode' => 'create', 'created' => $created, 'skipped' => $skipped], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);
    }

    // Purge: solo filas y salas marcadas.
    $roomStmt = $pdo->prepare('SELECT id FROM rooms WHERE notes = :m AND code LIKE :c');
    $roomStmt->execute([':m' => $MARK, ':c' => 'DEMO-%']);
    $rooms = array_map('intval', $roomStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $purged = [];

    if ($rooms !== []) {
        $in = implode(',', array_fill(0, count($rooms), '?'));
        $purged['recordings'] = $pdo->prepare("DELETE FROM camera_recordings WHERE room_id IN ($in)")->execute($rooms);
        $purged['visits'] = $pdo->prepare("DELETE FROM warehouse_visits WHERE room_id IN ($in)")->execute($rooms);
        $purged['anomalies'] = $pdo->prepare("DELETE FROM anomalies WHERE room_id IN ($in)")->execute($rooms);
        $purged['debts'] = $pdo->prepare("DELETE FROM debts WHERE room_id IN ($in)")->execute($rooms);
        $purged['qr'] = $pdo->prepare("DELETE FROM qr_credentials WHERE room_id IN ($in)")->execute($rooms);
        $purged['access'] = $pdo->prepare("DELETE FROM access_events WHERE room_id IN ($in)")->execute($rooms);
        $purged['sessions'] = $pdo->prepare("DELETE FROM worker_sessions WHERE room_id IN ($in)")->execute($rooms);
        $purged['iot_sessions'] = $pdo->prepare("DELETE FROM iot_sessions WHERE room_id IN ($in)")->execute($rooms);
        $purged['warehouse_state'] = $pdo->prepare("DELETE FROM warehouse_state WHERE room_id IN ($in)")->execute($rooms);
        $purged['stays'] = $pdo->prepare("DELETE FROM stays WHERE room_id IN ($in)")->execute($rooms);
        $purged['rooms'] = $pdo->prepare("DELETE FROM rooms WHERE id IN ($in)")->execute($rooms);
    }

    $workerIds = $pdo->prepare('SELECT id FROM workers WHERE notes = :m');
    $workerIds->execute([':m' => $MARK]);
    $wid = array_map('intval', $workerIds->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($wid !== []) {
        $win = implode(',', array_fill(0, count($wid), '?'));
        $purged['worker_sessions'] = $pdo->prepare("DELETE FROM worker_sessions WHERE worker_id IN ($win)")->execute($wid);
    }
    $purged['workers'] = $pdo->prepare('DELETE FROM workers WHERE notes = :m')->execute([':m' => $MARK]);
    $pdo->commit();
    echo json_encode(['ok' => true, 'mode' => 'purge', 'rooms' => $rooms, 'purged' => array_keys($purged)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[panel-demo-fixture] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
