<?php
declare(strict_types=1);

/**
 * bin/sensor-capture.php — Captura reproducible de sensores (F93/RF-158).
 *
 * Objetivo: medir puerta y presencia con una línea temporal única sin sondear
 * Tuya. Lee SOLO la BD local (eventos que ya llegaron por push) y escribe JSONL
 * con la señal, la decisión radar auditada, los ajustes del radar y las marcas
 * físicas del técnico.
 *
 * Uso:
 *   # captura en primer plano (Ctrl-C para terminar antes)
 *   php bin/sensor-capture.php --room=14 --duration=600
 *
 *   # marca física desde otra terminal
 *   php bin/sensor-capture.php --mark=PUERTA_ABRE
 *
 *   # analizar una captura con una configuración candidata (sin efectos)
 *   php bin/sensor-capture.php --analyze=run/captures/14-....jsonl --mode=radar_only
 *
 * No escribe en el dominio, no publica MQTT y no llama a la API de Tuya.
 */

require __DIR__ . '/../src/Support/Autoload.php';

use App\Domain\Presence\PresenceEvent;
use App\Domain\Presence\RadarPresenceDecision;
use App\Infrastructure\Db\PdoFactory;
use App\Support\Config;

Config::load(__DIR__ . '/../.env');

$api = dirname(__DIR__);
$runDir = $api . '/run';
$markerFile = $runDir . '/sensor-marker';

$opts = ['room' => null, 'duration' => 600, 'out' => null, 'mark' => null, 'analyze' => null, 'mode' => 'radar_only'];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--room=(\d+)$/', $arg, $m)) {
        $opts['room'] = (int) $m[1];
    } elseif (preg_match('/^--duration=(\d+)$/', $arg, $m)) {
        $opts['duration'] = max(1, (int) $m[1]);
    } elseif (str_starts_with($arg, '--out=')) {
        $opts['out'] = substr($arg, 6);
    } elseif (str_starts_with($arg, '--mark=')) {
        $opts['mark'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--analyze=')) {
        $opts['analyze'] = substr($arg, 10);
    } elseif (str_starts_with($arg, '--mode=')) {
        $opts['mode'] = substr($arg, 7);
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Uso: sensor-capture.php --room=N [--duration=s] [--out=file]\n"
            . "     sensor-capture.php --mark=ETIQUETA\n"
            . "     sensor-capture.php --analyze=file [--mode=radar_only|radar_door|mixed]\n");
        exit(0);
    } else {
        fwrite(STDERR, "[sensor-capture] argumento desconocido: {$arg}\n");
        exit(2);
    }
}

if ($opts['mark'] !== null) {
    if (!is_dir($runDir) && !mkdir($runDir, 0700, true) && !is_dir($runDir)) {
        fwrite(STDERR, "[sensor-capture] no se pudo crear {$runDir}\n");
        exit(1);
    }
    $line = json_encode([
        'type' => 'mark',
        'label' => trim((string) $opts['mark']),
        'at' => gmdate('Y-m-d\TH:i:s.v\Z'),
    ], JSON_UNESCAPED_UNICODE);
    file_put_contents($markerFile, $line . "\n", FILE_APPEND | LOCK_EX);
    fwrite(STDOUT, "marca registrada: {$opts['mark']}\n");
    exit(0);
}

if ($opts['analyze'] !== null) {
    exit(analyzeCapture($opts['analyze'], (string) $opts['mode']));
}

if ($opts['room'] === null) {
    fwrite(STDERR, "[sensor-capture] falta --room=N (o usa --mark / --analyze)\n");
    exit(2);
}

$roomId = (int) $opts['room'];
$pdo = PdoFactory::make();

$stmt = $pdo->prepare(
    "SELECT id, sensor, value, provider, occurred_at, received_at, applied, discard_reason, meta_json
       FROM presence_events
      WHERE room_id = :r
      ORDER BY id DESC LIMIT 1"
);
$stmt->execute([':r' => $roomId]);
$last = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
$lastId = $last === null ? 0 : (int) $last['id'];

$out = (string) ($opts['out'] ?? ($runDir . '/captures/' . $roomId . '-' . gmdate('Ymd\THis') . 'Z.jsonl'));
if (!str_starts_with($out, '/')) {
    $out = $api . '/' . ltrim($out, '/');
}
if (!is_dir(dirname($out)) && !mkdir(dirname($out), 0700, true) && !is_dir(dirname($out))) {
    fwrite(STDERR, "[sensor-capture] no se pudo crear " . dirname($out) . "\n");
    exit(1);
}
$fh = fopen($out, 'ab');
if ($fh === false) {
    fwrite(STDERR, "[sensor-capture] no se pudo abrir {$out}\n");
    exit(1);
}
@chmod($out, 0600);

$meta = ['type' => 'session', 'room_id' => $roomId, 'started_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
    'host' => php_uname('n'), 'last_event_id' => $lastId];
fwrite($fh, json_encode($meta, JSON_UNESCAPED_UNICODE) . "\n");
fwrite(STDOUT, "[sensor-capture] sala {$roomId} → {$out} ({$opts['duration']}s)\n");

$select = $pdo->prepare(
    "SELECT id, sensor, value, provider, occurred_at, received_at, applied, discard_reason, meta_json
       FROM presence_events
      WHERE room_id = :r AND id > :id
      ORDER BY id ASC LIMIT 200"
);

$deadline = time() + (int) $opts['duration'];
while (time() < $deadline) {
    // 1) Marcas físicas pendientes (fichero append-only de --mark).
    if (is_file($markerFile)) {
        $data = (string) file_get_contents($markerFile);
        if ($data !== '') {
            @file_put_contents($markerFile, '', LOCK_EX);
            foreach (array_filter(array_map('trim', explode("\n", $data))) as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    fwrite($fh, $line . "\n");
                }
            }
            fflush($fh);
        }
    }

    // 2) Eventos nuevos desde la BD local (push ya persistido).
    $select->execute([':r' => $roomId, ':id' => $lastId]);
    foreach ($select->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $lastId = (int) $row['id'];
        $decodedMeta = json_decode((string) ($row['meta_json'] ?? ''), true);
        $record = [
            'type' => 'event',
            'id' => (int) $row['id'],
            'sensor' => (string) $row['sensor'],
            'value' => (string) $row['value'],
            'provider' => (string) $row['provider'],
            'occurred_at' => (string) $row['occurred_at'],
            'received_at' => (string) $row['received_at'],
            'applied' => $row['applied'] === null ? null : (bool) $row['applied'],
            'discard_reason' => $row['discard_reason'],
            'radar_decision' => is_array($decodedMeta['radar_decision'] ?? null)
                ? $decodedMeta['radar_decision'] : null,
            'settings' => is_array($decodedMeta['settings'] ?? null) ? $decodedMeta['settings'] : null,
        ];
        fwrite($fh, json_encode($record, JSON_UNESCAPED_UNICODE) . "\n");
    }
    fflush($fh);
    usleep(500_000);
}

$end = ['type' => 'session_end', 'ended_at' => gmdate('Y-m-d\TH:i:s.v\Z'), 'last_event_id' => $lastId];
fwrite($fh, json_encode($end, JSON_UNESCAPED_UNICODE) . "\n");
fclose($fh);
fwrite(STDOUT, "[sensor-capture] captura cerrada ({$out})\n");
exit(0);

/**
 * Replay sin efectos: recalcula RadarPresenceDecision sobre una captura y
 * resume confirmaciones, candidatos y huecos. No escribe en BD ni MQTT.
 */
function analyzeCapture(string $file, string $mode): int
{
    if (!is_file($file)) {
        fwrite(STDERR, "[sensor-capture] no existe {$file}\n");
        return 2;
    }
    $events = [];
    $marks = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $rec = json_decode($line, true);
        if (!is_array($rec)) {
            continue;
        }
        if (($rec['type'] ?? '') === 'mark') {
            $marks[] = $rec;
        } elseif (($rec['type'] ?? '') === 'event') {
            $events[] = $rec;
        }
    }
    if ($events === []) {
        fwrite(STDERR, "[sensor-capture] la captura no contiene eventos\n");
        return 1;
    }
    usort($events, static fn (array $a, array $b): int => strcmp((string) $a['occurred_at'], (string) $b['occurred_at']));

    $cfg = [
        'mode' => $mode,
        'confirm_reports' => 3, 'confirm_window_seconds' => 12, 'min_span_seconds' => 6,
        'hold_seconds' => 20, 'absent_reports' => 3, 'absent_window_seconds' => 30,
        'door_window_seconds' => 90, 'stale_seconds' => 180,
    ];

    $history = [];
    $counts = ['PRESENT_CONFIRMED' => 0, 'CANDIDATE_PRESENT' => 0,
        'ABSENT_CONFIRMED' => 0, 'CANDIDATE_ABSENT' => 0, 'UNKNOWN' => 0];
    $transitions = [];
    $previous = null;
    foreach ($events as $e) {
        $history[] = [
            'sensor' => (string) $e['sensor'],
            'value' => (string) $e['value'],
            'provider' => (string) $e['provider'],
            'occurred_at' => (string) $e['occurred_at'],
            'source_event_id' => 'cap-' . $e['id'],
        ];
        $now = new DateTimeImmutable((string) $e['occurred_at'], new DateTimeZone('UTC'));
        $decision = RadarPresenceDecision::decide($history, $now, $cfg, false, null);
        $state = (string) $decision['state'];
        $counts[$state] = ($counts[$state] ?? 0) + 1;
        if ($previous !== null && $previous !== $state) {
            $transitions[] = ['at' => $now->format('H:i:s.v'), 'from' => $previous, 'to' => $state];
        }
        $previous = $state;
    }

    $result = [
        'file' => $file,
        'mode' => $mode,
        'events' => count($events),
        'marks' => count($marks),
        'counts' => $counts,
        'transitions' => $transitions,
        'first_at' => $events[0]['occurred_at'],
        'last_at' => $events[count($events) - 1]['occurred_at'],
    ];
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    return 0;
}
