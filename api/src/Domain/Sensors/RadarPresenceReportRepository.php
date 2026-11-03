<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

use PDO;

/**
 * RadarPresenceReportRepository — F111.
 *
 * Persiste reportes explicitos del DP 1. No toca presencia fusionada, visitas
 * ni grabaciones: un reporte del radar es evidencia del sensor, no una
 * transicion de estancia.
 */
final class RadarPresenceReportRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Inserta un reporte. Devuelve false si ya existia (retransmision).
     *
     * @param array{marker:string,session_id:string,report_counter:int,value:string,origin:string,config_key?:?string,received_at:string} $report
     */
    public function record(int $deviceId, string $externalId, int $roomId, array $report): bool
    {
        if (!isset($report['marker']) || !is_string($report['marker']) || $report['marker'] === '') {
            return false;
        }
        $value = strtoupper((string) ($report['value'] ?? ''));
        if (!in_array($value, ['PRESENT', 'ABSENT'], true)) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO radar_presence_reports
                (device_id, external_id, room_id, marker, session_id, report_counter, value, origin, config_key, received_at)
             VALUES
                (:device_id, :external_id, :room_id, :marker, :session_id, :report_counter, :value, :origin, :config_key, :received_at)'
        );
        $stmt->execute([
            ':device_id' => $deviceId,
            ':external_id' => $externalId,
            ':room_id' => $roomId,
            ':marker' => $report['marker'],
            ':session_id' => (string) ($report['session_id'] ?? ''),
            ':report_counter' => (int) ($report['report_counter'] ?? 0),
            ':value' => $value,
            ':origin' => (string) ($report['origin'] ?? 'push'),
            ':config_key' => $report['config_key'] ?? null,
            ':received_at' => (string) ($report['received_at'] ?? ''),
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * F112: reportes DP1 de una ventana, para evaluar el exterior por presencia.
     *
     * @return list<array{value:string,received_at:string,config_key:?string,marker:string}>
     */
    public function listSince(int $roomId, string $since, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT value, received_at, config_key, marker
               FROM radar_presence_reports
              WHERE room_id = :room AND received_at >= :since
              ORDER BY received_at ASC, id ASC
              LIMIT ' . $limit
        );
        $stmt->execute([':room' => $roomId, ':since' => $since]);
        return array_map(static fn (array $r): array => [
            'value' => (string) $r['value'],
            'received_at' => (string) $r['received_at'],
            'config_key' => $r['config_key'] !== null ? (string) $r['config_key'] : null,
            'marker' => (string) $r['marker'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return array{marker:string,session_id:string,report_counter:int,value:string,origin:string,config_key:?string,received_at:string}|null
     */
    public function latestForRoom(int $roomId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT marker, session_id, report_counter, value, origin, config_key, received_at
               FROM radar_presence_reports
              WHERE room_id = :room_id
              ORDER BY received_at DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([':room_id' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'marker' => (string) $row['marker'],
            'session_id' => (string) $row['session_id'],
            'report_counter' => (int) $row['report_counter'],
            'value' => (string) $row['value'],
            'origin' => (string) $row['origin'],
            'config_key' => $row['config_key'] !== null ? (string) $row['config_key'] : null,
            'received_at' => (string) $row['received_at'],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recentForRoom(int $roomId, string $sinceUtc, int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT marker, session_id, report_counter, value, origin, config_key, received_at
               FROM radar_presence_reports
              WHERE room_id = :room_id AND received_at >= :since
              ORDER BY received_at ASC, id ASC
              LIMIT :lim'
        );
        $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':since', $sinceUtc);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $out[] = [
                'marker' => (string) $row['marker'],
                'session_id' => (string) $row['session_id'],
                'report_counter' => (int) $row['report_counter'],
                'value' => (string) $row['value'],
                'origin' => (string) $row['origin'],
                'config_key' => $row['config_key'] !== null ? (string) $row['config_key'] : null,
                'received_at' => (string) $row['received_at'],
            ];
        }
        return $out;
    }
}
