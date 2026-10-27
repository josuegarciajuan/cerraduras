<?php
declare(strict_types=1);

namespace App\Domain\Devices;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * DeviceIntegrationStatusService (F91/RF-144.5/RF-144.6).
 *
 * Derives a provisioning/health status from data already persisted:
 *   - device transport and pack assignment
 *   - the configured MQTT map (does it contain this external_id?)
 *   - devices.last_seen_at (last received event, not a probe)
 *   - quarantined spool records (if the ingress spool exists)
 *
 * It NEVER performs network I/O, MQTT requests, Tuya probes or polling. A quiet
 * battery door or relay is not degraded just because it has not reported for a
 * while: those devices are edge-triggered. Only periodic presence reports are
 * subject to a freshness threshold.
 */
final class DeviceIntegrationStatusService
{
    private const STATUS_DRAFT           = 'draft';
    private const STATUS_ASSOCIATED      = 'associated';
    private const STATUS_PENDING_MAPPING = 'pending_mapping';
    private const STATUS_WAITING_SIGNAL  = 'waiting_signal';
    private const STATUS_OPERATIONAL     = 'operational';
    private const STATUS_DEGRADED        = 'degraded';
    private const STATUS_ATTENTION       = 'attention';

    private string $mapFile;
    private string $spoolDir;
    private int $presenceStaleSeconds;

    /** @var array<string,true>|null */
    private ?array $mappedExternalIds = null;

    public function __construct(
        ?string $mapFile = null,
        ?string $spoolDir = null,
        int $presenceStaleSeconds = 300
    ) {
        $this->mapFile = self::resolvePath($mapFile ?? 'config/zigbee2mqtt-devices.json');
        $this->spoolDir = self::resolvePath($spoolDir ?? 'run/zigbee-spool');
        $this->presenceStaleSeconds = max(1, $presenceStaleSeconds);
    }

    /** @return array<string,mixed> */
    public function forDevice(Device $device): array
    {
        return $this->forRow([
            'id' => $device->id,
            'pack_id' => $device->packId,
            'kind' => $device->kind,
            'external_id' => $device->externalId,
            'transport' => $device->transport,
            'last_seen_at' => $device->lastSeenAt,
        ]);
    }

    /**
     * @param array<string,mixed> $row Keys: id, pack_id, kind, external_id,
     *        transport, last_seen_at. Extra keys are ignored.
     * @return array{status:string,mapping_configured:bool,last_signal_at:?string,last_signal_age_seconds:?int,reason:?string}
     */
    public function forRow(array $row): array
    {
        $packId = isset($row['pack_id']) && $row['pack_id'] !== null ? (int) $row['pack_id'] : null;
        $transport = Device::normalizeTransport(
            isset($row['transport']) && $row['transport'] !== null ? (string) $row['transport'] : null
        );
        $externalId = isset($row['external_id']) ? (string) $row['external_id'] : '';
        $kind = isset($row['kind']) ? (string) $row['kind'] : '';
        $lastSignalAt = isset($row['last_seen_at']) && $row['last_seen_at'] !== null
            ? (string) $row['last_seen_at']
            : null;
        $age = $this->ageSeconds($lastSignalAt);

        if ($packId === null) {
            return $this->result(self::STATUS_DRAFT, false, $lastSignalAt, $age, 'no_pack');
        }
        if ($transport === null) {
            return $this->result(self::STATUS_ASSOCIATED, false, $lastSignalAt, $age, 'unknown_transport');
        }
        if ($transport !== Device::TRANSPORT_ZIGBEE2MQTT) {
            // Non-Zigbee transports do not use the MQTT map. Their liveness is
            // shown separately by the existing device-status endpoint.
            return $this->result(self::STATUS_ASSOCIATED, false, $lastSignalAt, $age, null);
        }

        $mapped = $this->isMapped($externalId);

        if ($this->quarantineCount() > 0) {
            return $this->result(self::STATUS_ATTENTION, $mapped, $lastSignalAt, $age, 'quarantine');
        }
        if (!$mapped) {
            return $this->result(self::STATUS_PENDING_MAPPING, false, $lastSignalAt, $age, 'missing_mapping');
        }
        if ($lastSignalAt === null) {
            return $this->result(self::STATUS_WAITING_SIGNAL, true, null, null, 'no_signal');
        }
        if ($kind === Device::KIND_PRESENCE && $age !== null && $age > $this->presenceStaleSeconds) {
            return $this->result(self::STATUS_DEGRADED, true, $lastSignalAt, $age, 'stale');
        }

        return $this->result(self::STATUS_OPERATIONAL, true, $lastSignalAt, $age, null);
    }

    /** @return array<string,mixed> */
    private function result(
        string $status,
        bool $mappingConfigured,
        ?string $lastSignalAt,
        ?int $age,
        ?string $reason
    ): array {
        return [
            'status' => $status,
            'mapping_configured' => $mappingConfigured,
            'last_signal_at' => $this->toIso($lastSignalAt),
            'last_signal_age_seconds' => $age,
            'reason' => $reason,
        ];
    }

    private function isMapped(string $externalId): bool
    {
        if ($externalId === '') {
            return false;
        }
        if ($this->mappedExternalIds === null) {
            $this->mappedExternalIds = $this->loadMappedExternalIds();
        }
        return isset($this->mappedExternalIds[$externalId]);
    }

    /** @return array<string,true> */
    private function loadMappedExternalIds(): array
    {
        if (!is_file($this->mapFile)) {
            return [];
        }
        $raw = @file_get_contents($this->mapFile);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['devices']) || !is_array($decoded['devices'])) {
            return [];
        }
        $ids = [];
        foreach ($decoded['devices'] as $entry) {
            if (is_array($entry) && isset($entry['external_id']) && is_string($entry['external_id'])) {
                $ids[$entry['external_id']] = true;
            }
        }
        return $ids;
    }

    private function quarantineCount(): int
    {
        $dir = $this->spoolDir . '/quarantine';
        if (!is_dir($dir)) {
            return 0;
        }
        $files = @glob($dir . '/*');
        return is_array($files) ? count($files) : 0;
    }

    private function ageSeconds(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
        return max(0, time() - $dt->getTimestamp());
    }

    private function toIso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return $value;
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function resolvePath(string $path): string
    {
        if ($path === '') {
            return $path;
        }
        if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }
        return dirname(__DIR__, 3) . '/' . ltrim($path, '/');
    }
}
