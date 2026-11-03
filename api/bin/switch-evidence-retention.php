<?php
declare(strict_types=1);

/**
 * F112/RF-265 — retención de switch_command_evidence.
 *
 * Borra evidencia antigua de órdenes de luz. `days=0` desactiva la purga.
 * No toca devices, corridas ni telemetría.
 *
 * Uso:
 *   php bin/switch-evidence-retention.php --days=30 --dry-run
 *   php bin/switch-evidence-retention.php            # usa SWITCH_EVIDENCE_RETENTION_DAYS o 30
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Domain\Sensors\SwitchCommandEvidenceRepository;
use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

$opts = getopt('', ['days::', 'batch::', 'dry-run']);
$envDays = getenv('SWITCH_EVIDENCE_RETENTION_DAYS');
$defaultDays = ($envDays === false || trim((string) $envDays) === '') ? 30 : (int) $envDays;
$days = isset($opts['days']) ? (int) $opts['days'] : $defaultDays;
$batch = isset($opts['batch']) ? (int) $opts['batch'] : 1000;
$batch = max(100, min(50000, $batch));
$dryRun = array_key_exists('dry-run', $opts);

Config::load(dirname(__DIR__) . '/.env');
$pdo = PdoFactory::make();

if ($days <= 0) {
    echo "[switch-evidence-retention] retención desactivada (days={$days}); no se borra nada\n";
    exit(0);
}
$days = min(3650, $days);
$cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->modify('-' . $days . ' days')
    ->format('Y-m-d H:i:s.v');

$stmt = $pdo->prepare('SELECT COUNT(*) FROM switch_command_evidence WHERE requested_at < :cutoff');
$stmt->execute([':cutoff' => $cutoff]);
$old = (int) $stmt->fetchColumn();
$total = (int) $pdo->query('SELECT COUNT(*) FROM switch_command_evidence')->fetchColumn();

echo "[switch-evidence-retention] days={$days} total={$total} candidatos={$old} cutoff={$cutoff}\n";
if ($dryRun) {
    echo "[switch-evidence-retention] dry-run: no se borra nada\n";
    exit(0);
}

$deleted = (new SwitchCommandEvidenceRepository($pdo))->purgeBefore($cutoff, $batch);
echo "[switch-evidence-retention] borradas={$deleted}\n";
exit(0);
