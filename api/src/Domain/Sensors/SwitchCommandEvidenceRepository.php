<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

use PDO;

/**
 * SwitchCommandEvidenceRepository — F112/RF-261..RF-265.
 *
 * Persiste la evidencia de cada orden a un SWITCH Zigbee. La escritura del
 * registro nunca publica ordenes ni cambia el estado del dispositivo.
 */
final class SwitchCommandEvidenceRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Crea la operacion. Devuelve false si el operation_id ya existia.
     *
     * @param array{operation_id:string,room_id:int,device_id:int,external_id:string,provider:string,origin:string,action:string,actor:?string,requested_at:string,prior_state:?string,prior_state_at:?string} $row
     */
    public function begin(array $row): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO switch_command_evidence
                (operation_id, room_id, device_id, external_id, provider, origin, action, actor,
                 requested_at, prior_state, prior_state_at, verdict)
             VALUES
                (:operation_id, :room_id, :device_id, :external_id, :provider, :origin, :action, :actor,
                 :requested_at, :prior_state, :prior_state_at, :verdict)'
        );
        try {
            $stmt->execute([
                ':operation_id' => $row['operation_id'],
                ':room_id' => $row['room_id'],
                ':device_id' => $row['device_id'],
                ':external_id' => $row['external_id'],
                ':provider' => $row['provider'],
                ':origin' => $row['origin'],
                ':action' => $row['action'],
                ':actor' => $row['actor'],
                ':requested_at' => self::dbDate($row['requested_at']),
                ':prior_state' => $row['prior_state'],
                ':prior_state_at' => self::dbDate($row['prior_state_at']),
                ':verdict' => 'indeterminate',
            ]);
        } catch (PDOException $e) {
            // Solo el operation_id duplicado es un "ya existe". Cualquier otro
            // error (p.ej. fecha invalida) debe propagarse y verse.
            if ((string) $e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
        return true;
    }

    public function markPublished(string $operationId, string $publishedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE switch_command_evidence
                SET publish_ok = 1, publish_error = NULL, published_at = :at
              WHERE operation_id = :op'
        );
        $stmt->execute([':at' => self::dbDate($publishedAt), ':op' => $operationId]);
    }

    public function markPublishFailed(string $operationId, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE switch_command_evidence
                SET publish_ok = 0, publish_error = :err, verdict = :verdict, diagnosis = :diag
              WHERE operation_id = :op'
        );
        $stmt->execute([
            ':err' => substr($error, 0, 255),
            ':verdict' => 'publish_failed',
            ':diag' => 'publish_failed',
            ':op' => $operationId,
        ]);
    }

    /**
     * @param array{reported_state:?string,reported_at:?string,fresh:?bool,report_source:?string,human_state:?string,human_at:?string,verdict:string,diagnosis:?string} $result
     */
    public function close(string $operationId, array $result): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE switch_command_evidence
                SET reported_state = :reported_state, reported_at = :reported_at, fresh = :fresh,
                    report_source = :report_source, human_state = :human_state, human_at = :human_at,
                    verdict = :verdict, diagnosis = :diagnosis
              WHERE operation_id = :op'
        );
        $stmt->execute([
            ':reported_state' => $result['reported_state'],
            ':reported_at' => self::dbDate($result['reported_at']),
            ':fresh' => $result['fresh'] === null ? null : ($result['fresh'] ? 1 : 0),
            ':report_source' => $result['report_source'],
            ':human_state' => $result['human_state'],
            ':human_at' => self::dbDate($result['human_at']),
            ':verdict' => $result['verdict'],
            ':diagnosis' => $result['diagnosis'],
            ':op' => $operationId,
        ]);
    }

    public function setHuman(string $operationId, ?string $humanState, ?string $humanAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE switch_command_evidence
                SET human_state = :state, human_at = :at
              WHERE operation_id = :op'
        );
        $stmt->execute([':state' => $humanState, ':at' => self::dbDate($humanAt), ':op' => $operationId]);
    }

    /**
     * F112: normaliza cualquier fecha a UTC `Y-m-d H:i:s.v` antes de escribir.
     *
     * Acepta el formato SQL y el ISO 8601 que llega por push/telemetria
     * (`2026-11-03T15:32:01.197Z` o con desplazamiento). MariaDB en modo
     * estricto rechaza el ISO con `T`/`Z` en una columna DATETIME(3).
     */
    public static function dbDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/', $v) === 1) {
            $parts = explode('.', $v, 2);
            $micro = isset($parts[1]) ? substr($parts[1] . '000000', 0, 6) : '000000';
            return $parts[0] . '.' . substr($micro, 0, 3);
        }
        try {
            $dt = new \DateTimeImmutable($v);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('invalid_datetime: ' . $v);
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    /** @return array<string,mixed>|null */
    public function findByOperationId(string $operationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM switch_command_evidence WHERE operation_id = :op LIMIT 1');
        $stmt->execute([':op' => $operationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function latestForRoom(int $roomId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM switch_command_evidence
              WHERE room_id = :room
              ORDER BY requested_at DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([':room' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function recentForRoom(int $roomId, string $from, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM switch_command_evidence
              WHERE room_id = :room AND requested_at >= :from
              ORDER BY requested_at DESC, id DESC
              LIMIT ' . $limit
        );
        $stmt->execute([':room' => $roomId, ':from' => $from]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function purgeBefore(string $cutoff, int $batch = 1000): int
    {
        $batch = max(100, min(50000, $batch));
        $deleted = 0;
        do {
            $stmt = $this->pdo->prepare(
                'DELETE FROM switch_command_evidence WHERE requested_at < :cutoff
                  ORDER BY requested_at ASC LIMIT ' . $batch
            );
            $stmt->execute([':cutoff' => $cutoff]);
            $n = $stmt->rowCount();
            $deleted += $n;
        } while ($n >= $batch);
        return $deleted;
    }
}
