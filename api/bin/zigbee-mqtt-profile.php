<?php
declare(strict_types=1);

/**
 * zigbee-mqtt-profile.php — F125.1.2.
 *
 * Diagnóstico y asignación del perfil MQTT por dispositivo.
 *
 *   php bin/zigbee-mqtt-profile.php --device=1339 --json
 *   php bin/zigbee-mqtt-profile.php --device=1339 --set-profile=dev --dry-run
 *   php bin/zigbee-mqtt-profile.php --device=1339 --set-profile=dev --apply --confirm
 *   php bin/zigbee-mqtt-profile.php --device=1339 --clear --apply --confirm
 *
 * No publica MQTT ni toca el radar. Solo lee/escribe `devices.meta_json`.
 * El JSON de perfiles no contiene contraseñas; el diagnóstico nunca las imprime.
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Zigbee\ZigbeeMqttProfile;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

function usage(): void
{
    echo "Uso: php bin/zigbee-mqtt-profile.php --device=ID [--json] [--set-profile=NAME|--clear] [--dry-run] [--apply --confirm]\n";
}

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

$opts = getopt('', ['device:', 'json', 'set-profile:', 'clear', 'dry-run', 'apply', 'confirm', 'help']);
if (isset($opts['help']) || !isset($opts['device'])) {
    usage();
    exit(isset($opts['help']) ? 0 : 1);
}
$deviceId = (int) $opts['device'];
if ($deviceId <= 0) {
    fail('device inválido');
}
$json = isset($opts['json']);
$clear = isset($opts['clear']);
$setProfile = isset($opts['set-profile']) ? strtolower(trim((string) $opts['set-profile'])) : null;
$apply = isset($opts['apply']);
$dryRun = isset($opts['dry-run']);
$confirm = isset($opts['confirm']);

if ($clear && $setProfile !== null) {
    fail('--clear y --set-profile son excluyentes');
}
if (!$clear && $setProfile === null && ($apply || $dryRun)) {
    fail('indica --set-profile o --clear');
}

$pdo = PdoFactory::make();
$stmt = $pdo->prepare(
    'SELECT d.id, d.label, d.external_id, d.meta_json, COALESCE(r.code, \'\') AS room_code
       FROM devices d LEFT JOIN rooms r ON r.pack_id = d.pack_id
      WHERE d.id = :id LIMIT 1'
);
$stmt->execute([':id' => $deviceId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row === false) {
    fail('dispositivo no encontrado');
}
$meta = json_decode((string) ($row['meta_json'] ?? ''), true);
$meta = is_array($meta) ? $meta : [];
$currentProfile = $meta[ZigbeeMqttProfile::META_KEY] ?? null;

$targetMeta = $meta;
$action = 'describe';
if ($clear) {
    unset($targetMeta[ZigbeeMqttProfile::META_KEY]);
    $action = 'clear';
} elseif ($setProfile !== null) {
    if (!preg_match('/^[a-z0-9_-]{1,32}$/', $setProfile)) {
        fail('perfil inválido');
    }
    $targetMeta[ZigbeeMqttProfile::META_KEY] = $setProfile;
    $action = 'set';
}

// Valida el perfil objetivo antes de escribir.
try {
    ZigbeeMqttProfile::resolve($targetMeta);
    $description = ZigbeeMqttProfile::describe($targetMeta);
} catch (Throwable $e) {
    fail('perfil inválido: ' . $e->getMessage());
}

$payload = [
    'device' => (int) $row['id'],
    'room' => (string) ($row['room_code'] ?? ''),
    'label' => (string) ($row['label'] ?? ''),
    'external_id' => (string) ($row['external_id'] ?? ''),
    'current_profile' => $currentProfile,
    'target_profile' => $targetMeta[ZigbeeMqttProfile::META_KEY] ?? ZigbeeMqttProfile::DEFAULT,
    'action' => $action,
    'applied' => false,
    'dry_run' => $dryRun || (!$apply && $action !== 'describe'),
    'resolved' => $description,
];

if ($action === 'describe' || $dryRun || !$apply) {
    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo 'Dispositivo: ' . $payload['device'] . ' · ' . $payload['room'] . ' · ' . $payload['label'] . "\n";
        echo 'Perfil actual: ' . ($currentProfile ?? '(default)') . "\n";
        echo 'Perfil objetivo: ' . ($payload['target_profile'] ?? ZigbeeMqttProfile::DEFAULT) . "\n";
        echo 'Broker: ' . ($description['host'] ?? '?') . ':' . ($description['port'] ?? '?')
            . ' · usuario ' . ($description['username'] ?? '?') . "\n";
        echo 'Password file: ' . ($description['password_file'] ?? '—')
            . ' · ' . (!empty($description['password_file_exists']) ? 'existe' : 'NO existe') . "\n";
        if (($description['ok'] ?? false) !== true) {
            echo 'Error: ' . ($description['error'] ?? 'perfil inválido') . "\n";
        }
        if ($action !== 'describe') {
            echo "Modo dry-run: no se ha escrito nada. Usa --apply --confirm para aplicar.\n";
        }
    }
    exit(($description['ok'] ?? false) === true ? 0 : 1);
}

if (!$confirm && in_array($action, ['set', 'clear'], true)) {
    fail('para aplicar cambios usa --apply --confirm');
}

$backupDir = __DIR__ . '/../run';
if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    fail('no se pudo crear run/ para el backup');
}
$backup = $backupDir . '/zigbee-device-meta-backup-' . $deviceId . '-' . gmdate('Ymd\THis\Z') . '.json';
if (file_put_contents($backup, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
    fail('no se pudo guardar el backup del meta');
}
@chmod($backup, 0600);

$pdo->prepare('UPDATE devices SET meta_json = :meta WHERE id = :id')->execute([
    ':meta' => json_encode($targetMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ':id' => $deviceId,
]);

$payload['applied'] = true;
$payload['dry_run'] = false;
$payload['backup'] = $backup;
if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo 'Aplicado: dispositivo ' . $deviceId . ' → perfil ' . ($payload['target_profile'] ?? ZigbeeMqttProfile::DEFAULT) . "\n";
    echo 'Backup: ' . $backup . "\n";
}
exit(0);
