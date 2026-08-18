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

    /** @var int maximum connection lifetime in seconds */
    private const MAX_LIFETIME_S = 1800;

    /** @var int seconds between keepalive comments */
    private const KEEPALIVE_S = 15;

    /** @var int max concurrent SSE connections per room (Fase 2 — T2.6) */
    private const MAX_CONNS_PER_ROOM = 10;

    /** @var array<int, int> connection count per room */
    private static array $connCount = [];

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
            exit(0);
        }
        self::$connCount[$roomId]++;

        // ── SSE headers ──
        header('Content-Type: text/event-stream; charset=utf-8');
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

        // ── Send initial connection event ──
        $this->sendEvent('connected', ['room_id' => $roomId, 'ts' => gmdate('Y-m-d\TH:i:s\Z')]);

        $lastFingerprint = '';
        $startTime       = time();
        $lastKeepalive   = $startTime;
        $loopCount       = 0;

        try {
            // ── Main event loop ──
            while (true) {
                $loopCount++;
                $now = time();

                // Check maximum lifetime
                if ($now - $startTime > self::MAX_LIFETIME_S) {
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
                        $this->sendEvent('state', $state);
                    }
                } elseif ($now - $lastKeepalive >= self::KEEPALIVE_S) {
                    // Send periodic keepalive to prevent proxy timeouts
                    $this->sendComment('keepalive ' . gmdate('Y-m-d\TH:i:s\Z'));
                    $lastKeepalive = $now;
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
                'SELECT door_state, presence_state, last_open_at, last_close_at, last_absent_since FROM iot_sessions WHERE room_id = :rid'
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
                ]
                : [
                    'door_state'       => 'UNKNOWN',
                    'presence_state'   => 'UNKNOWN',
                    'last_open_at'     => null,
                    'last_close_at'    => null,
                    'last_absent_since' => null,
                ];

            // Active stay
            $stayStmt = $this->pdo->prepare(
                "SELECT id, status, duracion_minutos, first_entry_at, exit_detected_at
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

            if ($sessionRow !== false
                && ($sessionRow['presence_state'] ?? '') === 'ABSENT'
                && ($sessionRow['door_state'] ?? '') === 'CLOSED'
                && !empty($sessionRow['last_absent_since'])
            ) {
                $anchor = $sessionRow['last_close_at'] ?? $sessionRow['last_open_at'];
                if ($anchor !== null) {
                    $anchorTs = strtotime($anchor . ' UTC');
                    $absentTs = strtotime((string) $sessionRow['last_absent_since'] . ' UTC');
                    $nowTs    = Clock::nowUtc()->getTimestamp();
                    $closeWindowS = \App\Domain\Presence\ExitRuleEvaluator::DOOR_CLOSE_WINDOW_S;

                    if ($anchorTs !== false && $absentTs !== false
                        && ($nowTs - $anchorTs) <= $closeWindowS
                    ) {
                        $deadlineTs = $absentTs + $gapSeconds;
                        $exitDeadline = gmdate('Y-m-d\TH:i:s\Z', $deadlineTs);
                    }
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
        $stmt = $this->pdo->prepare(
            'SELECT sensor, value, provider, occurred_at
             FROM presence_events
             WHERE room_id = :rid
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
