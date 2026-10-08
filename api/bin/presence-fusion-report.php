<?php
declare(strict_types=1);

/**
 * bin/presence-fusion-report.php — F88/RF-125.1: informe de la fusión de presencia.
 *
 * Solo lectura (BD local). Cruza los eventos de PRESENCE del almacén con el último
 * movimiento de cámara (`devices.last_motion_at`) para cuantificar fantasmas del
 * radar y validar los umbrales de F88. **No** llama a Tuya (cero cuota).
 *
 * Uso:
 *   php bin/presence-fusion-report.php [--minutes=60] [--room=12]
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$minutes = 60;
$roomId  = 0;
foreach ($argv ?? [] as $i => $arg) {
    if (preg_match('/^--minutes=(\d+)$/', (string) $arg, $m)) {
        $minutes = max(1, (int) $m[1]);
    } elseif ($arg === '--minutes' && isset($argv[$i + 1])) {
        $minutes = max(1, (int) $argv[$i + 1]);
    }
    if (preg_match('/^--room=(\d+)$/', (string) $arg, $m)) {
        $roomId = (int) $m[1];
    } elseif ($arg === '--room' && isset($argv[$i + 1])) {
        $roomId = (int) $argv[$i + 1];
    }
}

$pdo = PdoFactory::make();

if ($roomId <= 0) {
    $roomId = (int) ($pdo->query(
        "SELECT r.id FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
         WHERE rt.code = 'ALMACEN_BEBIDAS' ORDER BY r.id ASC LIMIT 1"
    )->fetchColumn() ?: 0);
}
if ($roomId <= 0) {
    fwrite(STDERR, "No hay sala ALMACEN_BEBIDAS.\n");
    exit(1);
}

echo "Presence fusion report — room={$roomId} ventana={$minutes} min (UTC)\n";
echo str_repeat('=', 64) . "\n\n";

// Cámaras y su último movimiento.
echo "Cámaras:\n";
$cams = $pdo->prepare(
    "SELECT d.id, d.subtype, d.label, d.last_motion_at
       FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
      WHERE r.id = :r AND d.kind='CAMERA' ORDER BY d.subtype"
);
$cams->execute([':r' => $roomId]);
foreach ($cams->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
    printf("  #%d %-8s %-20s last_motion_at=%s\n",
        (int) $c['id'], (string) $c['subtype'], (string) ($c['label'] ?? ''), (string) ($c['last_motion_at'] ?? '—'));
}
echo "\n";

// Resumen por valor/estado.
echo "Eventos PRESENCE (resumen):\n";
$sum = $pdo->prepare(
    "SELECT value, applied, COALESCE(discard_reason, '-') AS reason, COUNT(*) n
       FROM presence_events
      WHERE room_id = :r AND sensor = 'PRESENCE'
        AND occurred_at >= (UTC_TIMESTAMP(3) - INTERVAL :m MINUTE)
      GROUP BY value, applied, discard_reason
      ORDER BY value, applied, reason"
);
$sum->execute([':r' => $roomId, ':m' => $minutes]);
$total = 0;
foreach ($sum->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $total += (int) $row['n'];
    printf("  value=%-7s applied=%d reason=%-16s n=%d\n",
        (string) $row['value'], (int) $row['applied'], (string) $row['reason'], (int) $row['n']);
}
echo "  TOTAL: {$total}\n\n";

// Eventos de puerta.
echo "Eventos PROXIMITY aplicados:\n";
$door = $pdo->prepare(
    "SELECT value, occurred_at FROM presence_events
      WHERE room_id = :r AND sensor = 'PROXIMITY' AND applied = 1
        AND occurred_at >= (UTC_TIMESTAMP(3) - INTERVAL :m MINUTE)
      ORDER BY occurred_at DESC LIMIT 10"
);
$door->execute([':r' => $roomId, ':m' => $minutes]);
$doorRows = $door->fetchAll(PDO::FETCH_ASSOC) ?: [];
if ($doorRows === []) {
    echo "  (ninguno)\n";
} else {
    foreach ($doorRows as $d) {
        printf("  %-6s %s\n", (string) $d['value'], (string) $d['occurred_at']);
    }
}
echo "\n";

// Últimos eventos crudos.
echo "Últimos 20 eventos PRESENCE:\n";
$ev = $pdo->prepare(
    "SELECT occurred_at, value, applied, COALESCE(discard_reason, '-') AS reason,
            JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.tuya_raw_val')) AS raw
       FROM presence_events
      WHERE room_id = :r AND sensor = 'PRESENCE'
        AND occurred_at >= (UTC_TIMESTAMP(3) - INTERVAL :m MINUTE)
      ORDER BY occurred_at DESC LIMIT 20"
);
$ev->execute([':r' => $roomId, ':m' => $minutes]);
foreach ($ev->fetchAll(PDO::FETCH_ASSOC) ?: [] as $e) {
    printf("  %s %-7s raw=%-8s applied=%d reason=%s\n",
        (string) $e['occurred_at'], (string) $e['value'], (string) ($e['raw'] ?? '—'),
        (int) $e['applied'], (string) $e['reason']);
}

echo "\nFin.\n";
