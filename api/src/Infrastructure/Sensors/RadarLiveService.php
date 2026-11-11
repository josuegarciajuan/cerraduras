<?php
declare(strict_types=1);

namespace App\Infrastructure\Sensors;

use App\Domain\Sensors\RadarFreshness;
use App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService;
use PDO;
use RuntimeException;

/**
 * RadarLiveService — F125.1.5.
 *
 * Construye el payload ligero del canal en vivo de Radar Studio:
 * presencia cruda, frescura, salud, ajustes, movimiento/último eco, puerta y
 * modo de presencia. No incluye la serie de 60 s ni el diagnóstico (eso lo
 * aporta `/radar/scope` a 1,5 s).
 */
final class RadarLiveService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function payload(int $roomId): array
    {
        $ctx = new SensorContextBuilder($this->pdo);
        $devices = $ctx->devices($roomId);
        $device = $devices['presence'] ?? null;
        if ($device === null) {
            throw new RuntimeException('presence_device_missing');
        }

        $status = (new ZigbeeRadarCalibrationService($this->pdo))->status($roomId, $device);
        $settings = (array) ($status['settings'] ?? []);
        $stale = (bool) ($status['stale'] ?? true);
        $age = isset($status['settings_age_seconds']) && is_numeric($status['settings_age_seconds'])
            ? (int) $status['settings_age_seconds'] : null;
        $target = isset($settings['target_distance']) && is_numeric($settings['target_distance'])
            ? (float) $settings['target_distance'] : null;
        if ($target !== null && $target <= 0) {
            $target = null;
        }

        $report = is_array($status['presence_report'] ?? null) ? $status['presence_report'] : null;
        $state = strtoupper(trim((string) ($report['value'] ?? '')));
        if (!in_array($state, ['PRESENT', 'ABSENT'], true)) {
            $state = strtoupper(trim((string) ($status['effective_presence'] ?? '')));
        }
        if (!in_array($state, ['PRESENT', 'ABSENT'], true)) {
            $state = 'UNKNOWN';
        }
        if ($stale) {
            $state = 'UNKNOWN';
        }

        $live = $ctx->live($roomId);
        $motion = !$stale && $target !== null;
        $series = $motion ? [['distance_m' => round($target, 3), 'age_s' => $age]] : [];

        return [
            'ok' => true,
            'room_id' => $roomId,
            'server_ts' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'device' => [
                'id' => (int) $device['id'],
                'external_id' => (string) $device['external_id'],
                'label' => (string) ($device['label'] ?? ''),
                'health' => (string) ($device['health'] ?? ''),
                'health_detail' => (string) ($device['health_detail'] ?? ''),
                'lqi' => $device['lqi'] ?? null,
                'battery_pct' => $device['battery_pct'] ?? null,
            ],
            'settings' => $settings,
            'raw' => ['state' => $state, 'age_seconds' => $age, 'stale' => $stale],
            'stats' => [
                'motion_recent' => $motion,
                'last_age_s' => $age,
                'last_distance' => $target,
            ],
            'series' => $series,
            'last_distance' => $target,
            'door_state' => $live['door_state'] ?? null,
            'presence_mode' => $this->presenceMode($roomId),
            'presence_stale' => (bool) ($live['presence_stale'] ?? false),
            'stale_seconds' => (int) ($status['stale_seconds'] ?? RadarFreshness::staleSeconds()),
        ];
    }

    private function presenceMode(int $roomId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(r.presence_mode_override, rt.presence_mode) AS presence_mode
               FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id
              WHERE r.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $roomId]);
        $value = $stmt->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }
}
