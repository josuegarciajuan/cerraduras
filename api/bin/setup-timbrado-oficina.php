<?php
declare(strict_types=1);

/**
 * bin/setup-timbrado-oficina.php — F116
 *
 * Crea (o reutiliza) la sala `oficina` de tipo TIMBRADO y le asigna el pack
 * `PACK_ZIGBEE`, desasignándolo de la sala donde esté ahora. No borra cámaras
 * ni dispositivos: solo mueve la asignación del pack.
 *
 * Uso:
 *   php bin/setup-timbrado-oficina.php --dry-run
 *   php bin/setup-timbrado-oficina.php --apply
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Domain\Devices\ApplyPackService;
use App\Domain\Devices\DevicePackRepository;
use App\Domain\Devices\DeviceRepository;
use App\Domain\Rooms\RoomRepository;
use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

const ROOM_CODE = 'oficina';
const ROOM_TYPE_CODE = 'TIMBRADO';
const PACK_CODE = 'PACK_ZIGBEE';

$apply = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif ($arg === '--dry-run' || $arg === '--help' || $arg === '-h') {
        if ($arg === '--help' || $arg === '-h') {
            echo "Uso: php bin/setup-timbrado-oficina.php [--dry-run|--apply]\n";
            exit(0);
        }
    } else {
        fwrite(STDERR, "[timbrado] argumento desconocido: {$arg}\n");
        exit(2);
    }
}

$pdo = PdoFactory::make();
$roomRepo = new RoomRepository($pdo);
$packRepo = new DevicePackRepository($pdo);
$deviceRepo = new DeviceRepository($pdo);
$applyPack = new ApplyPackService($packRepo, $deviceRepo, $roomRepo, $pdo);

$typeStmt = $pdo->prepare('SELECT id FROM room_types WHERE code = :c LIMIT 1');
$typeStmt->execute([':c' => ROOM_TYPE_CODE]);
$typeId = (int) ($typeStmt->fetchColumn() ?: 0);
if ($typeId <= 0) {
    fwrite(STDERR, "[timbrado] ERROR: falta el tipo " . ROOM_TYPE_CODE . "; ejecuta php bin/migrate.php\n");
    exit(1);
}

$packStmt = $pdo->prepare('SELECT id, name FROM device_packs WHERE code = :c LIMIT 1');
$packStmt->execute([':c' => PACK_CODE]);
$pack = $packStmt->fetch(PDO::FETCH_ASSOC) ?: null;
if ($pack === null) {
    fwrite(STDERR, "[timbrado] ERROR: no existe el pack " . PACK_CODE . "\n");
    exit(1);
}
$packId = (int) $pack['id'];

$currentStmt = $pdo->prepare(
    'SELECT r.id, r.code, r.room_type_id, r.pack_id, rt.code AS room_type_code
       FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id
      WHERE r.pack_id = :pid LIMIT 1'
);
$currentStmt->execute([':pid' => $packId]);
$current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$room = $roomRepo->findByCode(ROOM_CODE);
$roomId = $room !== null ? (int) $room->id : 0;

echo "[timbrado] tipo " . ROOM_TYPE_CODE . " = id {$typeId}\n";
echo "[timbrado] pack " . PACK_CODE . " = id {$packId} (" . (string) $pack['name'] . ")\n";
echo "[timbrado] sala destino " . ROOM_CODE . " = " . ($roomId > 0 ? "id {$roomId}" : 'crear') . "\n";
if ($current !== null) {
    echo "[timbrado] pack actualmente en sala " . (int) $current['id'] . " (" . (string) $current['code'] . ")\n";
} else {
    echo "[timbrado] pack sin sala asignada\n";
}

if (!$apply) {
    echo "[timbrado] --dry-run: no se escribe nada. Usa --apply para aplicar.\n";
    exit(0);
}

if ($roomId <= 0) {
    $roomId = $roomRepo->insert(ROOM_CODE, $typeId, null, 'Entrada de la oficina (timbre).');
    echo "[timbrado] sala creada id {$roomId}\n";
} elseif ((int) $room->roomTypeId !== $typeId) {
    $roomRepo->update($roomId, ['room_type_id' => $typeId, 'code' => ROOM_CODE]);
    echo "[timbrado] sala {$roomId} actualizada a tipo " . ROOM_TYPE_CODE . "\n";
}

$result = $applyPack->apply($roomId, $packId);
echo "[timbrado] pack asignado: " . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo "[timbrado] OK. La cámara EXTERIOR y el resto de dispositivos siguen en el pack; solo se mueve la asignación.\n";
