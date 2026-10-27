<?php
declare(strict_types=1);

namespace App\Domain\Devices;

/**
 * CameraReachabilityProbe (F92/RF-154).
 *
 * On-demand reachability check for an RTSP camera. It never runs in a polling
 * loop: the caller decides when to probe (dashboard load / "Comprobar").
 *
 * Preferred path: `ffprobe -rtsp_transport tcp` with a bounded deadline, which
 * proves a video stream is readable. Fallback: a TCP connect to the RTSP port
 * when ffprobe is not installed. Credentials in the URL are never logged.
 */
final class CameraReachabilityProbe
{
    private ?string $ffprobeBinary;
    private float $timeoutSeconds;

    public function __construct(?string $ffprobeBinary = null, float $timeoutSeconds = 3.0)
    {
        $this->ffprobeBinary = $ffprobeBinary;
        $this->timeoutSeconds = max(0.5, $timeoutSeconds);
    }

    /**
     * @return ?bool true=stream reachable, false=unreachable, null=invalid/not assessable
     */
    public function probe(string $rtspUrl): ?bool
    {
        $endpoint = self::parseRtsp($rtspUrl);
        if ($endpoint === null) {
            return null;
        }

        $ffprobe = $this->ffprobeBinary ?? self::discoverFfprobe();
        if ($ffprobe !== null) {
            $result = $this->probeWithFfprobe($ffprobe, $rtspUrl);
            if ($result !== null) {
                return $result;
            }
        }

        return $this->probeTcp($endpoint['host'], $endpoint['port']);
    }

    /**
     * Parse an RTSP URL without exposing credentials.
     *
     * @return array{host:string,port:int}|null
     */
    public static function parseRtsp(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $parts = @parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['rtsp', 'rtsps'], true)) {
            return null;
        }
        $host = trim((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }
        $defaultPort = $scheme === 'rtsps' ? 322 : 554;
        $port = isset($parts['port']) ? (int) $parts['port'] : $defaultPort;
        if ($port < 1 || $port > 65535) {
            return null;
        }
        return ['host' => $host, 'port' => $port];
    }

    private function probeTcp(string $host, int $port): bool
    {
        $timeout = min(2.0, $this->timeoutSeconds);
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (is_resource($socket)) {
            fclose($socket);
            return true;
        }
        return false;
    }

    private function probeWithFfprobe(string $ffprobe, string $url): ?bool
    {
        $timeoutUs = (string) (int) round($this->timeoutSeconds * 1_000_000);
        $cmd = [
            $ffprobe,
            '-v', 'error',
            '-rtsp_transport', 'tcp',
            '-rw_timeout', $timeoutUs,
            '-select_streams', 'v:0',
            '-show_entries', 'stream=codec_type',
            '-of', 'csv=p=0',
            $url,
        ];

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return null;
        }

        foreach ([1, 2] as $fd) {
            if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
                stream_set_blocking($pipes[$fd], false);
            }
        }

        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $deadline = microtime(true) + $this->timeoutSeconds + 0.5;

        while (true) {
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                $stdout .= (string) stream_get_contents($pipes[1]);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                $stderr .= (string) stream_get_contents($pipes[2]);
            }

            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                @proc_terminate($proc, 9);
                $exitCode = -1;
                break;
            }
            usleep(50_000);
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        @proc_close($proc);

        // 127 = binary not found / not executable; fall back to TCP.
        if ($exitCode === 127) {
            return null;
        }
        if ($exitCode !== 0) {
            return false;
        }
        return trim($stdout) !== '';
    }

    private static function discoverFfprobe(): ?string
    {
        $configured = trim((string) (getenv('CAMERA_PROBE_FFPROBE') ?: ''));
        if ($configured !== '') {
            if (str_contains($configured, DIRECTORY_SEPARATOR)) {
                return is_file($configured) && is_executable($configured) ? $configured : null;
            }
            return self::searchPath($configured);
        }
        return self::searchPath('ffprobe');
    }

    private static function searchPath(string $binary): ?string
    {
        $path = getenv('PATH') ?: '';
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
