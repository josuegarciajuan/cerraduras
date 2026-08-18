<?php
declare(strict_types=1);

namespace App\Support\Logger;

use App\Support\Clock;

/**
 * Logger: JSON-line logger with leveling.
 *
 * Outputs one JSON object per line to:
 *  - stdout (always), so containers/journald can capture it.
 *  - optional rolling file path (LOG_PATH from .env).
 *
 * Levels: debug(10) < info(20) < warn(30) < error(40).
 * Messages below LOG_LEVEL are discarded.
 *
 * Every entry includes: ts (UTC ISO-8601 with ms), level, msg, context.
 */
final class Logger
{
    private const LEVELS = [
        'debug' => 10,
        'info'  => 20,
        'warn'  => 30,
        'error' => 40,
    ];

    private int $minLevel;
    private ?string $filePath;

    public function __construct(string $level = 'info', ?string $filePath = null)
    {
        $level = strtolower($level);
        $this->minLevel = self::LEVELS[$level] ?? self::LEVELS['info'];
        $this->filePath = $filePath;
    }

    public function debug(string $msg, array $ctx = []): void
    {
        $this->log('debug', $msg, $ctx);
    }
    public function info(string $msg, array $ctx = []): void
    {
        $this->log('info', $msg, $ctx);
    }
    public function warn(string $msg, array $ctx = []): void
    {
        $this->log('warn', $msg, $ctx);
    }
    public function error(string $msg, array $ctx = []): void
    {
        $this->log('error', $msg, $ctx);
    }

    private function log(string $level, string $msg, array $ctx): void
    {
        if ((self::LEVELS[$level] ?? 0) < $this->minLevel) {
            return;
        }

        $entry = [
            'ts' => Clock::nowIsoUtcMillis(),
            'level' => $level,
            'msg' => $msg,
        ];
        if (!empty($ctx)) {
            $entry['context'] = $ctx;
        }

        $line = (string) json_encode(
            $entry,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        // stdout: under the CLI SAPI the STDOUT constant is defined, but under
        // web SAPIs it is not. We open php://stdout explicitly, which works in
        // both environments. Under a web server it maps to the request output.
        $stdout = @fopen('php://stdout', 'w');
        if (is_resource($stdout)) {
            @fwrite($stdout, $line . PHP_EOL);
        }

        // optional file (best-effort, silent on failure)
        if ($this->filePath !== null && $this->filePath !== '') {
            $dir = dirname($this->filePath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($this->filePath, $line . PHP_EOL, FILE_APPEND);
        }
    }
}
