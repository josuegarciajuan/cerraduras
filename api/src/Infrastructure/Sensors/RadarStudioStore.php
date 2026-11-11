<?php
declare(strict_types=1);

namespace App\Infrastructure\Sensors;

use RuntimeException;

/**
 * RadarStudioStore — F125/F125.1.
 *
 * Estado efímero del Radar Studio por sala:
 *   - room-<id>.json         operación de autoajuste clásica.
 *   - wizard-room-<id>.json  calibración guiada (F125.1).
 *
 * Escritura atómica y flock por fichero. No guarda secretos ni datos de
 * negocio: solo la operación/estado en curso.
 */
final class RadarStudioStore
{
    public function __construct(private string $dir)
    {
    }

    /** @return array<string,mixed>|null */
    public function load(int $roomId): ?array
    {
        return $this->readState($this->path($roomId));
    }

    /** @param array<string,mixed> $state */
    public function save(int $roomId, array $state): void
    {
        $this->writeState($this->path($roomId), $state);
    }

    public function clear(int $roomId): void
    {
        $this->removeState($this->path($roomId));
    }

    /** @return array<string,mixed>|null */
    public function loadWizard(int $roomId): ?array
    {
        return $this->readState($this->wizardPath($roomId));
    }

    /** @param array<string,mixed> $state */
    public function saveWizard(int $roomId, array $state): void
    {
        $this->writeState($this->wizardPath($roomId), $state);
    }

    public function clearWizard(int $roomId): void
    {
        $this->removeState($this->wizardPath($roomId));
    }

    /**
     * Serializa una operación completa (lectura, publicación MQTT, escritura)
     * por sala. El callback recibe el estado actual.
     *
     * @template T
     * @param callable(?array<string,mixed>):T $fn
     * @return T
     */
    public function withLock(int $roomId, callable $fn)
    {
        return $this->withFileLock($this->path($roomId), function () use ($roomId, $fn) {
            return $fn($this->load($roomId));
        });
    }

    /**
     * Igual que withLock, pero para el estado de la calibración guiada.
     *
     * @template T
     * @param callable(?array<string,mixed>):T $fn
     * @return T
     */
    public function withWizardLock(int $roomId, callable $fn)
    {
        return $this->withFileLock($this->wizardPath($roomId), function () use ($roomId, $fn) {
            return $fn($this->loadWizard($roomId));
        });
    }

    /** @return array<string,mixed>|null */
    private function readState(string $path): ?array
    {
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
    private function writeState(string $path, array $state): void
    {
        $this->ensureDir();
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

    private function removeState(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withFileLock(string $path, callable $fn)
    {
        $this->ensureDir();
        $lockPath = $path . '.lock';
        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new RuntimeException('radar_studio_lock_failed');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('radar_studio_lock_busy');
            }
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function path(int $roomId): string
    {
        return rtrim($this->dir, '/') . '/room-' . max(1, $roomId) . '.json';
    }

    private function wizardPath(int $roomId): string
    {
        return rtrim($this->dir, '/') . '/wizard-room-' . max(1, $roomId) . '.json';
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
