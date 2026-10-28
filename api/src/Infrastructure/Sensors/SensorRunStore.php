<?php
declare(strict_types=1);

namespace App\Infrastructure\Sensors;

use RuntimeException;

/**
 * SensorRunStore — F94/RF-160.
 *
 * Persistencia en fichero de las corridas de verificación física:
 *   <dir>/<run_id>.json   (estado completo, escritura atómica)
 *   <dir>/<run_id>.jsonl  (log append-only: pasos, validaciones y muestras)
 *
 * Sin BD, sin procesos de fondo. El `run_id` se valida por whitelist para
 * impedir path traversal.
 */
final class SensorRunStore
{
    public function __construct(private string $dir)
    {
    }

    public function isValidId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id) === 1;
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public function create(array $meta): array
    {
        $this->ensureDir();
        $room = (int) ($meta['room_id'] ?? 0);
        $stamp = gmdate('Ymd\THis\Z');
        $suffix = bin2hex(random_bytes(3));
        $runId = ($room > 0 ? $room : 'x') . '-' . $stamp . '-' . $suffix;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $record = [
            'run_id' => $runId,
            'catalog' => (string) ($meta['catalog'] ?? 'sensores'),
            'catalog_version' => (int) ($meta['catalog_version'] ?? 1),
            'room_id' => $room,
            'room' => (array) ($meta['room'] ?? []),
            'operator' => (string) ($meta['operator'] ?? ''),
            'mode' => (string) ($meta['mode'] ?? 'universal'),
            'plan' => array_values((array) ($meta['plan'] ?? [])),
            'status' => 'running',
            'created_at' => $now,
            'updated_at' => $now,
            'calibration' => [
                'initial_settings' => (array) ($meta['initial_settings'] ?? []),
                'initial_settings_at' => $meta['initial_settings_at'] ?? null,
                'devices' => (array) ($meta['devices'] ?? []),
            ],
            'steps' => [],
            'summary' => ['pass' => 0, 'fail' => 0, 'skip' => 0, 'unverified' => 0, 'pending' => 0, 'total' => 0, 'green' => false],
            'verdict' => null,
            'soak' => ['status' => 'PENDING', 'since' => null, 'checked_at' => null, 'result' => null],
        ];
        $this->save($record);
        $this->appendLog($runId, ['type' => 'run_start', 'at' => $now,
            'room_id' => $room, 'operator' => $record['operator']]);
        return $record;
    }

    /** @return array<string,mixed>|null */
    public function load(string $id): ?array
    {
        if (!$this->isValidId($id)) {
            return null;
        }
        $path = $this->path($id);
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $record */
    public function save(array $record): void
    {
        $this->ensureDir();
        $id = (string) ($record['run_id'] ?? '');
        if (!$this->isValidId($id)) {
            throw new RuntimeException('invalid_run_id');
        }
        $record['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $path = $this->path($id);
        $tmp = $path . '.tmp';
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('run_save_failed');
        }
        @chmod($path, 0600);
    }

    /** @param array<string,mixed> $entry */
    public function appendLog(string $id, array $entry): void
    {
        if (!$this->isValidId($id)) {
            return;
        }
        $this->ensureDir();
        $entry['at'] = $entry['at'] ?? gmdate('Y-m-d\TH:i:s.v\Z');
        $log = $this->path($id, 'jsonl');
        @file_put_contents(
            $log,
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
        @chmod($log, 0600);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRuns(int $limit = 50): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }
        $files = glob($this->dir . '/*.json') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $out = [];
        foreach (array_slice($files, 0, max(1, $limit)) as $file) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (!is_array($decoded)) {
                continue;
            }
            $out[] = [
                'run_id' => (string) ($decoded['run_id'] ?? basename($file, '.json')),
                'catalog_version' => (int) ($decoded['catalog_version'] ?? 1),
                'room_id' => (int) ($decoded['room_id'] ?? 0),
                'operator' => (string) ($decoded['operator'] ?? ''),
                'status' => (string) ($decoded['status'] ?? ''),
                'created_at' => (string) ($decoded['created_at'] ?? ''),
                'updated_at' => (string) ($decoded['updated_at'] ?? ''),
                'summary' => (array) ($decoded['summary'] ?? []),
            ];
        }
        return $out;
    }

    /**
     * Ejecuta una mutación bajo lock exclusivo del fichero de corrida.
     * Evita lecturas-modificación-escritura concurrentes.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $fn
     * @return array<string,mixed>
     */
    public function withLock(string $id, callable $fn): array
    {
        $this->ensureDir();
        $lockDir = $this->dir . '/locks';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0700, true);
        }
        $lockPath = $lockDir . '/' . $id . '.lock';
        $fh = @fopen($lockPath, 'c');
        if ($fh === false) {
            throw new RuntimeException('run_lock_unavailable');
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                throw new RuntimeException('run_lock_failed');
            }
            $record = $this->load($id);
            if ($record === null) {
                throw new RuntimeException('run_not_found');
            }
            $record = $fn($record);
            $this->save($record);
            return $record;
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    /**
     * Busca un intento activo en cualquier corrida en curso de la sala.
     *
     * @return array{run_id:string,step_id:string,attempt_id:string}|null
     */
    public function findActiveAttemptForRoom(int $roomId): ?array
    {
        if (!is_dir($this->dir)) {
            return null;
        }
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $run = json_decode((string) @file_get_contents($file), true);
            if (!is_array($run) || (int) ($run['room_id'] ?? 0) !== $roomId) {
                continue;
            }
            if (($run['status'] ?? '') !== 'running') {
                continue;
            }
            foreach ((array) ($run['steps'] ?? []) as $stepId => $step) {
                $active = $step['active_attempt'] ?? null;
                if (is_array($active) && !empty($active['attempt_id'])) {
                    return [
                        'run_id' => (string) ($run['run_id'] ?? ''),
                        'step_id' => (string) $stepId,
                        'attempt_id' => (string) $active['attempt_id'],
                    ];
                }
            }
        }
        return null;
    }

    public function logPath(string $id): string
    {
        return $this->path($id, 'jsonl');
    }

    private function path(string $id, string $ext = 'json'): string
    {
        return $this->dir . '/' . $id . '.' . $ext;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException('run_dir_unavailable');
        }
        @chmod($this->dir, 0700);
    }
}
