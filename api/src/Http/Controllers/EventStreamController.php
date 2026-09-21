<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Clock;
use App\Support\Config;
use App\Support\Qr\QrTokenizer;

/**
 * EventStreamController: Server-Sent Events endpoint for real-time dashboard updates.
 *
 * GET /dashboard-api/event-stream?room_id=N
 *
 * Monitors the database for state changes and pushes updates to the browser
 * via SSE, eliminating the 2-second polling delay. Falls back gracefully
 * when clients disconnect.
 *
 * Implementation notes:
 *  - Uses a two-tier approach: lightweight fingerprint query every 500ms,
 *    full state query only when the fingerprint changes.
 *  - Max connection lifetime: 30 minutes, after which the client is expected
 *    to reconnect (EventSource auto-reconnects natively).
 *  - Designed for PHP built-in server with PHP_CLI_SERVER_WORKERS=8.
 *    Each SSE client occupies one worker for the duration.
 */
final class EventStreamController
{
    private \PDO $pdo;

    /** @var int microseconds between DB checks */
    private const SLEEP_US = 200_000;

    /**
     * @var int maximum connection lifetime in seconds.
     *
     * Fase B: bajado de 1800 a 60. El servidor PHP built-in NO detecta la
     * desconexión del cliente (`connection_aborted()` no se activa aunque se
     * escriba), así que un SSE muerto retiene un worker hasta este límite; con
     * 8 workers, varios SSE zombies agotan el pool → el nuevo SSE no se sirve →
     * el panel cae a polling lento → retraso >10 s. Acotarlo a 60 s limita la
     * fuga y el cliente reconecta en ~0,5 s (EventSource + `retry`), sin huecos
     * visibles.
     */
    private const MAX_LIFETIME_S = 60;

    /** @var int seconds between keepalive comments */
    private const KEEPALIVE_S = 15;

    /** @var int seconds between named `ping` events (F41, contracts.md §4) */
    private const PING_S = 5;

    /** @var int max concurrent SSE connections per room (Fase 2 — T2.6) */
    private const MAX_CONNS_PER_ROOM = 10;

    /** @var array<int, int> connection count per room */
    private static array $connCount = [];

    /** @var int errors de fingerprint/full-state en ESTA conexión (instrumentación A, Fase A) */
    private int $sseFingerprintErrors = 0;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Stream SSE events to the client until disconnect or timeout.
     *
     * This method outputs directly to the client and calls exit().
     * It does NOT return a Response — it bypasses the normal framework pipeline.
     */
    public function stream(int $roomId): void
    {
        // ── Rate limiting: reject if too many clients are connected to this room ──
        if (!isset(self::$connCount[$roomId])) {
            self::$connCount[$roomId] = 0;
        }
        if (self::$connCount[$roomId] >= self::MAX_CONNS_PER_ROOM) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache');
            echo "event: close\ndata: {\"reason\":\"too_many_connections\",\"room_id\":{$roomId}}\n\n";
            $this->logSse([
                'event' => 'reject', 'room_id' => $roomId, 'ip' => $this->clientIp(),
                'reason' => 'too_many_connections', 'conns' => self::$connCount[$roomId],
            ]);
            exit(0);
        }
        self::$connCount[$roomId]++;

        // ── Instrumentación (Fase A): traza de vida de la conexión SSE ──
        $streamStart = microtime(true);
        $stateEvents = 0;
        $pingEvents  = 0;
        $reason      = 'client_abort';
        $this->logSse([
            'event'   => 'open', 'room_id' => $roomId, 'ip' => $this->clientIp(),
            'ua'      => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'conns'   => self::$connCount[$roomId],
        ]);

        // Fase B: que el cliente no espere backoff si el servidor cierra el
        // stream (max_lifetime) — reconecta en 3 s por defecto.
        header('Content-Type: text/event-stream; charset=utf-8');
        ignore_user_abort(false);
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // disable nginx proxy buffering

        // ── Disable all output buffering ──
        if (ob_get_level()) {
            ob_end_clean();
        }
        // Prevent PHP from buffering output
        ini_set('output_buffering', '0');
        ini_set('zlib.output_compression', '0');
        // Implicit flush after every output call
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }

        // SSE reconnect hint (Fase B): 3 s si el servidor cierra el stream.
        echo "retry: 3000\n\n";
        flush();

        // ── Send initial connection event ──
        $this->sendEvent('connected', ['room_id' => $roomId, 'ts' => gmdate('Y-m-d\TH:i:s\Z')]);

        $lastFingerprint = '';
        $startTime       = time();
        $lastKeepalive   = $startTime;
        $lastPing        = $startTime;
        $loopCount       = 0;

        try {
            // ── Main event loop ──
            while (true) {
                $loopCount++;
                $now = time();

                // Check maximum lifetime
                if ($now - $startTime > self::MAX_LIFETIME_S) {
                    $reason = 'max_lifetime';
                    $this->sendEvent('close', ['reason' => 'max_lifetime', 'uptime_s' => $now - $startTime]);
                    break;
                }

                // Check client disconnect
                if (connection_aborted()) {
                    break;
                }

                // ── Tier 1: lightweight fingerprint query ──
                $fingerprint = $this->computeFingerprint($roomId);

                if ($fingerprint !== $lastFingerprint || $fingerprint === '') {
                    $lastFingerprint = $fingerprint;

                    // ── Tier 2: full state query (only on change) ──
                    $state = $this->fetchFullState($roomId);
                    if ($state !== null) {
                        $state['server_ts'] = gmdate('Y-m-d\TH:i:s\Z'); // Fase A: medir latencia real
                        $this->sendEvent('state', $state);
                        $stateEvents++;
                    }
                } elseif ($now - $lastKeepalive >= self::KEEPALIVE_S) {
                    // Send periodic keepalive to prevent proxy timeouts
                    $this->sendComment('keepalive ' . gmdate('Y-m-d\TH:i:s\Z'));
                    $lastKeepalive = $now;
                }

                // F41 (RF-49.1): named liveness event for the panel SSE watchdog.
                if ($now - $lastPing >= self::PING_S) {
                    $this->sendEvent('ping', ['room_id' => $roomId, 'ts' => gmdate('Y-m-d\TH:i:s\Z')]);
                    $pingEvents++;
                    $lastPing = $now;
                }

                // Re-check connection before sleeping
                if (connection_aborted()) {
                    break;
                }

                usleep(self::SLEEP_US);
            } // end while
        } finally {
            // Release connection slot (Fase 2 — T2.6)
            self::$connCount[$roomId] = max(0, self::$connCount[$roomId] - 1);
            // Fase A: traza de cierre con contadores (state vs ping) y motivo.
            $this->logSse([
                'event'        => 'close',
                'room_id'      => $roomId,
                'ip'           => $this->clientIp(),
                'dur_s'        => round(microtime(true) - $streamStart, 1),
                'reason'       => $reason,
                'state_events' => $stateEvents,
                'ping_events'  => $pingEvents,
                'fp_errors'    => $this->sseFingerprintErrors,
                'conns'        => self::$connCount[$roomId],
            ]);
        }

        exit(0);
    }

    /**
     * Compute a lightweight fingerprint of the room state.
     *
     * This single query captures all fields that could trigger a dashboard
     * update. Returns a hash string, or '' if the room doesn't exist.
     */
    private function computeFingerprint(int $roomId): string
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    COALESCE(s.door_state,         '') AS c1,
                    COALESCE(s.presence_state,     '') AS c2,
                    COALESCE(s.last_close_at,      '') AS c3,
                    COALESCE(s.last_open_at,       '') AS c4,
                    COALESCE(s.last_absent_since,  '') AS c5,
                    COALESCE(s.last_door_event_at,     '') AS c14,
                    COALESCE(s.last_presence_event_at, '') AS c15,
                    COALESCE(s.last_door_value,        '') AS c16,
                    COALESCE(s.last_presence_value,    '') AS c17,
                    COALESCE(st.entry_confirmed_at,    '') AS c18,
                    COALESCE(r.status,             '') AS c6,
                    COALESCE(r.cooldown_until,     '') AS c7,
                    COALESCE(st.status,            '') AS c8,
                    COALESCE(st.first_entry_at,    '') AS c9,
                    COALESCE(st.exit_detected_at,  '') AS c10,
                    (SELECT MAX(id) FROM access_events WHERE room_id = r.id) AS c11,
                    (SELECT MAX(occurred_at) FROM access_events WHERE room_id = r.id) AS c12,
                    (SELECT COUNT(*) FROM anomalies WHERE room_id = r.id AND status = 'OPEN') AS c13
                 FROM rooms r
                 LEFT JOIN iot_sessions s  ON s.room_id  = r.id
                 LEFT JOIN stays st        ON st.room_id = r.id AND st.status IN ('RESERVED','OCCUPIED')
                 WHERE r.id = :rid
                 LIMIT 1"
            );
            $stmt->execute([':rid' => $roomId]);
            $row = $stmt->fetch(\PDO::FETCH_NUM);

            if ($row === false) {
                return '';
            }

            return md5(implode('|', $row));
        } catch (\Throwable $e) {
            // If DB is temporarily unavailable, return empty to trigger a full
            // fetch on the next cycle, rather than crashing the stream.
            $this->sseFingerprintErrors++;
            return '';
        }
    }

    /**
     * Fetch the full room state for the dashboard.
     *
     * Returns the same structure as RoomLiveController::show() so the
     * frontend can reuse the same rendering functions.
     */
    private function fetchFullState(int $roomId): ?array
    {
        try {
            // Room
            $roomStmt = $this->pdo->prepare('SELECT id, code, status, cooldown_until, presence_check_seconds FROM rooms WHERE id = :rid');
            $roomStmt->execute([':rid' => $roomId]);
            $roomRow = $roomStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$roomRow) {
                return null;
            }

            // IoT session
            $iotStmt = $this->pdo->prepare(
                'SELECT door_state, presence_state, last_open_at, last_close_at, last_absent_since,
                        last_door_event_at, last_presence_event_at, last_door_value, last_presence_value
                   FROM iot_sessions WHERE room_id = :rid'
            );
            $iotStmt->execute([':rid' => $roomId]);
            $sessionRow = $iotStmt->fetch(\PDO::FETCH_ASSOC);

            $iotData = $sessionRow !== false
                ? [
                    'door_state'       => $sessionRow['door_state'] ?? 'UNKNOWN',
                    'presence_state'   => $sessionRow['presence_state'] ?? 'UNKNOWN',
                    'last_open_at'     => $sessionRow['last_open_at'],
                    'last_close_at'    => $sessionRow['last_close_at'],
                    'last_absent_since' => $sessionRow['last_absent_since'],
                    'last_door_event_at'     => $sessionRow['last_door_event_at'] ?? null,
                    'last_presence_event_at' => $sessionRow['last_presence_event_at'] ?? null,
                    'last_door_value'        => $sessionRow['last_door_value'] ?? null,
                    'last_presence_value'    => $sessionRow['last_presence_value'] ?? null,
                ]
                : [
                    'door_state'       => 'UNKNOWN',
                    'presence_state'   => 'UNKNOWN',
                    'last_open_at'     => null,
                    'last_close_at'    => null,
                    'last_absent_since' => null,
                    'last_door_event_at'     => null,
                    'last_presence_event_at' => null,
                    'last_door_value'        => null,
                    'last_presence_value'    => null,
                ];

            // Active stay
            $stayStmt = $this->pdo->prepare(
                "SELECT id, status, duracion_minutos, first_entry_at, entry_confirmed_at, exit_detected_at
                 FROM stays
                 WHERE room_id = :rid AND status IN ('RESERVED','OCCUPIED')
                 ORDER BY id DESC LIMIT 1"
            );
            $stayStmt->execute([':rid' => $roomId]);
            $stayRow = $stayStmt->fetch(\PDO::FETCH_ASSOC);

            $stayData = null;
            if ($stayRow !== false) {
                $stayData = [
                    'id'              => (int) $stayRow['id'],
                    'status'          => $stayRow['status'],
                    'duracion_minutos' => (int) ($stayRow['duracion_minutos'] ?? 0),
                    'first_entry_at'  => $stayRow['first_entry_at'],
                    'entry_confirmed_at' => $stayRow['entry_confirmed_at'] ?? null,
                    'exited_at'       => $stayRow['exit_detected_at'],
                ];
            }

            // Recent access events (last 20)
            $recentEvents = $this->fetchRecentEvents($roomId, 20);

            // Recent presence events (last 10)
            $recentPresence = $this->fetchRecentPresence($roomId, 10);

            // Switch state
            $switchState = $this->fetchSwitchState($roomId);

            // QR status
            $qrStatus = $this->fetchQrStatus($roomId);

            // Exit deadline
            $exitDeadline = null;
            $gapSeconds   = $this->resolveGapSeconds($roomId, (int) ($roomRow['presence_check_seconds'] ?? 0));

            // F41/F42 (contracts.md §3.3): presence ABSENT + credited door cycle
            // (open then close) + door CLOSED + entry confirmed. F42 (RF-46.4):
            // sin `entry_confirmed_at` no hay salida posible; evita un conteo de
            // salida durante una entrada aún no consolidada.
            if ($sessionRow !== false
                && ($sessionRow['presence_state'] ?? '') === 'ABSENT'
                && ($sessionRow['door_state'] ?? '') === 'CLOSED'
                && !empty($sessionRow['last_absent_since'])
                && !empty($sessionRow['last_open_at'])
                && !empty($sessionRow['last_close_at'])
                && $stayData !== null
                && !empty($stayData['entry_confirmed_at'])
            ) {
                $openTs   = strtotime((string) $sessionRow['last_open_at'] . ' UTC');
                $closeTs  = strtotime((string) $sessionRow['last_close_at'] . ' UTC');
                $absentTs = strtotime((string) $sessionRow['last_absent_since'] . ' UTC');

                if ($openTs !== false && $closeTs !== false && $absentTs !== false
                    && $closeTs >= $openTs
                ) {
                    $deadlineTs   = $absentTs + $gapSeconds;
                    $exitDeadline = gmdate('Y-m-d\TH:i:s\Z', $deadlineTs);
                }
            }

            // F35: Active anomalies for this room (OPEN only, same as /live endpoint)
            $anomalyStmt = $this->pdo->prepare(
                "SELECT id, anomaly_type, severity, status, context_data, detected_at,
                        acknowledged_at, dismissed_at
                 FROM anomalies
                 WHERE room_id = :rid AND status = 'OPEN'
                 ORDER BY detected_at DESC
                 LIMIT 20"
            );
            $anomalyStmt->execute([':rid' => $roomId]);
            $anomalies = [];
            foreach ($anomalyStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $ar) {
                $ctx = null;
                if ($ar['context_data'] !== null) {
                    $decoded = json_decode((string) $ar['context_data'], true);
                    $ctx = is_array($decoded) ? $decoded : null;
                }
                $anomalies[] = [
                    'id'               => (int) $ar['id'],
                    'anomaly_type'     => (string) $ar['anomaly_type'],
                    'severity'         => (string) $ar['severity'],
                    'status'           => (string) $ar['status'],
                    'context_data'     => $ctx,
                    'detected_at'      => $ar['detected_at'],
                    'acknowledged_at'  => $ar['acknowledged_at'],
                    'dismissed_at'     => $ar['dismissed_at'],
                ];
            }

            return [
                'room_id'          => $roomId,
                'code'             => $roomRow['code'],
                'status'           => $roomRow['status'],
                'cooldown_until'   => $roomRow['cooldown_until'],
                'iot_session'      => $iotData,
                'active_stay'      => $stayData,
                'switch_state'     => $switchState,
                'exit_deadline'    => $exitDeadline,
                'gap_seconds'      => $gapSeconds,
                'qr_status'        => $qrStatus,
                'recent_events'    => $recentEvents,
                'recent_presence'  => $recentPresence,
                'anomalies'        => $anomalies,
            ];
        } catch (\Throwable $e) {
            // Log error but don't crash the stream
            error_log('[EventStreamController] fetchFullState error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch recent access events for the room.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchRecentEvents(int $roomId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, kind, result, reason, provider, correlation_id, meta_json, occurred_at
             FROM access_events
             WHERE room_id = :rid
             ORDER BY occurred_at DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':rid', $roomId, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $events = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $meta = null;
            if ($row['meta_json'] !== null) {
                $decoded = json_decode((string) $row['meta_json'], true);
                $meta = is_array($decoded) ? $decoded : null;
            }
            $events[] = [
                'id'             => (int) $row['id'],
                'kind'           => (string) $row['kind'],
                'result'         => (string) $row['result'],
                'reason'         => $row['reason'],
                'provider'       => (string) $row['provider'],
                'correlation_id' => (string) $row['correlation_id'],
                'meta'           => $meta,
                'occurred_at'    => (string) $row['occurred_at'],
            ];
        }
        return $events;
    }

    /**
     * Fetch recent presence events for the room.
     *
     * @return list<array{sensor:string,value:string,provider:string,occurred_at:string}>
     */
    private function fetchRecentPresence(int $roomId, int $limit): array
    {
        // F48: solo hechos aplicados; los descartados no deben disparar la
        // coreografía del panel (misma regla que RoomLiveController).
        $stmt = $this->pdo->prepare(
            'SELECT sensor, value, provider, occurred_at
             FROM presence_events
             WHERE room_id = :rid AND applied = 1
             ORDER BY occurred_at DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':rid', $roomId, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(function (array $row): array {
            return [
                'sensor'      => $row['sensor'],
                'value'       => $row['value'],
                'provider'    => $row['provider'],
                'occurred_at' => $row['occurred_at'],
            ];
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Fetch switch device state for the room (via pack).
     */
    private function fetchSwitchState(int $roomId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT d.id, d.external_id, d.meta_json
             FROM devices d
             INNER JOIN rooms r ON r.pack_id = d.pack_id
             WHERE r.id = :rid AND d.kind = 'SWITCH'
             LIMIT 1"
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $meta = !empty($row['meta_json'])
            ? json_decode((string) $row['meta_json'], true)
            : [];

        return [
            'id'           => (int) $row['id'],
            'external_id'  => $row['external_id'],
            'last_command' => $meta['last_command'] ?? 'UNKNOWN',
            'commanded_at' => $meta['commanded_at'] ?? null,
            // Observabilidad del último fallo Tuya (aditivo, nullable).
            'last_error'          => $meta['last_error'] ?? null,
            'last_error_at'       => $meta['last_error_at'] ?? null,
            'last_error_category' => $meta['last_error_category'] ?? null,
        ];
    }

    /**
     * Fetch QR credential status for the room.
     *
     * Mimics RoomLiveController::fetchQrStatus().
     */
    private function fetchQrStatus(int $roomId): array
    {
        $default = [
            'scannable' => false, 'consumed' => false, 'revoked' => false,
            'expired' => false, 'jti' => '', 'stay_id' => null,
        ];

        $stmt = $this->pdo->prepare(
            'SELECT qc.id, qc.jti, qc.issued_at, qc.consumed_at, qc.revoked_at, qc.expires_at, qc.stay_id, qc.room_id
             FROM qr_credentials qc
             INNER JOIN stays s ON s.id = qc.stay_id
             WHERE s.room_id = :rid AND s.status IN (\'RESERVED\',\'OCCUPIED\')
             ORDER BY qc.issued_at DESC LIMIT 1'
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return $default;
        }

        $nowUtc  = gmdate('Y-m-d H:i:s');
        $expired = $row['expires_at'] && $row['expires_at'] < $nowUtc;
        $iatEpoch = strtotime((string) $row['issued_at'] . ' UTC');
        $expEpoch = strtotime((string) $row['expires_at'] . ' UTC');

        $secret      = Config::getRequired('QR_SIGNING_SECRET');
        $qrTokenizer = new QrTokenizer($secret);
        $qrText      = $qrTokenizer->issue(
            (int) $row['room_id'],
            (int) $row['stay_id'],
            (string) $row['jti'],
            $iatEpoch,
            $expEpoch
        );

        return [
            'scannable'  => !$row['consumed_at'] && !$row['revoked_at'] && !$expired,
            'consumed'   => (bool) $row['consumed_at'],
            'revoked'    => (bool) $row['revoked_at'],
            'expired'    => $expired,
            'jti'        => (string) $row['jti'],
            'stay_id'    => (int) $row['stay_id'],
            'expires_at' => $row['expires_at'],
            'qr_text'    => $qrText,
        ];
    }

    /**
     * Resolve gap_seconds: room-level override takes precedence over room_type.
     */
    private function resolveGapSeconds(int $roomId, int $roomPresenceCheckSeconds): int
    {
        if ($roomPresenceCheckSeconds > 0) {
            return $roomPresenceCheckSeconds;
        }

        $stmt = $this->pdo->prepare(
            'SELECT rt.exit_presence_gap_seconds
             FROM rooms r
             INNER JOIN room_types rt ON rt.id = r.room_type_id
             WHERE r.id = :rid
             LIMIT 1'
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false && isset($row['exit_presence_gap_seconds'])) {
            return (int) $row['exit_presence_gap_seconds'];
        }

        return 15;
    }

    /**
     * Client IP as seen by the stream (last hop of X-Forwarded-For, else REMOTE_ADDR).
     */
    private function clientIp(): string
    {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($xff) && trim($xff) !== '') {
            $parts = array_map('trim', explode(',', $xff));
            $last  = end($parts);
            if (is_string($last) && $last !== '') {
                return $last;
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    /**
     * Fase A: append one JSON line to api/logs/event-stream.log.
     *
     * Low volume BY DESIGN: only `open`/`close`/`reject` (never per event), so it
     * can stay enabled in production. Never breaks the stream on logging errors.
     */
    private function logSse(array $entry): void
    {
        try {
            $line = json_encode(
                array_merge(['ts' => gmdate('Y-m-d\TH:i:s\Z')], $entry),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($line === false) {
                return;
            }
            $dir = dirname(__DIR__, 3) . '/logs';
            if (!is_dir($dir)) {
                return;
            }
            @file_put_contents($dir . '/event-stream.log', $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Never break the stream because of instrumentation.
        }
    }

    /**
     * Send an SSE event.
     */
    private function sendEvent(string $event, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }
        echo "event: {$event}\n";
        echo "data: {$json}\n\n";
        flush();
    }

    /**
     * Send an SSE comment (keepalive, invisible to EventSource listeners).
     */
    private function sendComment(string $msg): void
    {
        echo ": {$msg}\n\n";
        flush();
    }
}
