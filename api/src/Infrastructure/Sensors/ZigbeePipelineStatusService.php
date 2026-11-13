<?php
declare(strict_types=1);

namespace App\Infrastructure\Sensors;

use App\Domain\Sensors\ZigbeePipelineHealth;
use App\Support\Config;

/**
 * ZigbeePipelineStatusService — F134.
 *
 * Combines the persisted pipeline status with the pure health policy. Used by
 * health/deep, device-status, Radar Studio and the CRM panel.
 */
final class ZigbeePipelineStatusService
{
    private ZigbeePipelineStatusReader $reader;
    private ZigbeePipelineHealth $policy;
    private int $staleSeconds;

    public function __construct(
        ?ZigbeePipelineStatusReader $reader = null,
        ?ZigbeePipelineHealth $policy = null,
        ?int $staleSeconds = null
    ) {
        $this->reader = $reader ?? new ZigbeePipelineStatusReader();
        $this->policy = $policy ?? new ZigbeePipelineHealth();
        $this->staleSeconds = $staleSeconds
            ?? max(1, (int) (Config::get('ZIGBEE_PIPELINE_STALE_SECONDS', '180') ?? 180));
    }

    /** @return array{prod:array<string,mixed>,dev:array<string,mixed>} */
    public function all(): array
    {
        $resolved = [];
        foreach ($this->reader->readAll() as $profile => $snapshot) {
            $resolved[$profile] = $this->policy->resolve($snapshot, time(), $this->staleSeconds);
        }
        return $resolved;
    }

    /** @return array<string,mixed> */
    public function forProfile(?string $profile): array
    {
        $profiles = $this->all();
        $key = strtolower(trim((string) $profile));
        if ($key === '' || $key === 'default') {
            $key = 'prod';
        }
        if (isset($profiles[$key])) {
            return $profiles[$key];
        }
        return $profiles['prod'] ?? $this->policy->resolve(
            ['profile' => $key, 'enabled' => false],
            time(),
            $this->staleSeconds
        );
    }

    /**
     * Resolve the pipeline that serves a device. `meta.mqtt_profile` is the
     * per-device override introduced by F125.1.2; absent/default means prod.
     *
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function forDevice(array $device): array
    {
        $meta = is_array($device['meta'] ?? null) ? $device['meta'] : [];
        $profile = $meta['mqtt_profile'] ?? 'prod';
        return $this->forProfile(is_string($profile) ? $profile : 'prod');
    }
}
