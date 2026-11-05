<?php
declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * TimbreEventStreamController — SSE del panel /timbre (F117).
 *
 * GET /timbre-api/event-stream?room_id=N
 */
final class TimbreEventStreamController
{
    private const SLEEP_US = 200_000;
    private const PING_S = 5;
    private const MAX_LIFETIME_S = 60;

    public function __construct(private TimbreStateController $state)
    {
    }

    public function stream(int $roomId): void
    {
        @set_time_limit(0);
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        echo "retry: 3000\n\n";
        echo "event: connected\n";
        echo 'data: ' . json_encode(['room_id' => $roomId, 'ts' => gmdate('Y-m-d\TH:i:s\Z')]) . "\n\n";
        @flush();

        $start = microtime(true);
        $lastPing = time();
        $lastFp = null;

        while (true) {
            if (connection_aborted()) {
                break;
            }
            if ((microtime(true) - $start) > self::MAX_LIFETIME_S) {
                echo "event: close\n";
                echo 'data: {"reason":"max_lifetime"}' . "\n\n";
                @flush();
                break;
            }

            try {
                $snapshot = $this->state->stateArray($roomId);
                $live = $snapshot['live'] ?? null;
                if (is_array($live)) {
                    unset($live['door_age_seconds'], $live['presence_age_seconds']);
                }
                $fp = md5(json_encode([
                    $snapshot['room'],
                    $live,
                    $snapshot['camera'],
                    $snapshot['movement'],
                    $snapshot['last_movement_id'],
                ]));
                if ($fp !== $lastFp) {
                    $lastFp = $fp;
                    echo "event: state\n";
                    echo 'data: ' . json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                    @flush();
                }
            } catch (\Throwable $e) {
                // Nunca tumbar el stream por un error transitorio de BD.
            }

            if ((time() - $lastPing) >= self::PING_S) {
                $lastPing = time();
                echo "event: ping\n";
                echo "data: {}\n\n";
                @flush();
            }

            usleep(self::SLEEP_US);
        }
        exit(0);
    }
}
