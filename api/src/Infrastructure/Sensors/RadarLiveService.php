<?php
declare(strict_types=1);

namespace App\Infrastructure\Sensors;

use App\Domain\Sensors\RadarDistanceReport;
use App\Domain\Sensors\RadarDistanceReportRepository;
use App\Domain\Sensors\RadarFreshness;
use App\Domain\Sensors\RadarLiveDistance;
use App\Infrastructure\Zigbee\RadarCalibrationConvergence;
use App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService;
use PDO;
use RuntimeException;

/**
 * RadarLiveService — F125.1.5 / F125.1.6.
 *
 * Construye el payload ligero del canal en vivo de Radar Studio:
 * presencia cruda, frescura, salud, ajustes, movimiento/último eco, distancia
 * actual, puerta y modo de presencia. No incluye la serie de 60 s ni el
 * diagnóstico (eso lo aporta `/radar/scope` a 1,5 s).
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
        $maxAge = isset($status['stale_seconds']) && is_numeric($status['stale_seconds'])
            ? max(5, min(300, (int) $status['stale_seconds']))
            : RadarFreshness::staleSeconds();
        $settingsAt = isset($status['settings_at']) && is_string($status['settings_at'])
            ? $status['settings_at'] : null;
        $latestReport = null;
        if ($target === null || $age === null || $age > $maxAge) {
            $since = gmdate('Y-m-d H:i:s', time() - $maxAge);
            $configKey = RadarDistanceReport::configKey($settings);
            $latestReport = (new RadarDistanceReportRepository($this->pdo))
                ->latestPositiveForRoom($roomId, $since, $configKey);
        }
        $distance = RadarLiveDistance::choose(
            $target,
            $age,
            $settingsAt,
            $latestReport,
            $maxAge,
            gmdate('Y-m-d\TH:i:s.v\Z')
        );
        $distanceM = $distance['distance_m'];
        $distanceAge = $distance['age_seconds'];
        $distanceAt = $distance['at'];
        $distanceSource = $distance['source'];
        $motion = !$stale && $distanceM !== null;
        $series = $motion
            ? [['distance_m' => $distanceM, 'age_s' => $distanceAge]]
            : [];

        $calibration = RadarCalibrationConvergence::statusFor(
            is_array($device['meta'] ?? null) ? $device['meta'] : [],
            is_array($settings) ? $settings : []
        );
        $calibration['reported_invalid'] = (bool) ($status['reported_invalid'] ?? false);
        $calibration['reported_invalid_reason'] = $status['reported_invalid_reason'] ?? null;
        $calibration['latest_reported_settings'] = $status['latest_reported_settings'] ?? null;
        $calibration['reported_unstable'] = (bool) ($status['reported_unstable'] ?? false);
        $calibration['reported_variants'] = $status['reported_variants'] ?? [];

        // F130: decisión operativa y barrera de alcance del último frame. El
        // eco bruto sigue exponiéndose aparte; el cliente no debe rotular
        // "presencia confirmada" si el dominio no la aplicó.
        $decision = $this->latestRadarDecision($roomId);
        $rangeGate = is_array($decision['range_gate'] ?? null) ? $decision['range_gate'] : null;

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
                'last_distance' => $distanceM,
                'last_distance_age_s' => $distanceAge,
            ],
            'series' => $series,
            'last_distance' => $distanceM,
            'last_distance_at' => $distanceAt,
            'last_distance_age_s' => $distanceAge,
            'last_distance_source' => $distanceSource,
            'door_state' => $live['door_state'] ?? null,
            'presence_mode' => $this->presenceMode($roomId),
            'presence_stale' => (bool) ($live['presence_stale'] ?? false),
            'stale_seconds' => (int) ($status['stale_seconds'] ?? RadarFreshness::staleSeconds()),
            'calibration' => $calibration,
            'presence_decision' => $decision !== null ? [
                'state' => $decision['state'] ?? null,
                'apply' => $decision['apply'] ?? null,
                'discard_reason' => $decision['discard_reason'] ?? null,
                'at' => $decision['at'] ?? null,
                'converted_from' => $decision['converted_from'] ?? null,
            ] : null,
            'range_gate' => $rangeGate,
            'range_evaluation' => $rangeGate,
        ];
    }

    /**
     * F130: última decisión radar persistida para la sala. Es aditiva y sirve
     * para que Radar Studio no confunda presencia cruda con presencia aplicada.
     *
     * @return array<string,mixed>|null
     */
    private function latestRadarDecision(int $roomId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT meta_json
               FROM presence_events
              WHERE room_id = :rid AND provider = 'ZIGBEE2MQTT' AND sensor = 'PRESENCE'
              ORDER BY occurred_at DESC, id DESC
              LIMIT 1"
        );
        $stmt->execute([':rid' => $roomId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null) {
            return null;
        }
        $meta = json_decode((string) $raw, true);
        return is_array($meta) && is_array($meta['radar_decision'] ?? null)
            ? $meta['radar_decision'] : null;
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
