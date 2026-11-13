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
    /** @var array{incoming:int,processing:int,quarantine:int}|null */
    private ?array $lightCache = null;
    private int $lightCacheAt = 0;

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
     * F138: recupera claims abandonados por un crash del worker.
     *
     * Un fichero reclamado a `processing` no vuelve a `incoming` por sí solo.
     * Si su mtime supera el umbral, se devuelve a la cola para no perder el
     * mensaje confirmado al broker. El umbral evita mover un fichero que otro
     * worker está procesando ahora.
     */
    public function recoverStaleClaims(int $olderThanSeconds = 300): int
    {
        $cutoff = time() - max(1, $olderThanSeconds);
        $files = glob($this->path('processing') . '/*.json') ?: [];
        $recovered = 0;
        foreach ($files as $src) {
            $mtime = @filemtime($src);
            if ($mtime === false || $mtime > $cutoff) {
                continue;
            }
            $base = basename($src);
            $dst = $this->path('incoming') . DIRECTORY_SEPARATOR . $base;
            if (file_exists($dst)) {
                $dst = $this->path('incoming') . DIRECTORY_SEPARATOR
                    . pathinfo($base, PATHINFO_FILENAME)
                    . '-recovered-' . time() . '.json';
            }
            if (@rename($src, $dst)) {
                $recovered++;
            }
        }
        return $recovered;
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

    /**
     * Devuelve un registro a `incoming` sin consumir intentos.
     * Se usa para errores transitorios de infraestructura (p. ej. conexión
     * MySQL perdida), que no deben contar como mensaje veneno.
     *
     * @param array<string,mixed> $record
     */
    public function defer(string $file, array $record, string $error): void
    {
        $record['last_error'] = $error;
        $record['deferred_at'] = gmdate('Y-m-d\TH:i:s.v\Z');
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

    /**
     * Conteo completo. OJO: `processed` puede contener cientos de miles de
     * ficheros; no debe llamarse por cada mensaje. El worker usa `lightCounts()`
     * y mantiene el total de `processed` como contador persistido.
     *
     * @return array{incoming:int,processing:int,processed:int,quarantine:int}
     */
    public function counts(): array
    {
        return [
            'incoming' => $this->countFiles('incoming'),
            'processing' => $this->countFiles('processing'),
            'processed' => $this->countFiles('processed'),
            'quarantine' => $this->countFiles('quarantine'),
        ];
    }

    /**
     * Conteo barato para el estado frecuente del worker: solo `incoming`,
     * `processing` y `quarantine` (dirs pequeños), con caché corta. `processed`
     * lo aporta el contador persistido del worker.
     *
     * @return array{incoming:int,processing:int,quarantine:int}
     */
    public function lightCounts(int $ttlSeconds = 5): array
    {
        $now = time();
        if ($this->lightCache !== null && ($now - $this->lightCacheAt) < max(1, $ttlSeconds)) {
            return $this->lightCache;
        }
        $this->lightCache = [
            'incoming' => $this->countFiles('incoming'),
            'processing' => $this->countFiles('processing'),
            'quarantine' => $this->countFiles('quarantine'),
        ];
        $this->lightCacheAt = $now;
        return $this->lightCache;
    }

    public function countFiles(string $subdir): int
    {
        $dir = $this->path($subdir);
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        foreach (new \DirectoryIterator($dir) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.json')) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Elimina ficheros de un subdirectorio más antiguos que `$days`.
     * `processed` y `quarantine` son auditoría operativa, no datos de negocio.
     */
    public function pruneOlderThan(string $subdir, int $days, bool $dryRun = false): int
    {
        if ($days <= 0) {
            return 0;
        }
        $dir = $this->path($subdir);
        if (!is_dir($dir)) {
            return 0;
        }
        $cutoff = time() - ($days * 86400);
        $removed = 0;
        foreach (new \DirectoryIterator($dir) as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.json')) {
                continue;
            }
            if ($file->getMTime() >= $cutoff) {
                continue;
            }
            if (!$dryRun && @unlink($file->getPathname())) {
                $removed++;
            } elseif ($dryRun) {
                $removed++;
            }
        }
        $this->lightCache = null;
        return $removed;
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
