<?php
declare(strict_types=1);

namespace App\Infrastructure\Sensors;

use PDO;

/**
 * SensorContextBuilder — F94/RF-160.
 *
 * Construye el contexto de un paso desde la BD local: dispositivos, estado
 * vivo, eventos de la ventana, configuración del radar y efectos observados.
 * Solo lectura; no publica MQTT, no llama a Tuya y no crea procesos.
 */
final class SensorContextBuilder
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{presence:?array<string,mixed>,proximity:?array<string,mixed>,switch:?array<string,mixed>,transport:string}
     */
    public function devices(int $roomId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT d.id, d.kind, d.external_id, d.label, d.transport, d.meta_json,
                    d.last_seen_at, d.battery_pct
               FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
              WHERE r.id = :rid AND d.kind IN ('PRESENCE','PROXIMITY','SWITCH')
              ORDER BY FIELD(d.kind,'PRESENCE','PROXIMITY','SWITCH'), d.id"
        );
        $stmt->execute([':rid' => $roomId]);
        $out = ['presence' => null, 'proximity' => null, 'switch' => null, 'transport' => ''];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $kind = strtoupper((string) $row['kind']);
            $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
            $lastSeen = $row['last_seen_at'] !== null ? (string) $row['last_seen_at'] : null;
            $lastSeenTs = $lastSeen !== null ? strtotime($lastSeen . ' UTC') : false;
            $age = $lastSeenTs !== false ? max(0, time() - $lastSeenTs) : null;
            $metaArr = is_array($meta) ? $meta : [];
            $health = \App\Infrastructure\Sensors\DeviceHealth::classify(
                true,
                $age !== null && $age <= 300,
                (string) $row['kind'],
                $age
            );
            $dev = [
                'id' => (int) $row['id'],
                'kind' => (string) $row['kind'],
                'external_id' => (string) $row['external_id'],
                'label' => (string) ($row['label'] ?? ''),
                'transport' => strtoupper((string) ($row['transport'] ?? '')),
                'last_seen_at' => $lastSeen,
                'last_seen_age_seconds' => $age,
                'fresh' => $age !== null && $age <= 300,
                'health' => $health['state'],
                'health_label' => $health['label'],
                'health_tone' => $health['tone'],
                'health_detail' => $health['detail'],
                'expects_periodic_push' => $health['expects_periodic_push'],
                'battery_pct' => $row['battery_pct'] !== null ? (int) $row['battery_pct'] : null,
                'model' => (string) ($metaArr['model'] ?? ''),
                'lqi' => $metaArr['lqi'] ?? null,
                'door_polarity' => strtolower((string) ($metaArr['door_polarity'] ?? 'normal')),
                'switch_state' => strtoupper((string) ($metaArr['switch_state'] ?? 'UNKNOWN')),
                'switch_state_at' => $metaArr['switch_state_at'] ?? null,
                'calibration' => is_array($metaArr['calibration'] ?? null) ? $metaArr['calibration'] : null,
                'meta' => $metaArr,
            ];
            if ($kind === 'PRESENCE') {
                $out['presence'] = $dev;
            } elseif ($kind === 'PROXIMITY') {
                $out['proximity'] = $dev;
            } elseif ($kind === 'SWITCH') {
                $out['switch'] = $dev;
            }
        }
        $out['transport'] = $out['presence']['transport'] ?? ($out['proximity']['transport'] ?? '');
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public function live(int $roomId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.door_state, s.presence_state, s.last_door_event_at, s.last_presence_event_at,
                    s.last_open_at, s.last_close_at, s.last_absent_since,
                    rt.presence_stale_seconds
               FROM iot_sessions s
               LEFT JOIN rooms r ON r.id = s.room_id
               LEFT JOIN room_types rt ON rt.id = r.room_type_id
              WHERE s.room_id = :rid LIMIT 1"
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $now = time();
        $age = static function (?string $ts) use ($now): ?int {
            if ($ts === null || $ts === '') {
                return null;
            }
            $t = strtotime($ts . ' UTC');
            return $t === false ? null : max(0, $now - $t);
        };
        $staleSeconds = (int) ($row['presence_stale_seconds'] ?? 180);
        if ($staleSeconds <= 0) {
            $staleSeconds = 180;
        }
        $presenceAge = $age($row['last_presence_event_at'] ?? null);
        $doorAge = $age($row['last_door_event_at'] ?? null);

        return [
            'door_state' => (string) ($row['door_state'] ?? 'UNKNOWN'),
            'presence_state' => (string) ($row['presence_state'] ?? 'UNKNOWN'),
            'last_door_event_at' => $row['last_door_event_at'] ?? null,
            'last_presence_event_at' => $row['last_presence_event_at'] ?? null,
            'last_open_at' => $row['last_open_at'] ?? null,
            'last_close_at' => $row['last_close_at'] ?? null,
            'last_absent_since' => $row['last_absent_since'] ?? null,
            'door_age_seconds' => $doorAge,
            'presence_age_seconds' => $presenceAge,
            'presence_stale' => $presenceAge !== null && $presenceAge > $staleSeconds,
            'presence_stale_seconds' => $staleSeconds,
            'presence_decision' => $this->latestRadarDecision($roomId),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function eventsSince(int $roomId, string $sinceUtc, int $limit = 3000): array
    {
        $limit = max(1, min(10000, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT id, sensor, value, provider, occurred_at, received_at, applied, discard_reason, meta_json
               FROM presence_events
              WHERE room_id = :rid AND occurred_at >= :since
              ORDER BY occurred_at ASC, id ASC
              LIMIT :lim"
        );
        $stmt->bindValue(':rid', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':since', $sinceUtc);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
            $out[] = [
                'id' => (int) $row['id'],
                'sensor' => (string) $row['sensor'],
                'value' => (string) $row['value'],
                'provider' => (string) $row['provider'],
                'occurred_at' => (string) $row['occurred_at'],
                'received_at' => (string) $row['received_at'],
                'applied' => $row['applied'] === null ? null : (bool) $row['applied'],
                'discard_reason' => $row['discard_reason'] !== null ? (string) $row['discard_reason'] : null,
                'meta' => is_array($meta) ? $meta : [],
            ];
        }
        return $out;
    }

    /**
     * Últimos eventos de la sala (para el panel en vivo de la UI).
     *
     * @return list<array<string,mixed>>
     */
    public function latestEvents(int $roomId, int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT id, sensor, value, provider, occurred_at, received_at, applied, discard_reason, meta_json
               FROM presence_events WHERE room_id = :rid
              ORDER BY id DESC LIMIT :lim"
        );
        $stmt->bindValue(':rid', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
            $out[] = [
                'id' => (int) $row['id'],
                'sensor' => (string) $row['sensor'],
                'value' => (string) $row['value'],
                'provider' => (string) $row['provider'],
                'occurred_at' => (string) $row['occurred_at'],
                'received_at' => (string) $row['received_at'],
                'applied' => $row['applied'] === null ? null : (bool) $row['applied'],
                'discard_reason' => $row['discard_reason'] !== null ? (string) $row['discard_reason'] : null,
                'settings' => is_array($meta['settings'] ?? null) ? $meta['settings'] : null,
                'radar_state' => is_array($meta['radar_decision'] ?? null)
                    ? ($meta['radar_decision']['state'] ?? null) : null,
            ];
        }
        return $out;
    }

    /**
     * @return array{settings:array<string,mixed>,settings_at:?string,settings_age_seconds:?int,value:?string}
     */
    public function latestSettings(int $roomId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT value, occurred_at, meta_json FROM presence_events
              WHERE room_id = :rid AND provider = 'ZIGBEE2MQTT' AND sensor = 'PRESENCE'
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['settings' => [], 'settings_at' => null, 'settings_age_seconds' => null, 'value' => null];
        }
        $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
        $settings = is_array($meta) && is_array($meta['settings'] ?? null) ? $meta['settings'] : [];
        $at = (string) ($row['occurred_at'] ?? '');
        $age = null;
        if ($at !== '') {
            $ts = strtotime($at . ' UTC');
            if ($ts !== false) {
                $age = max(0, time() - $ts);
            }
        }
        return [
            'settings' => $settings,
            'settings_at' => $at !== '' ? $at : null,
            'settings_age_seconds' => $age,
            'value' => (string) ($row['value'] ?? ''),
        ];
    }

    /**
     * Instantánea de efectos observables para detectar acciones falsas.
     *
     * @return array<string,int>
     */
    public function effects(int $roomId): array
    {
        $count = function (string $table, string $where = '') use ($roomId): int {
            try {
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE room_id = :rid {$where}");
                $stmt->execute([':rid' => $roomId]);
                return (int) ($stmt->fetchColumn() ?: 0);
            } catch (\Throwable $e) {
                return 0;
            }
        };
        $switchOn = 0;
        $stmt = $this->pdo->prepare(
            "SELECT d.meta_json FROM devices d JOIN rooms r ON r.pack_id = d.pack_id
              WHERE r.id = :rid AND d.kind = 'SWITCH' ORDER BY d.id LIMIT 1"
        );
        $stmt->execute([':rid' => $roomId]);
        $meta = json_decode((string) ($stmt->fetchColumn() ?: ''), true);
        if (is_array($meta) && strtoupper((string) ($meta['switch_state'] ?? '')) === 'ON') {
            $switchOn = 1;
        }

        return [
            'visits' => $count('warehouse_visits'),
            'recordings' => $count('camera_recordings'),
            'stays' => $count('stays'),
            'access_events' => $count('access_events'),
            'anomalies' => $count('anomalies', "AND status = 'OPEN'"),
            'switch_on' => $switchOn,
        ];
    }

    /**
     * @return array<int,int>
     */
    public static function effectDelta(array $before, array $after): array
    {
        $delta = [];
        foreach ($after as $key => $value) {
            if (is_int($value)) {
                $delta[$key] = $value - (int) ($before[$key] ?? 0);
            }
        }
        return $delta;
    }

    /**
     * @param array<string,mixed> $initialEffects
     * @param array<string,mixed> $initialSettings
     * @return array<string,mixed>
     */
    public function context(
        int $roomId,
        string $sinceUtc,
        array $initialEffects = [],
        array $initialSettings = []
    ): array {
        $devices = $this->devices($roomId);
        $live = $this->live($roomId);
        $latest = $this->latestSettings($roomId);
        $effects = $this->effects($roomId);
        $sinceTs = strtotime($sinceUtc . ' UTC');
        $elapsed = $sinceTs === false ? 0 : max(0, time() - $sinceTs);

        return [
            'room_id' => $roomId,
            'transport' => $devices['transport'],
            'devices' => [
                'presence' => $devices['presence'],
                'proximity' => $devices['proximity'],
            ],
            'live' => $live,
            'events' => $this->eventsSince($roomId, $sinceUtc),
            'settings' => $latest['settings'],
            'settings_at' => $latest['settings_at'],
            'settings_age_seconds' => $latest['settings_age_seconds'],
            'initial_settings' => $initialSettings,
            'effects' => $effects,
            'effects_delta' => self::effectDelta($initialEffects, $effects),
            'elapsed_seconds' => $elapsed,
            'server_now' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * F95/RF-162: información de la sala y plan propuesto.
     *
     * @return array<string,mixed>
     */
    public function preflight(int $roomId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.code, r.room_type_id, r.presence_mode_override,
                    rt.code AS room_type_code, rt.presence_mode
               FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id
              WHERE r.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $roomId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $devices = $this->devices($roomId);
        $latest = $this->latestSettings($roomId);
        $live = $this->live($roomId);
        $issues = [];
        // F95/RF-163.4: puerta y luz son EndDevice y solo emiten al cambiar; su
        // silencio no es una incidencia. Solo el radar (Router) debe reportar
        // periódicamente; si no lo hace, es "sin datos".
        if ($devices['proximity'] === null) {
            $issues[] = 'door_missing';
        }
        if ($devices['presence'] === null) {
            $issues[] = 'presence_missing';
        } elseif (($devices['presence']['health'] ?? '') === 'stale') {
            $issues[] = 'presence_stale';
        }
        $hasSwitch = $devices['switch'] !== null;
        $canStart = $devices['proximity'] !== null && $devices['presence'] !== null;
        $plan = ['door', 'radar_inside', 'radar_outside'];
        if ($hasSwitch) {
            $plan[] = 'light';
        }
        return [
            'room' => [
                'id' => (int) ($room['id'] ?? $roomId),
                'code' => (string) ($room['code'] ?? ''),
                'room_type_id' => (int) ($room['room_type_id'] ?? 0),
                'room_type_code' => (string) ($room['room_type_code'] ?? ''),
                'presence_mode' => (string) ($room['presence_mode_override'] ?? $room['presence_mode'] ?? ''),
            ],
            'devices' => $devices,
            'switch' => $devices['switch'],
            'settings' => $latest['settings'],
            'settings_at' => $latest['settings_at'],
            'settings_age_seconds' => $latest['settings_age_seconds'],
            'live' => $live,
            'issues' => $issues,
            'can_start' => $canStart,
            'plan' => $plan,
            'effects' => $this->effects($roomId),
            'server_now' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @return array{state:string,state_at:?string,reported:bool}
     */
    public function switchSnapshot(int $roomId): array
    {
        $devices = $this->devices($roomId);
        $switch = $devices['switch'];
        return [
            'state' => $switch['switch_state'] ?? 'UNKNOWN',
            'state_at' => $switch['switch_state_at'] ?? null,
            'reported' => in_array($switch['switch_state'] ?? 'UNKNOWN', ['ON', 'OFF'], true),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestRadarDecision(int $roomId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT meta_json FROM presence_events
              WHERE room_id = :rid AND provider = 'ZIGBEE2MQTT' AND sensor = 'PRESENCE'
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':rid' => $roomId]);
        $meta = json_decode((string) ($stmt->fetchColumn() ?: ''), true);
        return is_array($meta) && is_array($meta['radar_decision'] ?? null)
            ? $meta['radar_decision'] : null;
    }
}
