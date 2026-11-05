<?php
declare(strict_types=1);

namespace App\Domain\Warehouse;

/**
 * RemoteDoorOpenPolicy — F116/RF-293.
 *
 * Pure rules for the ESP32 local-relay remote open path. No DB, no HTTP.
 *
 * The capability is advertised by the firmware in its heartbeat and persisted
 * in `devices.meta_json.remote_open_capable`. The version check is a second
 * guard so a downgraded firmware (which does not understand the `open`
 * command) is never sent one.
 */
final class RemoteDoorOpenPolicy
{
    public const META_KEY = 'remote_open_capable';
    public const MIN_MAJOR = 2;
    public const MIN_MINOR = 7;

    public static function isCapable(?array $meta, ?string $firmwareVersion): bool
    {
        if (!is_array($meta) || ($meta[self::META_KEY] ?? null) !== true) {
            return false;
        }
        return self::isSupportedVersion($firmwareVersion);
    }

    /**
     * Accepts `2.7-ota`, `2.8`, `3.0.1`... Rejects `2.6-ota` and garbage.
     */
    public static function isSupportedVersion(?string $version): bool
    {
        if ($version === null) {
            return false;
        }
        $value = trim($version);
        if ($value === '') {
            return false;
        }
        if (preg_match('/^(\\d+)\\.(\\d+)(?:[.\\-+][0-9A-Za-z._-]*)?$/', $value, $m) !== 1) {
            return false;
        }
        $major = (int) $m[1];
        $minor = (int) $m[2];
        return $major > self::MIN_MAJOR
            || ($major === self::MIN_MAJOR && $minor >= self::MIN_MINOR);
    }

    /**
     * The command payload stored in `device_commands.payload_json`.
     *
     * @return array<string,mixed>
     */
    public static function payload(int $roomId, string $actor, string $requestedAt): array
    {
        return [
            'room_id' => $roomId,
            'requested_by' => $actor,
            'requested_at' => $requestedAt,
            'source' => 'timbre',
            'protocol' => 'remote-open-v1',
        ];
    }
}
