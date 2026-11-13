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
     * @param array{marker:string,session_id:string,report_counter:int,distance_m:float,origin:string,config_key?:?string,received_at:string} $report
     */
    public function record(int $deviceId, string $externalId, int $roomId, array $report): bool
    {
        // F110 opción 3: un DP9 con distancia 0 no es una medición. La
        // presencia/ausencia se conserva en presence_events; aquí solo se
        // guardan distancias positivas para no llenar la tabla.
        if (!isset($report['distance_m']) || !is_numeric($report['distance_m'])
            || (float) $report['distance_m'] <= 0.0) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO radar_distance_reports
                (device_id, external_id, room_id, marker, session_id, report_counter, distance_m, origin, config_key, received_at)
             VALUES
                (:device_id, :external_id, :room_id, :marker, :session_id, :report_counter, :distance_m, :origin, :config_key, :received_at)'
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
            ':config_key' => $report['config_key'] ?? null,
            ':received_at' => $report['received_at'],
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array{id:int,device_id:int,room_id:int,marker:string,session_id:string,
     *                    report_counter:int,distance_m:float,origin:string,config_key:?string,received_at:string}>
     */
    public function recentForRoom(int $roomId, string $sinceUtc, int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, device_id, room_id, marker, session_id, report_counter, distance_m, origin, config_key, received_at
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
                'config_key' => $row['config_key'] !== null ? (string) $row['config_key'] : null,
                'received_at' => (string) $row['received_at'],
            ];
        }
        return $out;
    }

    /**
     * F133: reporte DP9 por marcador, con la configuración ORIGINAL con la que
     * se registró. Es la autoridad cuando una trama combinada reutiliza un
     * marcador cacheado después de cambiar el perfil.
     *
     * @return array{id:int,device_id:int,room_id:string,marker:string,session_id:string,
     *               report_counter:int,distance_m:float,origin:string,config_key:?string,received_at:string}|null
     */
    public function findByMarker(int $roomId, string $marker): ?array
    {
        if ($marker === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, device_id, room_id, marker, session_id, report_counter,
                    distance_m, origin, config_key, received_at
               FROM radar_distance_reports
              WHERE room_id = :room_id AND marker = :marker
              LIMIT 1'
        );
        $stmt->execute([':room_id' => $roomId, ':marker' => $marker]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'device_id' => (int) $row['device_id'],
            'room_id' => (int) $row['room_id'],
            'marker' => (string) $row['marker'],
            'session_id' => (string) $row['session_id'],
            'report_counter' => (int) $row['report_counter'],
            'distance_m' => (float) $row['distance_m'],
            'origin' => (string) $row['origin'],
            'config_key' => $row['config_key'] !== null ? (string) $row['config_key'] : null,
            'received_at' => (string) $row['received_at'],
        ];
    }

    /**
     * F125.1.6: última DP9 positiva dentro de la ventana de frescura.
     *
     * @return array{id:int,device_id:int,room_id:int,marker:string,session_id:string,
     *               report_counter:int,distance_m:float,origin:string,config_key:?string,received_at:string}|null
     */
    public function latestPositiveForRoom(
        int $roomId,
        string $sinceUtc,
        ?string $configKey = null
    ): ?array {
        $sql = 'SELECT id, device_id, room_id, marker, session_id, report_counter,
                       distance_m, origin, config_key, received_at
                  FROM radar_distance_reports
                 WHERE room_id = :room_id
                   AND distance_m > 0
                   AND received_at >= :since';
        if ($configKey !== null) {
            $sql .= ' AND config_key = :config_key';
        }
        $sql .= ' ORDER BY received_at DESC, id DESC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':since', $sinceUtc);
        if ($configKey !== null) {
            $stmt->bindValue(':config_key', $configKey);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'device_id' => (int) $row['device_id'],
            'room_id' => (int) $row['room_id'],
            'marker' => (string) $row['marker'],
            'session_id' => (string) $row['session_id'],
            'report_counter' => (int) $row['report_counter'],
            'distance_m' => (float) $row['distance_m'],
            'origin' => (string) $row['origin'],
            'config_key' => $row['config_key'] !== null ? (string) $row['config_key'] : null,
            'received_at' => (string) $row['received_at'],
        ];
    }
}
