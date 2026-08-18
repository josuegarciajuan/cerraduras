<?php
declare(strict_types=1);

namespace Ws\Support\Logger;

/**
 * JSON structured logger for WS-VB6.
 * Minimal port of the API logger.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private int     $threshold;
    private ?string $filePath;

    public function __construct(string $level = 'info', ?string $filePath = null)
    {
        $this->threshold = self::LEVELS[strtolower($level)] ?? 1;
        $this->filePath  = $filePath;
    }

    /** @param array<string,mixed> $ctx */
    public function debug(string $msg, array $ctx = []): void { $this->log('debug', $msg, $ctx); }
    /** @param array<string,mixed> $ctx */
    public function info(string $msg, array $ctx = []): void  { $this->log('info',  $msg, $ctx); }
    /** @param array<string,mixed> $ctx */
    public function warning(string $msg, array $ctx = []): void { $this->log('warning', $msg, $ctx); }
    /** @param array<string,mixed> $ctx */
    public function error(string $msg, array $ctx = []): void { $this->log('error', $msg, $ctx); }

    /** @param array<string,mixed> $ctx */
    private function log(string $level, string $msg, array $ctx): void
    {
        if ((self::LEVELS[$level] ?? 0) < $this->threshold) {
            return;
        }
        $entry = array_merge([
            'ts'      => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.vP'),
            'level'   => strtoupper($level),
            'service' => 'ws-vb6',
            'msg'     => $msg,
        ], $ctx);
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if ($this->filePath !== null) {
            @file_put_contents($this->filePath, $line, FILE_APPEND | LOCK_EX);
        }
        $stdout = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');
        fwrite($stdout, $line);
    }
}
