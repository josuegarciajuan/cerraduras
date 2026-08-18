<?php
declare(strict_types=1);

namespace App\Domain\Debts;

use PDO;

/**
 * DebtRepository: PDO persistence for the `debts` table.
 */
final class DebtRepository implements DebtRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function insert(int $stayId, int $roomId, int $excesoMinutos): int
    {
        $this->pdo->prepare(
            "INSERT INTO debts (stay_id, room_id, exceso_minutos, status, attempts)
             VALUES (:s, :r, :e, 'PENDING_SYNC', 0)"
        )->execute([':s' => $stayId, ':r' => $roomId, ':e' => $excesoMinutos]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?Debt
    {
        $stmt = $this->pdo->prepare($this->base() . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findActiveForStay(int $stayId): ?Debt
    {
        $stmt = $this->pdo->prepare(
            $this->base() .
            " WHERE stay_id = :s AND status IN ('PENDING_SYNC','SYNCING') LIMIT 1"
        );
        $stmt->execute([':s' => $stayId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /** @return list<Debt> */
    public function listFiltered(array $filters, int $limit = 50, int $offset = 0): array
    {
        $wheres = [];
        $params = [];
        if (!empty($filters['status'])) {
            $wheres[] = 'status = :st';
            $params[':st'] = $filters['status'];
        }
        if (!empty($filters['stay_id'])) {
            $wheres[] = 'stay_id = :sid';
            $params[':sid'] = (int) $filters['stay_id'];
        }
        if (!empty($filters['from'])) {
            $wheres[] = 'created_at >= :from';
            $params[':from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $wheres[] = 'created_at < :to';
            $params[':to'] = $filters['to'];
        }
        $sql = $this->base();
        if ($wheres) {
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }
        $sql .= ' ORDER BY id DESC LIMIT :lim OFFSET :off';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<string,mixed> $fields */
    public function update(int $id, array $fields): int
    {
        if (empty($fields)) {
            return 0;
        }
        $allowed = [
            'status'              => ':st',
            'attempts'            => ':att',
            'last_error'          => ':err',
            'amount_eur_snapshot' => ':amt',
            'vb6_ack_ref'         => ':ack',
            'next_attempt_at'     => ':nat',
        ];
        $sets = []; $params = [':id' => $id];
        foreach ($fields as $col => $val) {
            if (!isset($allowed[$col])) continue;
            $sets[] = "`{$col}` = {$allowed[$col]}";
            $params[$allowed[$col]] = $val;
        }
        if (!$sets) return 0;
        $stmt = $this->pdo->prepare('UPDATE debts SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** @return list<array<string,mixed>> */
    public function findOverdueOccupied(): array
    {
        // Find OCCUPIED stays whose (first_entry_at + duracion_minutos) is in the past.
        // Grace is applied in the service layer (room_type.grace_minutes may vary).
        $stmt = $this->pdo->query(
            "SELECT s.id AS stay_id, s.room_id, r.room_type_id,
                    s.first_entry_at, s.duracion_minutos
             FROM stays s
             JOIN rooms r ON r.id = s.room_id
              WHERE s.status IN ('OCCUPIED', 'EXITED')
               AND s.first_entry_at IS NOT NULL
               AND DATE_ADD(s.first_entry_at, INTERVAL s.duracion_minutos MINUTE) < UTC_TIMESTAMP(3)
             ORDER BY s.id ASC"
        );
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    private function base(): string
    {
        return 'SELECT id, stay_id, room_id, exceso_minutos, amount_eur_snapshot,
                       status, attempts, last_error, vb6_ack_ref, created_at, updated_at
                FROM debts';
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Debt
    {
        return new Debt(
            (int)    $row['id'],
            (int)    $row['stay_id'],
            (int)    $row['room_id'],
            (int)    $row['exceso_minutos'],
            $row['amount_eur_snapshot'] === null ? null : (int) $row['amount_eur_snapshot'],
            (string) $row['status'],
            (int)    $row['attempts'],
            $row['last_error'] === null ? null : (string) $row['last_error'],
            $row['vb6_ack_ref'] === null ? null : (string) $row['vb6_ack_ref'],
            (string) $row['created_at'],
            (string) $row['updated_at']
        );
    }
}
