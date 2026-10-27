<?php
declare(strict_types=1);

namespace App\Domain\Devices;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * DeviceLivenessService (F92/RF-152..RF-155).
 *
 * Pure policy that decides whether a device is online, offline or unknown.
 * It never performs network I/O: probes are executed by the caller and their
 * persisted result is read from `online_state` + `online_probed_at`.
 *
 * The category is derived from the declared transport, not from `kind`, except
 * for legacy rows where `transport` is NULL (F91/RF-144.5 compatibility).
 */
final class DeviceLivenessService
{
    public const CATEGORY_CAMERA = 'camera';
    public const CATEGORY_ZIGBEE = 'zigbee';
    public const CATEGORY_TUYA   = 'tuya';
    public const CATEGORY_LOCAL  = 'local';
    public const CATEGORY_OTHER  = 'other';

    public const SOURCE_CAMERA_PROBE       = 'camera_probe';
    public const SOURCE_ZIGBEE_AVAILABILITY = 'zigbee_availability';
    public const SOURCE_ZIGBEE_PUSH        = 'zigbee_push';
    public const SOURCE_TUYA_PROBE         = 'tuya_probe';
    public const SOURCE_TUYA_PUSH          = 'tuya_push';
    public const SOURCE_ESP32_HEARTBEAT    = 'esp32_heartbeat';
    public const SOURCE_NONE               = 'none';

    public const STATE_ONLINE  = 'online';
    public const STATE_OFFLINE = 'offline';
    public const STATE_UNKNOWN = 'unknown';

    /** @var list<string> */
    private const TUYA_KINDS = [
        Device::KIND_PRESENCE,
        Device::KIND_PROXIMITY,
        Device::KIND_SWITCH,
    ];

    /** @var list<string> */
    private const ESP32_KINDS = [
        Device::KIND_RPI,
        Device::KIND_SCANNER,
        Device::KIND_LOCK,
    ];

    /**
     * @param array<string,mixed> $row Keys: kind, transport, last_seen_at,
     *        online_state, online_probed_at, meta, integration_status.
     */
    public function category(array $row): string
    {
        $kind = strtoupper((string) ($row['kind'] ?? ''));
        if ($kind === Device::KIND_CAMERA) {
            return self::CATEGORY_CAMERA;
        }

        $transport = self::transportOf($row);
        if ($transport === Device::TRANSPORT_ZIGBEE2MQTT) {
            return self::CATEGORY_ZIGBEE;
        }
        if ($transport === Device::TRANSPORT_TUYA) {
            return self::CATEGORY_TUYA;
        }
        if ($transport === Device::TRANSPORT_LOCAL || $transport === Device::TRANSPORT_SIMULATED) {
            return self::CATEGORY_LOCAL;
        }

        // Legacy rows: preserve the pre-F92 behavior when transport is NULL.
        if (in_array($kind, self::TUYA_KINDS, true)) {
            return self::CATEGORY_TUYA;
        }
        if (in_array($kind, self::ESP32_KINDS, true)) {
            return self::CATEGORY_LOCAL;
        }

        return self::CATEGORY_OTHER;
    }

    /** @param array<string,mixed> $row */
    public function isTuyaCloud(array $row): bool
    {
        return $this->category($row) === self::CATEGORY_TUYA;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{state:string,online:bool|null,source:string,reason:?string}
     */
    public function resolve(
        array $row,
        ?int $now = null,
        int $probeTtlSeconds = 600,
        int $pushTtlSeconds = 600,
        int $presenceStaleSeconds = 300
    ): array {
        $now ??= time();
        $category = $this->category($row);

        if ($category === self::CATEGORY_CAMERA) {
            return $this->resolveCamera($row, $now, $probeTtlSeconds);
        }
        if ($category === self::CATEGORY_ZIGBEE) {
            return $this->resolveZigbee($row, $now, $probeTtlSeconds, $presenceStaleSeconds);
        }
        if ($category === self::CATEGORY_TUYA) {
            return $this->resolveTuya($row, $now, $probeTtlSeconds, $pushTtlSeconds);
        }
        if ($category === self::CATEGORY_LOCAL) {
            return $this->resolveLocal($row, $now, $pushTtlSeconds);
        }

        return $this->unknown(self::SOURCE_NONE, 'unknown_transport');
    }

    /** @param array<string,mixed> $row */
    private function resolveCamera(array $row, int $now, int $probeTtlSeconds): array
    {
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        if (($meta['enabled'] ?? true) === false) {
            return $this->unknown(self::SOURCE_CAMERA_PROBE, 'disabled');
        }
        $rtsp = is_string($meta['rtsp_url'] ?? null) ? trim((string) $meta['rtsp_url']) : '';
        if ($rtsp === '') {
            return $this->unknown(self::SOURCE_CAMERA_PROBE, 'no_rtsp_url');
        }

        $probe = $this->freshProbe($row, $now, $probeTtlSeconds);
        if ($probe === null) {
            return $this->unknown(self::SOURCE_CAMERA_PROBE, 'not_probed');
        }
        return $this->state($probe, self::SOURCE_CAMERA_PROBE);
    }

    /** @param array<string,mixed> $row */
    private function resolveZigbee(
        array $row,
        int $now,
        int $probeTtlSeconds,
        int $presenceStaleSeconds
    ): array {
        // Real availability from Zigbee2MQTT wins while it is fresh.
        $probe = $this->freshProbe($row, $now, $probeTtlSeconds);
        if ($probe !== null) {
            return $this->state($probe, self::SOURCE_ZIGBEE_AVAILABILITY);
        }

        $integration = is_array($row['integration_status'] ?? null) ? $row['integration_status'] : null;
        if ($integration !== null) {
            $status = (string) ($integration['status'] ?? '');
            $reason = $integration['reason'] ?? null;
            switch ($status) {
                case 'operational':
                    return $this->state(true, self::SOURCE_ZIGBEE_PUSH);
                case 'degraded':
                    return $this->unknown(self::SOURCE_ZIGBEE_PUSH, is_string($reason) ? $reason : 'stale');
                case 'waiting_signal':
                    return $this->unknown(self::SOURCE_ZIGBEE_PUSH, 'no_signal');
                case 'pending_mapping':
                    return $this->unknown(self::SOURCE_ZIGBEE_PUSH, 'missing_mapping');
                case 'attention':
                    return $this->unknown(self::SOURCE_ZIGBEE_PUSH, 'quarantine');
                case 'draft':
                    return $this->unknown(self::SOURCE_ZIGBEE_PUSH, 'no_pack');
                case 'associated':
                    return $this->unknown(self::SOURCE_ZIGBEE_PUSH, 'unknown_transport');
                default:
                    return $this->unknown(self::SOURCE_ZIGBEE_PUSH, 'no_signal');
            }
        }

        // Fallback for callers without the integration snapshot.
        $lastSeen = $this->ageSeconds($row['last_seen_at'] ?? null, $now);
        if ($lastSeen === null) {
            return $this->unknown(self::SOURCE_ZIGBEE_PUSH, 'no_signal');
        }
        $kind = strtoupper((string) ($row['kind'] ?? ''));
        if ($kind === Device::KIND_PRESENCE && $lastSeen > $presenceStaleSeconds) {
            return $this->unknown(self::SOURCE_ZIGBEE_PUSH, 'stale');
        }
        return $this->state(true, self::SOURCE_ZIGBEE_PUSH);
    }

    /** @param array<string,mixed> $row */
    private function resolveTuya(
        array $row,
        int $now,
        int $probeTtlSeconds,
        int $pushTtlSeconds
    ): array {
        $kind = strtoupper((string) ($row['kind'] ?? ''));
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        $isPush = ($kind === Device::KIND_PROXIMITY)
            || (($meta['presence_source'] ?? null) === 'push');

        if ($isPush) {
            $lastSeen = $this->ageSeconds($row['last_seen_at'] ?? null, $now);
            if ($lastSeen !== null && $lastSeen <= $pushTtlSeconds) {
                return $this->state(true, self::SOURCE_TUYA_PUSH);
            }
        }

        $probe = $this->freshProbe($row, $now, $probeTtlSeconds);
        if ($probe === null) {
            return $this->unknown(self::SOURCE_TUYA_PROBE, 'no_signal');
        }
        return $this->state($probe, self::SOURCE_TUYA_PROBE);
    }

    /** @param array<string,mixed> $row */
    private function resolveLocal(array $row, int $now, int $ttlSeconds): array
    {
        $age = $this->ageSeconds($row['last_seen_at'] ?? null, $now);
        if ($age === null) {
            return $this->unknown(self::SOURCE_NONE, 'no_signal');
        }
        if ($age <= $ttlSeconds) {
            return $this->state(true, self::SOURCE_ESP32_HEARTBEAT);
        }
        return $this->state(false, self::SOURCE_ESP32_HEARTBEAT);
    }

    /**
     * @param array<string,mixed> $row
     * @return bool|null null when there is no fresh persisted probe
     */
    private function freshProbe(array $row, int $now, int $ttlSeconds): ?bool
    {
        $probedAge = $this->ageSeconds($row['online_probed_at'] ?? null, $now);
        if ($probedAge === null || $probedAge > $ttlSeconds) {
            return null;
        }
        $raw = $row['online_state'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        return (int) $raw === 1;
    }

    private function ageSeconds(mixed $value, int $now): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
        return max(0, $now - $dt->getTimestamp());
    }

    private function state(bool $online, string $source): array
    {
        return [
            'state' => $online ? self::STATE_ONLINE : self::STATE_OFFLINE,
            'online' => $online,
            'source' => $source,
            'reason' => null,
        ];
    }

    private function unknown(string $source, string $reason): array
    {
        return [
            'state' => self::STATE_UNKNOWN,
            'online' => null,
            'source' => $source,
            'reason' => $reason,
        ];
    }

    /** @param array<string,mixed> $row */
    private static function transportOf(array $row): string
    {
        $value = strtoupper(trim((string) ($row['transport'] ?? '')));
        return $value === Device::TRANSPORT_UNKNOWN ? '' : $value;
    }
}
