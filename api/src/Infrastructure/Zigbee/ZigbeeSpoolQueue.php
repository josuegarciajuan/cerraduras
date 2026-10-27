<?php
declare(strict_types=1);

namespace App\Infrastructure\Zigbee;

use DateTimeImmutable;
use Throwable;

/**
 * File-based queue for the Zigbee ingress spool (F91/RF-146).
 *
 * Layout: incoming -> processing -> processed | (retry -> incoming) | quarantine.
 * Transitions use rename() for atomicity; retry rewrites atomically.
 */
final class ZigbeeSpoolQueue
{
    public function __construct(private string $dir)
    {
        $this->ensureLayout();
    }

    private function ensureLayout(): void
    {
        foreach (['incoming', 'processing', 'processed', 'quarantine'] as $sub) {
            $path = $this->dir . DIRECTORY_SEPARATOR . $sub;
            if (!is_dir($path)) {
                mkdir($path, 0700, true);
            }
            @chmod($path, 0700);
        }
    }

    /** @return list<string> */
    public function pendingFiles(): array
    {
        $files = glob($this->path('incoming') . '/*.json') ?: [];
        sort($files, SORT_STRING);
        return array_map(static fn (string $f): string => basename($f), $files);
    }

    /**
     * Move one incoming file to processing and return its decoded record.
     * Invalid JSON is moved to quarantine and null is returned.
     *
     * @return array{file:string,record:array<string,mixed>}|null
     */
    public function claim(string $file): ?array
    {
        $safe = basename($file);
        $src = $this->path('incoming') . DIRECTORY_SEPARATOR . $safe;
        $dst = $this->path('processing') . DIRECTORY_SEPARATOR . $safe;
        if (!is_file($src)) {
            return null;
        }
        if (!rename($src, $dst)) {
            return null;
        }

        $raw = file_get_contents($dst);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($record)) {
            $this->quarantine($safe, null, 'invalid_json');
            return null;
        }
        return ['file' => $safe, 'record' => $record];
    }

    public function complete(string $file): void
    {
        $this->move('processing', 'processed', $file);
    }

    /**
     * Requeue a failed record with attempts+1. Never drops it silently.
     *
     * @param array<string,mixed> $record
     */
    public function retry(string $file, array $record, string $error): void
    {
        $record['attempts'] = (int) ($record['attempts'] ?? 0) + 1;
        $record['last_error'] = $error;
        $record['last_attempt_at'] = gmdate('Y-m-d\TH:i:s.v\Z');
        $this->writeAtomic('incoming', $file, $record);
        @unlink($this->path('processing') . DIRECTORY_SEPARATOR . basename($file));
    }

    public function quarantine(string $file, ?array $record, string $error): void
    {
        $safe = basename($file);
        if ($record !== null) {
            $record['last_error'] = $error;
            $record['quarantined_at'] = gmdate('Y-m-d\TH:i:s.v\Z');
            $this->writeAtomic('quarantine', $safe, $record);
            @unlink($this->path('processing') . DIRECTORY_SEPARATOR . $safe);
            @unlink($this->path('incoming') . DIRECTORY_SEPARATOR . $safe);
            return;
        }
        $this->move('processing', 'quarantine', $safe);
    }

    /**
     * Copy a quarantined/processed record back to incoming with attempts=0.
     * Returns the new filename or null.
     */
    public function requeue(string $sourceFile): ?string
    {
        $safe = basename($sourceFile);
        $candidates = [
            $this->path('quarantine') . DIRECTORY_SEPARATOR . $safe,
            $this->path('processed') . DIRECTORY_SEPARATOR . $safe,
            $this->path('processing') . DIRECTORY_SEPARATOR . $safe,
            $this->path('incoming') . DIRECTORY_SEPARATOR . $safe,
        ];
        foreach ($candidates as $source) {
            if (!is_file($source)) {
                continue;
            }
            $raw = file_get_contents($source);
            $record = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($record)) {
                return null;
            }
            $record['attempts'] = 0;
            $record['last_error'] = null;
            $record['replay'] = true;
            $record['replay_at'] = gmdate('Y-m-d\TH:i:s.v\Z');
            $record['replayed_from'] = $safe;
            $record['replayed_by'] = self::currentUser();
            $name = sprintf('%013d-%08d-%s.json', (int) (microtime(true) * 1000), random_int(0, 99999999), substr((string) ($record['payload_sha256'] ?? 'replay'), 0, 16));
            $this->writeAtomic('incoming', $name, $record);
            $this->appendReplayAudit([
                'at' => gmdate('Y-m-d\TH:i:s.v\Z'),
                'source' => $safe,
                'target' => $name,
                'by' => $record['replayed_by'],
                'payload_sha256' => $record['payload_sha256'] ?? null,
            ]);
            return $name;
        }
        return null;
    }

    /** @return array{incoming:int,processing:int,processed:int,quarantine:int} */
    public function counts(): array
    {
        return [
            'incoming' => count(glob($this->path('incoming') . '/*.json') ?: []),
            'processing' => count(glob($this->path('processing') . '/*.json') ?: []),
            'processed' => count(glob($this->path('processed') . '/*.json') ?: []),
            'quarantine' => count(glob($this->path('quarantine') . '/*.json') ?: []),
        ];
    }

    /**
     * @return string|null validation error
     */
    public static function validateRecord(array $record): ?string
    {
        if (($record['schema'] ?? null) !== 1) {
            return 'schema_mismatch';
        }
        if (!is_string($record['topic'] ?? null) || trim($record['topic']) === '') {
            return 'topic_missing';
        }
        if (!is_string($record['payload'] ?? null)) {
            return 'payload_missing';
        }
        if (strlen($record['payload']) > 65536) {
            return 'payload_too_large';
        }
        if (!is_string($record['payload_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $record['payload_sha256'])) {
            return 'hash_invalid';
        }
        if (hash('sha256', $record['payload']) !== $record['payload_sha256']) {
            return 'hash_mismatch';
        }
        try {
            new DateTimeImmutable((string) ($record['received_at'] ?? ''));
        } catch (Throwable) {
            return 'received_at_invalid';
        }
        if (!isset($record['attempts']) || !is_int($record['attempts']) || $record['attempts'] < 0) {
            return 'attempts_invalid';
        }
        return null;
    }

    private function move(string $from, string $to, string $file): void
    {
        $safe = basename($file);
        $src = $this->path($from) . DIRECTORY_SEPARATOR . $safe;
        $dst = $this->path($to) . DIRECTORY_SEPARATOR . $safe;
        if (is_file($src)) {
            @rename($src, $dst);
        }
    }

    /** @param array<string,mixed> $record */
    private function writeAtomic(string $sub, string $file, array $record): void
    {
        $safe = basename($file);
        $dir = $this->path($sub);
        $tmp = $dir . DIRECTORY_SEPARATOR . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($tmp, $json, LOCK_EX);
        @chmod($tmp, 0600);
        rename($tmp, $dir . DIRECTORY_SEPARATOR . $safe);
    }

    private static function currentUser(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && isset($info['name'])) {
                return (string) $info['name'];
            }
        }
        return (string) (getenv('USER') ?: getenv('LOGNAME') ?: 'unknown');
    }

    /** @param array<string,mixed> $entry */
    private function appendReplayAudit(array $entry): void
    {
        $file = $this->dir . DIRECTORY_SEPARATOR . 'replay.log';
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        @chmod($file, 0600);
    }

    private function path(string $sub): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $sub;
    }
}
