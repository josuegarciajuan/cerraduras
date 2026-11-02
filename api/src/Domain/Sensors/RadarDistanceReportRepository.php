<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

use PDO;

/**
 * RadarDistanceReportRepository — F107/RF-231.
 *
 * Persiste reportes explícitos del DP 9. No toca presencia, visitas ni
 * grabaciones: una distancia no es una transición de estancia.
 */
final class RadarDistanceReportRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Inserta un reporte. Devuelve false si ya existía (retransmisión).
     *
     * @param array{marker:string,session_id:string,report_counter:int,distance_m:float,origin:string,received_at:string} $report
     */
    public function record(int $deviceId, string $externalId, int $roomId, array $report): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO radar_distance_reports
                (device_id, external_id, room_id, marker, session_id, report_counter, distance_m, origin, received_at)
             VALUES
                (:device_id, :external_id, :room_id, :marker, :session_id, :report_counter, :distance_m, :origin, :received_at)'
        );
        $stmt->execute([
            ':device_id' => $deviceId,
            ':external_id' => $externalId,
            ':room_id' => $roomId,
            ':marker' => $report['marker'],
            ':session_id' => $report['session_id'],
            ':report_counter' => $report['report_counter'],
            ':distance_m' => $report['distance_m'],
            ':origin' => $report['origin'],
            ':received_at' => $report['received_at'],
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array{id:int,device_id:int,room_id:int,marker:string,session_id:string,
     *                    report_counter:int,distance_m:float,origin:string,received_at:string}>
     */
    public function recentForRoom(int $roomId, string $sinceUtc, int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, device_id, room_id, marker, session_id, report_counter, distance_m, origin, received_at
               FROM radar_distance_reports
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
                'id' => (int) $row['id'],
                'device_id' => (int) $row['device_id'],
                'room_id' => (int) $row['room_id'],
                'marker' => (string) $row['marker'],
                'session_id' => (string) $row['session_id'],
                'report_counter' => (int) $row['report_counter'],
                'distance_m' => (float) $row['distance_m'],
                'origin' => (string) $row['origin'],
                'received_at' => (string) $row['received_at'],
            ];
        }
        return $out;
    }
}
