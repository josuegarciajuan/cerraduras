<?php
declare(strict_types=1);

namespace App\Infrastructure\Sensors;

use App\Support\Config;
use Throwable;

/**
 * ZigbeePipelineStatusReader — F134.
 *
 * Reads the persisted status files of the production and dev Zigbee
 * pipelines and checks whether their systemd units are active. It performs
 * no MQTT requests, no DB writes and no Tuya calls.
 */
final class ZigbeePipelineStatusReader
{
    private string $apiDir;
    /** @var callable(string):?bool|null */
    private $unitActive;
    private string $devEnvFile;

    /**
     * @param callable(string):?bool|null $unitActive Optional override for tests
     *        or environments without systemd. Returns true/false/null.
     */
    public function __construct(
        ?string $apiDir = null,
        ?callable $unitActive = null,
        ?string $devEnvFile = null
    ) {
        $this->apiDir = rtrim($apiDir ?? dirname(__DIR__, 3), '/');
        $this->unitActive = $unitActive;
        $this->devEnvFile = $devEnvFile ?? '/etc/cerraduras/zigbee-dev.env';
    }

    /** @return array{prod:array<string,mixed>,dev:array<string,mixed>} */
    public function readAll(): array
    {
        return [
            'prod' => $this->readProd(),
            'dev' => $this->readDev(),
        ];
    }

    /** @return array<string,mixed> */
    private function readProd(): array
    {
        $ingress = $this->statusFile($this->configPath('ZIGBEE_INGRESS_STATUS_FILE', 'run/zigbee-ingress-status.json'));
        $spool = $this->statusFile($this->configPath('ZIGBEE_SPOOL_STATUS_FILE', 'run/zigbee-spool-status.json'));
        $enabled = Config::getBool('ZIGBEE_INGRESS_ENABLED', false)
            || Config::getBool('ZIGBEE_SPOOL_ENABLED', false);

        return $this->snapshot('prod', $enabled, [
            'ingress' => 'cerraduras-zigbee-ingress',
            'spool' => 'cerraduras-zigbee-spool',
        ], $ingress, $spool);
    }

    /** @return array<string,mixed> */
    private function readDev(): array
    {
        $ingress = $this->statusFile('run/zigbee-ingress-status-dev.json');
        $spool = $this->statusFile('run/zigbee-spool-status-dev.json');
        $enabled = is_file($this->devEnvFile) || $ingress !== null || $spool !== null;

        return $this->snapshot('dev', $enabled, [
            'ingress' => 'cerraduras-zigbee-ingress-dev',
            'spool' => 'cerraduras-zigbee-spool-dev',
        ], $ingress, $spool);
    }

    /**
     * @param array<string,string> $units
     * @param array<string,mixed>|null $ingress
     * @param array<string,mixed>|null $spool
     * @return array<string,mixed>
     */
    private function snapshot(string $profile, bool $enabled, array $units, ?array $ingress, ?array $spool): array
    {
        $unitStates = [];
        foreach ($units as $name => $unit) {
            $unitStates[$name] = $this->isActive($unit);
        }
        $states = array_values($unitStates);
        $hasFalse = in_array(false, $states, true);
        $hasUnknown = in_array(null, $states, true);
        $active = $hasFalse ? false : ($hasUnknown ? null : true);

        $counts = is_array($spool['counts'] ?? null) ? $spool['counts'] : [];
        $pending = (int) ($counts['incoming'] ?? 0) + (int) ($counts['processing'] ?? 0);
        $quarantine = (int) ($counts['quarantine'] ?? 0);

        $statusUpdatedAt = $this->latest([
            $ingress['updated_at'] ?? null,
            $spool['updated_at'] ?? null,
        ]);
        $lastEventAt = $this->latest([
            $ingress['last_received_at'] ?? null,
            $spool['last_processed_at'] ?? null,
        ]);

        return [
            'profile' => $profile,
            'enabled' => $enabled,
            'active' => $active,
            'connected' => (bool) ($ingress['connected'] ?? false),
            'status_updated_at' => $statusUpdatedAt,
            'last_event_at' => $lastEventAt,
            'last_processed_at' => $spool['last_processed_at'] ?? null,
            'pending' => $pending,
            'quarantine' => $quarantine,
            'units' => $unitStates,
        ];
    }

    private function isActive(string $unit): ?bool
    {
        if ($this->unitActive !== null) {
            try {
                return ($this->unitActive)($unit);
            } catch (Throwable) {
                return null;
            }
        }
        if (!function_exists('exec') || !function_exists('shell_exec')) {
            return null;
        }
        $which = @shell_exec('command -v systemctl 2>/dev/null');
        if (!is_string($which) || trim($which) === '') {
            return null;
        }
        $output = [];
        $code = 0;
        @exec('systemctl is-active --quiet ' . escapeshellarg($unit) . ' 2>/dev/null', $output, $code);
        return $code === 0;
    }

    /** @return array<string,mixed>|null */
    private function statusFile(string $path): ?array
    {
        $absolute = $this->absolute($path);
        if (!is_file($absolute)) {
            return null;
        }
        $raw = @file_get_contents($absolute);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function configPath(string $key, string $fallback): string
    {
        $value = Config::get($key, $fallback);
        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    private function absolute(string $path): string
    {
        if ($path === '') {
            return $path;
        }
        if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }
        return $this->apiDir . '/' . ltrim($path, '/');
    }

    /** @param list<mixed> $values */
    private function latest(array $values): ?string
    {
        $candidates = [];
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $candidates[] = $value;
            }
        }
        if ($candidates === []) {
            return null;
        }
        sort($candidates, SORT_STRING);
        return $candidates[count($candidates) - 1];
    }
}
