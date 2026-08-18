<?php
declare(strict_types=1);

namespace App\Domain\Anomalies;

use PDO;

/**
 * AnomalyRepository: PDO persistence for anomalies.
 *
 * Follows the same pattern as IotSessionRepository — PDO injected via
 * constructor, private hydrate() method to convert DB rows to domain objects.
 */
final class AnomalyRepository implements AnomalyRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function save(Anomaly $anomaly): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO anomalies
                (room_id, stay_id, anomaly_type, severity, status,
                 context_data, detected_at)
             VALUES
                (:room, :stay, :type, :sev, :status, :ctx, :det)'
        );
        $stmt->execute([
            ':room'   => $anomaly->roomId,
            ':stay'   => $anomaly->stayId,
            ':type'   => $anomaly->anomalyType,
            ':sev'    => $anomaly->severity,
            ':status' => $anomaly->status,
            ':ctx'    => json_encode($anomaly->contextData, JSON_UNESCAPED_UNICODE),
            ':det'    => $anomaly->detectedAt,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findOpenByRoomAndType(int $roomId, string $anomalyType): ?Anomaly
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, room_id, stay_id, anomaly_type, severity, status,
                    context_data, detected_at, acknowledged_at, acknowledged_by,
                    dismissed_at, dismissed_by, created_at, updated_at
             FROM anomalies
             WHERE room_id = :rid AND anomaly_type = :type AND status = :status
             ORDER BY detected_at DESC LIMIT 1'
        );
        $stmt->execute([
            ':rid'    => $roomId,
            ':type'   => $anomalyType,
            ':status' => Anomaly::STATUS_OPEN,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByRoom(int $roomId, ?string $status = null): array
    {
        if ($status !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT id, room_id, stay_id, anomaly_type, severity, status,
                        context_data, detected_at, acknowledged_at, acknowledged_by,
                        dismissed_at, dismissed_by, created_at, updated_at
                 FROM anomalies WHERE room_id = :rid AND status = :status
                 ORDER BY detected_at DESC'
            );
            $stmt->execute([':rid' => $roomId, ':status' => $status]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id, room_id, stay_id, anomaly_type, severity, status,
                        context_data, detected_at, acknowledged_at, acknowledged_by,
                        dismissed_at, dismissed_by, created_at, updated_at
                 FROM anomalies WHERE room_id = :rid
                 ORDER BY detected_at DESC'
            );
            $stmt->execute([':rid' => $roomId]);
        }
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findOpenForRoom(int $roomId): array
    {
        return $this->findByRoom($roomId, Anomaly::STATUS_OPEN);
    }

    /**
     * Find all resolvable (OPEN + ACKNOWLEDGED) anomalies for a room.
     *
     * F37 fix: ACKNOWLEDGED anomalies were previously invisible to the
     * auto-resolver. Now both OPEN and ACKNOWLEDGED are evaluated and
     * auto-dismissed when their triggering condition clears.
     */
    public function findResolvableForRoom(int $roomId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, room_id, stay_id, anomaly_type, severity, status,
                    context_data, detected_at, acknowledged_at, acknowledged_by,
                    dismissed_at, dismissed_by, created_at, updated_at
             FROM anomalies
             WHERE room_id = :rid AND status IN (:open, :acked)
             ORDER BY detected_at DESC'
        );
        $stmt->execute([
            ':rid'   => $roomId,
            ':open'  => Anomaly::STATUS_OPEN,
            ':acked' => Anomaly::STATUS_ACKNOWLEDGED,
        ]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findAll(array $filters, int $limit = 50, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];

        if (isset($filters['room_id'])) {
            $where[] = 'a.room_id = :rid';
            $params[':rid'] = (int) $filters['room_id'];
        }
        if (isset($filters['anomaly_type'])) {
            $where[] = 'a.anomaly_type = :type';
            $params[':type'] = $filters['anomaly_type'];
        }
        if (isset($filters['severity'])) {
            $where[] = 'a.severity = :sev';
            $params[':sev'] = $filters['severity'];
        }
        if (isset($filters['status'])) {
            $where[] = 'a.status = :status';
            $params[':status'] = $filters['status'];
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT a.id, a.room_id, a.stay_id, a.anomaly_type, a.severity, a.status,
                       a.context_data, a.detected_at, a.acknowledged_at, a.acknowledged_by,
                       a.dismissed_at, a.dismissed_by, a.created_at, a.updated_at,
                       r.code AS room_code
                FROM anomalies a
                LEFT JOIN rooms r ON a.room_id = r.id
                WHERE {$whereClause}
                ORDER BY a.detected_at DESC
                LIMIT :lim OFFSET :off";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function count(array $filters): int
    {
        $where = ['1=1'];
        $params = [];

        if (isset($filters['room_id'])) {
            $where[] = 'room_id = :rid';
            $params[':rid'] = (int) $filters['room_id'];
        }
        if (isset($filters['anomaly_type'])) {
            $where[] = 'anomaly_type = :type';
            $params[':type'] = $filters['anomaly_type'];
        }
        if (isset($filters['severity'])) {
            $where[] = 'severity = :sev';
            $params[':sev'] = $filters['severity'];
        }
        if (isset($filters['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }

        $whereClause = implode(' AND ', $where);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM anomalies WHERE {$whereClause}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?Anomaly
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, room_id, stay_id, anomaly_type, severity, status,
                    context_data, detected_at, acknowledged_at, acknowledged_by,
                    dismissed_at, dismissed_by, created_at, updated_at
             FROM anomalies WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function update(int $id, array $fields): void
    {
        $sets = [];
        $params = [':id' => $id];

        $allowed = ['status', 'acknowledged_at', 'acknowledged_by', 'dismissed_at', 'dismissed_by'];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "`{$col}` = :{$col}";
                $params[":{$col}"] = $fields[$col];
            }
        }

        if (empty($sets)) {
            return;
        }

        $sql = 'UPDATE anomalies SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Anomaly
    {
        $contextData = [];
        if ($row['context_data'] !== null) {
            $decoded = json_decode((string) $row['context_data'], true);
            $contextData = is_array($decoded) ? $decoded : [];
        }

        return new Anomaly(
            (int)    $row['id'],
            (int)    $row['room_id'],
            $row['stay_id'] === null ? null : (int) $row['stay_id'],
            (string) $row['anomaly_type'],
            (string) $row['severity'],
            (string) $row['status'],
            $contextData,
            (string) $row['detected_at'],
            $row['acknowledged_at'] === null ? null : (string) $row['acknowledged_at'],
            $row['acknowledged_by'] === null ? null : (string) $row['acknowledged_by'],
            $row['dismissed_at']    === null ? null : (string) $row['dismissed_at'],
            $row['dismissed_by']    === null ? null : (string) $row['dismissed_by'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
            isset($row['room_code']) ? (string) $row['room_code'] : null,
        );
    }
}
