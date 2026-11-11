<?php
declare(strict_types=1);

namespace App\Infrastructure\Sensors;

use RuntimeException;

/**
 * RadarStudioStore — F125.
 *
 * Estado efímero de un asistente del Radar Studio (paseo, soak, reentrada).
 * Vive en `api/run/radar-studio/room-<id>.json` y se serializa con flock para
 * que dos pestañas no puedan conducir la misma calibración a la vez.
 *
 * No guarda secretos ni datos de negocio: solo la operación en curso.
 */
final class RadarStudioStore
{
    public function __construct(private string $dir)
    {
    }

    /** @return array<string,mixed>|null */
    public function load(int $roomId): ?array
    {
        $path = $this->path($roomId);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $state */
    public function save(int $roomId, array $state): void
    {
        $this->ensureDir();
        $path = $this->path($roomId);
        $tmp = $path . '.tmp-' . getmypid();
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('radar_studio_state_write_failed');
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('radar_studio_state_rename_failed');
        }
        @chmod($path, 0640);
    }

    public function clear(int $roomId): void
    {
        $path = $this->path($roomId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Serializa una operación completa (lectura, publicación MQTT, escritura)
     * por sala. El callback recibe el estado actual y debe devolver lo que se
     * quiera devolver al llamante.
     *
     * @template T
     * @param callable(?array<string,mixed>):T $fn
     * @return T
     */
    public function withLock(int $roomId, callable $fn)
    {
        $this->ensureDir();
        $lockPath = $this->path($roomId) . '.lock';
        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new RuntimeException('radar_studio_lock_failed');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('radar_studio_lock_busy');
            }
            return $fn($this->load($roomId));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function path(int $roomId): string
    {
        return rtrim($this->dir, '/') . '/room-' . max(1, $roomId) . '.json';
    }

    private function ensureDir(): void
    {
        if (is_dir($this->dir)) {
            return;
        }
        if (!mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new RuntimeException('radar_studio_dir_failed');
        }
    }
}
