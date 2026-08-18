<?php
declare(strict_types=1);

/**
 * bin/dev-create-stay.php
 *
 * Dev helper: inserts a RESERVED stay directly in the DB so we can exercise
 * /stays endpoints before QR issuance is wired (TSK-061 onwards).
 *
 * Usage:
 *   php bin/dev-create-stay.php <room_id> [duracion_minutos=60] [codtic=0]
 *
 * Prints the created stay id.
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Support\Config;
use App\Infrastructure\Db\PdoFactory;
use App\Domain\Stays\StayRepository;

Config::load(__DIR__ . '/../.env');

$argv = $_SERVER['argv'] ?? [];
$roomId = isset($argv[1]) ? (int) $argv[1] : 0;
$dur    = isset($argv[2]) ? (int) $argv[2] : 60;
$codtic = isset($argv[3]) ? (int) $argv[3] : 0;

if ($roomId <= 0) {
    fwrite(STDERR, "usage: php bin/dev-create-stay.php <room_id> [dur=60] [codtic=0]\n");
    exit(2);
}

$pdo = PdoFactory::make();
$repo = new StayRepository($pdo);
$refs = $codtic > 0 ? ['codtic' => $codtic, 'temporada' => date('Y')] : [];
$id = $repo->insertReserved($roomId, $dur, $refs);

echo "created stay id={$id} room_id={$roomId} dur={$dur} codtic={$codtic}\n";
