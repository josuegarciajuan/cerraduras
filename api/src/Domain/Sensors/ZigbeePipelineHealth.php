<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * ZigbeePipelineHealth — F134.
 *
 * Pure policy for the local Zigbee ingestion chain:
 *   Zigbee2MQTT -> ingress -> spool -> API/DB
 *
 * It never performs network I/O. The caller supplies a snapshot with the
 * persisted status files and the systemd unit state. The policy distinguishes
 * a stopped/stale pipeline from a sensor that simply has no recent reports:
 * a quiet sensor with a healthy pipeline is not a pipeline failure, while a
 * stopped ingress/spool invalidates the "sensor offline" conclusion.
 */
final class ZigbeePipelineHealth
{
    public const STATE_OK           = 'ok';
    public const STATE_STOPPED      = 'stopped';
    public const STATE_DISCONNECTED = 'disconnected';
    public const STATE_STALE        = 'stale';
    public const STATE_UNKNOWN      = 'unknown';
    public const STATE_DISABLED     = 'disabled';

    /**
     * @param array<string,mixed> $snapshot Keys: profile, enabled, active,
     *        connected, status_updated_at, last_event_at, last_processed_at,
     *        pending, quarantine, units.
     * @return array{
     *   profile:string,state:string,healthy:bool,reason:?string,enabled:bool,
     *   active:?bool,connected:bool,status_age_seconds:?int,status_updated_at:?string,
     *   last_event_age_seconds:?int,last_event_at:?string,last_processed_at:?string,
     *   pending:int,quarantine:int,units:array<string,mixed>
     * }
     */
    public function resolve(array $snapshot, int $now, int $staleSeconds = 180): array
    {
        $staleSeconds = max(1, $staleSeconds);
        $enabled = (bool) ($snapshot['enabled'] ?? false);
        $active = array_key_exists('active', $snapshot) && $snapshot['active'] !== null
            ? (bool) $snapshot['active']
            : null;
        $connected = (bool) ($snapshot['connected'] ?? false);
        $pending = max(0, (int) ($snapshot['pending'] ?? 0));
        $quarantine = max(0, (int) ($snapshot['quarantine'] ?? 0));
        $statusAge = $this->ageSeconds($snapshot['status_updated_at'] ?? null, $now);
        $eventAge = $this->ageSeconds($snapshot['last_event_at'] ?? null, $now);

        $state = self::STATE_OK;
        $healthy = true;
        $reason = null;

        if (!$enabled) {
            $state = self::STATE_DISABLED;
            $reason = 'disabled';
        } elseif ($active === false) {
            // The process is not running. This is the case that used to be
            // hidden by a stale `connected:true` status file.
            $state = self::STATE_STOPPED;
            $healthy = false;
            $reason = 'service_inactive';
        } elseif ($active === null && $statusAge === null) {
            $state = self::STATE_UNKNOWN;
            $healthy = false;
            $reason = 'no_status';
        } elseif ($active === null && $statusAge > $staleSeconds) {
            $state = self::STATE_STALE;
            $healthy = false;
            $reason = 'status_stale';
        } elseif (!$connected) {
            $state = self::STATE_DISCONNECTED;
            $healthy = false;
            $reason = 'ingress_disconnected';
        } elseif ($pending > 0 && $statusAge !== null && $statusAge > $staleSeconds) {
            // The process is alive but has stopped draining its queue.
            $state = self::STATE_STALE;
            $healthy = false;
            $reason = 'queue_stalled';
        }

        return [
            'profile' => (string) ($snapshot['profile'] ?? 'prod'),
            'state' => $state,
            'healthy' => $healthy,
            'reason' => $reason,
            'enabled' => $enabled,
            'active' => $active,
            'connected' => $connected,
            'status_age_seconds' => $statusAge,
            'status_updated_at' => $snapshot['status_updated_at'] ?? null,
            'last_event_age_seconds' => $eventAge,
            'last_event_at' => $snapshot['last_event_at'] ?? null,
            'last_processed_at' => $snapshot['last_processed_at'] ?? null,
            'pending' => $pending,
            'quarantine' => $quarantine,
            'units' => is_array($snapshot['units'] ?? null) ? $snapshot['units'] : [],
        ];
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
}
