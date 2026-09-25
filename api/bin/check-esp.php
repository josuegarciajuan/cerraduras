<?php
declare(strict_types=1);

/**
 * bin/check-esp.php
 *
 * Comprobación FIEL en tiempo real del estado de un ESP32 (chip que emite
 * /dashboard-api/device-heartbeat cada ~30 s).
 *
 * Dos modos:
 *   - Pasivo (por defecto): mide la antigüedad de devices.last_seen_at con un
 *     umbral ajustado (3x heartbeat). Refleja el latido real del chip.
 *   - Activo (--probe): encola un comando `check` en la command-queue y espera
 *     a que el chip lo recoja y responda (command-result). Prueba concluyente:
 *     si el chip está apagado, el comando se queda `pending` y expira.
 *
 * Uso:
 *   php bin/check-esp.php                       # latido de "prueba2" (una vez)
 *   php bin/check-esp.php --watch               # refresco continuo en vivo
 *   php bin/check-esp.php --probe               # reto activo (challenge/response)
 *   php bin/check-esp.php a0e549858428          # por external_id (12 hex)
 *   php bin/check-esp.php --id=224              # por id de BD
 *
 * Opciones:
 *   --watch             Refresco continuo (pasivo).
 *   --interval=N        Segundos entre refrescos en --watch (def. 10).
 *   --threshold=N       Segundos de frescura para considerar online (def. 90).
 *   --probe             Reto activo por command-queue.
 *   --timeout=N         Segundos máximos de espera del reto (def. 60).
 *   --json              Salida legible por máquina.
 *   --id=N              Selecciona el dispositivo por id (override).
 *   --help, -h          Ayuda.
 *
 * Códigos de salida: 0 online · 1 offline · 2 unknown/error · 3 no encontrado.
 *
 * Nota: devices.last_seen_at se guarda en UTC. Se compara SIEMPRE con
 * UTC_TIMESTAMP(3), nunca con NOW() (MySQL local puede ir en otra zona).
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Domain\Devices\CommandQueueRepository;
use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

const EXIT_ONLINE    = 0;
const EXIT_OFFLINE   = 1;
const EXIT_UNKNOWN   = 2;
const EXIT_NOT_FOUND = 3;

const HEARTBEAT_SECONDS = 30; // cadencia del firmware (device-heartbeat)

/** @return array<string,mixed> */
function parseArgs(array $argv): array
{
    $opts = [
        'target'    => null,
        'id'        => null,
        'watch'     => false,
        'interval'  => 10,
        'threshold' => HEARTBEAT_SECONDS * 3,
        'probe'     => false,
        'timeout'   => 60,
        'json'      => false,
        'help'      => false,
    ];

    $assoc = ['--interval' => 'interval', '--threshold' => 'threshold', '--timeout' => 'timeout', '--id' => 'id'];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string) $argv[$i];
        if ($arg === '--watch' || $arg === '-w') {
            $opts['watch'] = true;
            continue;
        }
        if ($arg === '--probe') {
            $opts['probe'] = true;
            continue;
        }
        if ($arg === '--json') {
            $opts['json'] = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            $opts['help'] = true;
            continue;
        }
        $matched = false;
        foreach ($assoc as $flag => $key) {
            if (str_starts_with($arg, $flag . '=')) {
                $opts[$key] = $arg;
                $opts[$key] = (int) substr($arg, strlen($flag) + 1);
                $matched = true;
                break;
            }
        }
        if ($matched) {
            continue;
        }
        if (str_starts_with($arg, '-')) {
            fwrite(STDERR, "[check-esp] Opción desconocida: {$arg}\n");
            exit(EXIT_UNKNOWN);
        }
        $opts['target'] = $arg;
    }

    return $opts;
}

function usage(): void
{
    echo <<<TXT
    check-esp.php — estado fiel en tiempo real de un ESP32 (heartbeat + command-queue)

    Uso:
      php bin/check-esp.php [objetivo] [opciones]

    [objetivo]  label del dispositivo (def. "prueba2") o external_id de 12 hex.

    Opciones:
      --watch           Refresco continuo (modo pasivo).
      --interval=N      Segundos entre refrescos en --watch (def. 10).
      --threshold=N     Segundos de frescura para "online" (def. 90).
      --probe           Reto activo: encola `check` y espera respuesta del chip.
      --timeout=N       Segundos máximos de espera del reto (def. 60).
      --id=N            Selecciona el dispositivo por id de BD.
      --json            Salida JSON.
      -h, --help        Esta ayuda.

    Códigos de salida: 0 online · 1 offline · 2 unknown/error · 3 no encontrado.

    TXT;
    echo "\n";
}

/**
 * Resuelve el dispositivo objetivo (preferentemente la fila RPI = dueño del chip).
 *
 * @return array<string,mixed>|null Fila con id, label, external_id, kind, pack_id, last_seen_at, age_s.
 */
function resolveDevice(PDO $pdo, array $opts): ?array
{
    $cols = 'd.id, d.label, d.external_id, d.kind, d.pack_id, d.last_seen_at,
             TIMESTAMPDIFF(SECOND, d.last_seen_at, UTC_TIMESTAMP(3)) AS age_s';

    if ($opts['id'] !== null) {
        $stmt = $pdo->prepare("SELECT {$cols} FROM devices d WHERE d.id = :id LIMIT 1");
        $stmt->execute([':id' => (int) $opts['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    $target = $opts['target'] ?? 'prueba2';
    if ($target === null || $target === '') {
        $target = 'prueba2';
    }

    // external_id: 12 caracteres hexadecimales (MAC del ESP32).
    if (preg_match('/^[0-9a-f]{12}$/i', $target) === 1) {
        $stmt = $pdo->prepare(
            "SELECT {$cols} FROM devices d WHERE d.external_id = :x
             ORDER BY (d.kind = 'RPI') DESC, d.id ASC LIMIT 1"
        );
        $stmt->execute([':x' => strtolower($target)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    // label: preferir RPI (el chip dueño es quien bebe el heartbeat).
    $stmt = $pdo->prepare(
        "SELECT {$cols} FROM devices d WHERE d.label = :l
         ORDER BY (d.kind = 'RPI') DESC, d.id ASC LIMIT 1"
    );
    $stmt->execute([':l' => $target]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * @return list<array<string,mixed>> candidatos con el mismo label/external_id
 */
function findCandidates(PDO $pdo, array $opts): array
{
    if ($opts['id'] !== null) {
        return [];
    }
    $target = $opts['target'] ?? 'prueba2';
    if ($target === null || $target === '') {
        $target = 'prueba2';
    }

    if (preg_match('/^[0-9a-f]{12}$/i', (string) $target) === 1) {
        $stmt = $pdo->prepare(
            'SELECT id, label, external_id, kind FROM devices WHERE external_id = :x ORDER BY id'
        );
        $stmt->execute([':x' => strtolower((string) $target)]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, label, external_id, kind FROM devices WHERE label = :l ORDER BY id'
        );
        $stmt->execute([':l' => (string) $target]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function stateFromAge(?string $lastSeen, ?int $age, int $threshold): array
{
    if ($lastSeen === null) {
        return ['state' => 'unknown', 'online' => null, 'reason' => 'sin heartbeat registrado'];
    }
    if ($age === null) {
        return ['state' => 'unknown', 'online' => null, 'reason' => 'edad no calculable'];
    }
    if ($age <= $threshold) {
        return ['state' => 'online', 'online' => true, 'reason' => "latido hace {$age}s (umbral {$threshold}s)"];
    }
    return ['state' => 'offline', 'online' => false, 'reason' => "sin latido desde hace {$age}s (umbral {$threshold}s)"];
}

function colorize(string $state): string
{
    $isTty = function_exists('posix_isatty') ? @posix_isatty(STDOUT) : false;
    if (!$isTty) {
        return strtoupper($state);
    }
    $map = [
        'online'  => "\033[32mONLINE\033[0m",
        'offline' => "\033[31mOFFLINE\033[0m",
        'unknown' => "\033[33mUNKNOWN\033[0m",
    ];
    return $map[$state] ?? strtoupper($state);
}

function utcToLocal(?string $utc): string
{
    if ($utc === null) {
        return '-';
    }
    // PHP puede recibir milisegundos ("Y-m-d H:i:s.mmm"): strtotime no los
    // soporta de forma fiable, así que los recortamos antes de convertir.
    $clean = preg_replace('/\.\d+$/', '', trim($utc));
    $ts = strtotime($clean . ' UTC');
    if ($ts === false) {
        return $utc;
    }
    $utcStr = gmdate('Y-m-d H:i:s', $ts);
    return $utcStr . ' UTC (' . date('H:i:s', $ts) . ' local)';
}

/**
 * Reto activo: encola un `check` y espera el command-result.
 *
 * @return array{online:bool,state:string,reason:string,command_id:?int,result:?array<string,mixed>,picked_up:bool}
 */
function runProbe(PDO $pdo, CommandQueueRepository $queue, string $chipId, int $timeoutSeconds): array
{
    $commandId = $queue->enqueue($chipId, 'check');
    if ($commandId === null) {
        return [
            'online' => false, 'state' => 'unknown',
            'reason' => 'no se pudo encolar el comando check',
            'command_id' => null, 'result' => null, 'picked_up' => false,
        ];
    }

    $start = time();
    $pickedUp = false;
    $sel = $pdo->prepare(
        'SELECT status, result_json, TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP(3)) AS age_s
         FROM device_commands WHERE id = :id LIMIT 1'
    );

    while (true) {
        $sel->execute([':id' => $commandId]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [
                'online' => false, 'state' => 'unknown',
                'reason' => "comando #{$commandId} desapareció",
                'command_id' => $commandId, 'result' => null, 'picked_up' => $pickedUp,
            ];
        }

        $status = (string) $row['status'];
        $age = (int) $row['age_s'];

        if ($status === 'done') {
            $result = $row['result_json'] !== null ? json_decode((string) $row['result_json'], true) : null;
            return [
                'online' => true, 'state' => 'online',
                'reason' => "comando #{$commandId} respondido en {$age}s",
                'command_id' => $commandId,
                'result' => is_array($result) ? $result : null,
                'picked_up' => true,
            ];
        }
        if ($status === 'picked_up') {
            $pickedUp = true;
        }
        if ($status === 'timeout') {
            return [
                'online' => false, 'state' => 'offline',
                'reason' => "comando #{$commandId} expiró (chip no respondió tras {$age}s)",
                'command_id' => $commandId, 'result' => null, 'picked_up' => $pickedUp,
            ];
        }
        if ((time() - $start) >= $timeoutSeconds) {
            return [
                'online' => false, 'state' => 'offline',
                'reason' => $pickedUp
                    ? "chip recogió el comando #{$commandId} pero no respondió en {$timeoutSeconds}s"
                    : "command #{$commandId} sigue pending tras {$timeoutSeconds}s (chip no está sondeando)",
                'command_id' => $commandId, 'result' => null, 'picked_up' => $pickedUp,
            ];
        }

        usleep(2_000_000);
    }
}

function reportPassive(array $dev, array $opts, array $state): void
{
    if ($opts['json']) {
        echo json_encode([
            'mode'        => $opts['watch'] ? 'watch' : 'passive',
            'id'          => (int) $dev['id'],
            'label'       => $dev['label'],
            'external_id' => $dev['external_id'],
            'kind'        => $dev['kind'],
            'pack_id'     => $dev['pack_id'] !== null ? (int) $dev['pack_id'] : null,
            'last_seen_at' => $dev['last_seen_at'],
            'age_seconds' => $dev['age_s'] !== null ? (int) $dev['age_s'] : null,
            'threshold'   => $opts['threshold'],
            'state'       => $state['state'],
            'online'      => $state['online'],
            'reason'      => $state['reason'],
            'ts'          => date('c'),
        ], JSON_UNESCAPED_UNICODE) . "\n";
        return;
    }

    $line = sprintf(
        '[%s] %s (%s · %s) — %s | último latido: %s | edad: %s',
        date('H:i:s'),
        $dev['label'] ?? '(sin label)',
        $dev['external_id'],
        $dev['kind'],
        colorize($state['state']),
        utcToLocal($dev['last_seen_at'] !== null ? (string) $dev['last_seen_at'] : null),
        $dev['age_s'] !== null ? ((int) $dev['age_s']) . 's' : '-'
    );

    if ($opts['watch']) {
        echo "\r\033[K" . $line;
    } else {
        echo $line . "\n";
        echo "  → " . $state['reason'] . "\n";
    }
}

function reportProbe(array $dev, array $opts, array $probe): void
{
    if ($opts['json']) {
        echo json_encode([
            'mode'        => 'probe',
            'id'          => (int) $dev['id'],
            'label'       => $dev['label'],
            'external_id' => $dev['external_id'],
            'kind'        => $dev['kind'],
            'command_id'  => $probe['command_id'],
            'picked_up'   => $probe['picked_up'],
            'state'       => $probe['state'],
            'online'      => $probe['online'],
            'reason'      => $probe['reason'],
            'result'      => $probe['result'],
            'ts'          => date('c'),
        ], JSON_UNESCAPED_UNICODE) . "\n";
        return;
    }

    echo sprintf(
        "[reto] %s (%s · %s) — %s\n",
        $dev['label'] ?? '(sin label)',
        $dev['external_id'],
        $dev['kind'],
        colorize($probe['state'])
    );
    echo '  → ' . $probe['reason'] . "\n";
    if ($probe['result'] !== null) {
        $parts = [];
        foreach ($probe['result'] as $k => $v) {
            $parts[] = $k . '=' . ($v ? 'ok' : 'offline');
        }
        echo '  → sub-dispositivos: ' . implode(' ', $parts) . "\n";
    }
}

// ── Main ────────────────────────────────────────────────────────────────────
$opts = parseArgs($argv);

if ($opts['help']) {
    usage();
    exit(EXIT_ONLINE);
}

if ($opts['threshold'] <= 0 || $opts['interval'] <= 0 || $opts['timeout'] <= 0) {
    fwrite(STDERR, "[check-esp] interval/threshold/timeout deben ser > 0\n");
    exit(EXIT_UNKNOWN);
}

try {
    $pdo = PdoFactory::make();
} catch (\Throwable $e) {
    fwrite(STDERR, '[check-esp] Error de conexión a BD: ' . $e->getMessage() . "\n");
    exit(EXIT_UNKNOWN);
}

$device = resolveDevice($pdo, $opts);
if ($device === null) {
    $target = $opts['id'] !== null ? ('id=' . $opts['id']) : ('"' . ($opts['target'] ?? 'prueba2') . '"');
    fwrite(STDERR, "[check-esp] No encontrado: {$target}\n");
    $candidates = findCandidates($pdo, $opts);
    if ($candidates !== []) {
        fwrite(STDERR, "[check-esp] Varias filas coinciden; usa --id=N:\n");
        foreach ($candidates as $c) {
            fwrite(STDERR, sprintf("    --id=%d  %s  %s  %s\n", $c['id'], $c['external_id'], $c['kind'], $c['label'] ?? '-'));
        }
    }
    exit(EXIT_NOT_FOUND);
}

$chipId = (string) $device['external_id'];

// Modo reto activo.
if ($opts['probe']) {
    $queue = new CommandQueueRepository($pdo);
    if (!$opts['json']) {
        echo 'Encolando `check` para ' . ($device['label'] ?? $chipId) . " (chip {$chipId})...\n";
    }
    $probe = runProbe($pdo, $queue, $chipId, (int) $opts['timeout']);
    reportProbe($device, $opts, $probe);
    exit($probe['online'] ? EXIT_ONLINE : EXIT_OFFLINE);
}

// Modo pasivo.
if (!$opts['watch']) {
    $state = stateFromAge(
        $device['last_seen_at'] !== null ? (string) $device['last_seen_at'] : null,
        $device['age_s'] !== null ? (int) $device['age_s'] : null,
        (int) $opts['threshold']
    );
    reportPassive($device, $opts, $state);
    exit($state['online'] === true ? EXIT_ONLINE : ($state['online'] === false ? EXIT_OFFLINE : EXIT_UNKNOWN));
}

// Watch: refresco continuo (re-resuelve por si cambia el id/label, pero mantiene el dispositivo).
$lastState = null;
while (true) {
    $fresh = resolveDevice($pdo, ['id' => $device['id']] + $opts);
    if ($fresh === null) {
        fwrite(STDERR, "\n[check-esp] El dispositivo desapareció de la BD.\n");
        exit(EXIT_NOT_FOUND);
    }
    $state = stateFromAge(
        $fresh['last_seen_at'] !== null ? (string) $fresh['last_seen_at'] : null,
        $fresh['age_s'] !== null ? (int) $fresh['age_s'] : null,
        (int) $opts['threshold']
    );
    reportPassive($fresh, $opts, $state);
    $lastState = $state['state'];

    sleep((int) $opts['interval']);
}
