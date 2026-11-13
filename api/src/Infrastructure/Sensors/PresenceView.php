<?php
declare(strict_types=1);

namespace App\Infrastructure\Sensors;

use App\Domain\Sensors\RadarFreshness;
use PDO;

/**
 * PresenceView — F136.
 *
 * Vista única y de solo lectura de la presencia del radar para TODOS los
 * paneles. Radar Studio (`/sensores`) y el dashboard (`/dashboard`) consumen
 * exactamente los mismos campos, de modo que la señal del sensor no dependa
 * del panel que la muestra.
 *
 * Capas separadas a propósito:
 *   - `raw`: DP1 del último reporte Zigbee + frescura (30 s). Es la detección
 *     inmediata del sensor.
 *   - `presence_outcome`: resultado operativo final persistido en
 *     `iot_sessions.presence_outcome_json` (autoritativo).
 *   - `pending`/`episode`: transición pendiente y episodio DP1/DP9.
 *
 * No publica MQTT, no consulta el dispositivo y no crea temporizadores.
 */
final class PresenceView
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function build(int $roomId): array
    {
        $session = $this->session($roomId);
        $presenceState = strtoupper((string) ($session['presence_state'] ?? 'UNKNOWN'));
        $lastPresenceAt = $session['last_presence_event_at'] ?? null;

        $outcome = self::decode($session['presence_outcome_json'] ?? null);
        if ($outcome !== null) {
            $outcome['age_seconds'] = RadarFreshness::ageSeconds(
                isset($outcome['decided_at']) && is_string($outcome['decided_at'])
                    ? $outcome['decided_at'] : null
            );
            $outcome['stale'] = RadarFreshness::decisionStale($outcome);
        }

        $pending = null;
        if (($session['presence_pending_state'] ?? null) !== null) {
            $pending = [
                'state' => (string) $session['presence_pending_state'],
                'since' => $session['presence_pending_since'] ?? null,
                'due_at' => $session['presence_pending_due_at'] ?? null,
                'marker' => $session['presence_pending_marker'] ?? null,
                'config_key' => $session['presence_pending_config_key'] ?? null,
            ];
        }

        $episode = null;
        if (($session['presence_episode_started_at'] ?? null) !== null
            || ($session['presence_episode_marker'] ?? null) !== null
        ) {
            $episode = [
                'marker' => $session['presence_episode_marker'] ?? null,
                'session' => $session['presence_episode_session'] ?? null,
                'started_at' => $session['presence_episode_started_at'] ?? null,
                'distance_marker' => $session['presence_episode_distance_marker'] ?? null,
            ];
        }

        $latest = $this->latestPresenceEvent($roomId);
        $rawState = self::rawState($latest);
        $stale = RadarFreshness::stale(
            is_string($lastPresenceAt) && $lastPresenceAt !== '' ? $lastPresenceAt : null
        );
        $rawAge = RadarFreshness::ageSeconds(
            is_string($lastPresenceAt) && $lastPresenceAt !== '' ? $lastPresenceAt : null
        );
        if ($stale) {
            $rawState = 'UNKNOWN';
        }

        $override = $session['presence_mode_override'] ?? null;
        $override = is_string($override) && $override !== '' ? $override : null;
        $mode = $session['presence_mode'] ?? null;
        $mode = is_string($mode) && $mode !== '' ? $mode : null;

        return [
            'presence_state' => $presenceState,
            'effective_presence' => $outcome['effective_presence'] ?? $presenceState,
            'presence_outcome' => $outcome,
            'pending' => $pending,
            'episode' => $episode,
            'raw' => [
                'state' => $rawState,
                'age_seconds' => $rawAge,
                'stale' => $stale,
                'presence_report' => self::presenceReport($latest),
            ],
            'radar_stale' => $stale,
            'stale_seconds' => RadarFreshness::staleSeconds(),
            'presence_mode' => $mode,
            'presence_mode_override' => $override,
            // Un panel puede decidir mostrar la detección inmediata solo en
            // salas con política radar explícita (F136).
            'radar_room' => $override !== null,
        ];
    }

    /** @return array<string,mixed> */
    private function session(int $roomId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.presence_state, s.last_presence_event_at,
                    s.presence_pending_state, s.presence_pending_since,
                    s.presence_pending_due_at, s.presence_pending_marker,
                    s.presence_pending_config_key, s.presence_outcome_json,
                    s.presence_episode_marker, s.presence_episode_session,
                    s.presence_episode_started_at, s.presence_episode_distance_marker,
                    r.presence_mode_override,
                    COALESCE(r.presence_mode_override, rt.presence_mode) AS presence_mode
               FROM iot_sessions s
               INNER JOIN rooms r ON r.id = s.room_id
               LEFT JOIN room_types rt ON rt.id = r.room_type_id
              WHERE s.room_id = :rid
              LIMIT 1'
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /** @return array<string,mixed>|null */
    private function latestPresenceEvent(int $roomId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT value, meta_json, occurred_at
               FROM presence_events
              WHERE room_id = :rid AND provider = 'ZIGBEE2MQTT' AND sensor = 'PRESENCE'
              ORDER BY occurred_at DESC, id DESC
              LIMIT 1"
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $meta = self::decode($row['meta_json'] ?? null);
        return [
            'value' => (string) ($row['value'] ?? ''),
            'occurred_at' => $row['occurred_at'] ?? null,
            'meta' => $meta ?? [],
        ];
    }

    /** @param array<string,mixed>|null $event */
    private static function rawState(?array $event): string
    {
        if ($event === null) {
            return 'UNKNOWN';
        }
        $meta = is_array($event['meta'] ?? null) ? $event['meta'] : [];
        $report = is_array($meta['presence_report'] ?? null) ? $meta['presence_report'] : null;
        $value = strtoupper(trim((string) ($report['value'] ?? '')));
        if (!in_array($value, ['PRESENT', 'ABSENT'], true)) {
            $value = strtoupper(trim((string) ($event['value'] ?? '')));
        }
        return in_array($value, ['PRESENT', 'ABSENT'], true) ? $value : 'UNKNOWN';
    }

    /** @param array<string,mixed>|null $event */
    private static function presenceReport(?array $event): ?array
    {
        $meta = is_array($event['meta'] ?? null) ? $event['meta'] : [];
        $report = $meta['presence_report'] ?? null;
        return is_array($report) ? $report : null;
    }

    /** @return array<string,mixed>|null */
    private static function decode(mixed $raw): ?array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
