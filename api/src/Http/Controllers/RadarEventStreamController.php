<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Sensors\RadarLiveService;
use PDO;
use Throwable;

/**
 * RadarEventStreamController — F125.1.5.
 *
 * SSE del estado vivo de Radar Studio. Mismo patrón que EventStreamController:
 * comprueba el payload cada 500 ms y solo emite cuando cambia; ping cada 5 s;
 * vida máxima 60 s para no retener workers del PHP built-in.
 */
final class RadarEventStreamController
{
    private const SLEEP_US = 500_000;
    private const MAX_LIFETIME_S = 60;
    private const PING_S = 5;
    private const MAX_CONNS_PER_ROOM = 5;

    /** @var array<int,int> */
    private static array $connCount = [];

    public function __construct(private PDO $pdo)
    {
    }

    public function stream(int $roomId): void
    {
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

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        ignore_user_abort(false);
        if (ob_get_level()) {
            ob_end_clean();
        }
        ini_set('output_buffering', '0');
        ini_set('zlib.output_compression', '0');

        echo "retry: 3000\n\n";
        flush();
        $this->sendEvent('connected', ['room_id' => $roomId, 'ts' => gmdate('Y-m-d\TH:i:s\Z')]);

        $service = new RadarLiveService($this->pdo);
        $lastHash = '';
        $start = time();
        $lastPing = $start;

        try {
            while (true) {
                $now = time();
                if ($now - $start > self::MAX_LIFETIME_S) {
                    $this->sendEvent('close', ['reason' => 'max_lifetime', 'uptime_s' => $now - $start]);
                    break;
                }
                if (connection_aborted()) {
                    break;
                }

                try {
                    $payload = $service->payload($roomId);
                } catch (Throwable $e) {
                    $this->sendEvent('error', ['message' => $e->getMessage()]);
                    break;
                }

                $hashData = $payload;
                unset($hashData['server_ts']);
                $hash = md5((string) json_encode($hashData));
                if ($hash !== $lastHash) {
                    $lastHash = $hash;
                    $this->sendEvent('live', $payload);
                }

                if ($now - $lastPing >= self::PING_S) {
                    $lastPing = $now;
                    $this->sendEvent('ping', ['room_id' => $roomId, 'ts' => gmdate('Y-m-d\TH:i:s\Z')]);
                }

                usleep(self::SLEEP_US);
            }
        } finally {
            self::$connCount[$roomId] = max(0, self::$connCount[$roomId] - 1);
        }
        exit(0);
    }

    /** @param array<string,mixed> $data */
    private function sendEvent(string $event, array $data): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        flush();
    }
}
