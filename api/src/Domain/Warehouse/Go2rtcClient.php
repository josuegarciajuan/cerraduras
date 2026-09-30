<?php
declare(strict_types=1);

namespace App\Domain\Warehouse;

/**
 * Go2rtcClient: thin client for the go2rtc HTTP API (F61/RF-72).
 *
 * Streams are registered by name (almacen_<room>_<position>) with the RTSP URL
 * passed as a query parameter, so credentials are never written to a config file
 * or to logs.
 */
final class Go2rtcClient
{
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function enabled(): bool
    {
        return $this->baseUrl !== '';
    }

    public static function streamName(int $roomId, string $position): string
    {
        return 'almacen_' . $roomId . '_' . strtoupper($position);
    }

    /** Add/replace a stream. Returns true on HTTP 2xx. */
    public function upsert(string $name, string $rtspUrl): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $url = $this->baseUrl . '/api/streams?name=' . rawurlencode($name) . '&src=' . rawurlencode($rtspUrl);
        return $this->request('PUT', $url);
    }

    /** Remove a stream by name. */
    public function remove(string $name): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $url = $this->baseUrl . '/api/streams?src=' . rawurlencode($name);
        return $this->request('DELETE', $url);
    }

    public function liveUrl(int $roomId, string $position): ?string
    {
        if (!$this->enabled()) {
            return null;
        }
        return $this->baseUrl . '/stream.html?src=' . rawurlencode(self::streamName($roomId, $position));
    }

    private function request(string $method, string $url): bool
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }
}
