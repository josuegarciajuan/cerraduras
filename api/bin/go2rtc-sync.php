<?php
declare(strict_types=1);

/**
 * bin/go2rtc-sync.php — register warehouse camera streams in go2rtc (F61/RF-72.3).
 *
 * Reads CAMERA devices (kind=CAMERA, enabled) and calls PUT /api/streams.
 * RTSP URLs are never logged. Safe to run at boot and after camera changes.
 *
 * Usage: php bin/go2rtc-sync.php
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Domain\Warehouse\Go2rtcClient;
use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$base = (string) (Config::get('GO2RTC_BASE_URL', '') ?? '');
$client = new Go2rtcClient($base);
if (!$client->enabled()) {
    fwrite(STDOUT, "[go2rtc-sync] GO2RTC_BASE_URL no configurada; nada que hacer\n");
    exit(0);
}

$pdo = PdoFactory::make();
$n = 0;
$stmt = $pdo->query(
    "SELECT r.id AS room_id, d.subtype, d.meta_json
     FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
     WHERE d.kind='CAMERA' AND r.pack_id IS NOT NULL"
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $meta = json_decode((string) ($row['meta_json'] ?? ''), true) ?: [];
    $url = is_string($meta['rtsp_url'] ?? null) ? $meta['rtsp_url'] : '';
    if ($url === '' || (($meta['enabled'] ?? true) === false)) {
        continue;
    }
    $name = Go2rtcClient::streamName((int) $row['room_id'], (string) $row['subtype']);
    if ($client->upsert($name, $url)) {
        $n++;
    }
}
fwrite(STDOUT, "[go2rtc-sync] {$n} stream(s) sincronizados\n");
exit(0);
