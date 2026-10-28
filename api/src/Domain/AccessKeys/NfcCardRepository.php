<?php
declare(strict_types=1);

namespace App\Domain\AccessKeys;

use PDO;

/**
 * NfcCardRepository: PDO persistence for physical NFC cards (F96).
 *
 * The card code is never stored in clear text, only its SHA-256 hash and the
 * last four characters for operator identification.
 */
final class NfcCardRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare($this->baseSelect() . ' WHERE c.id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /** @return array<string,mixed>|null */
    public function findByCodeHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare($this->baseSelect() . ' WHERE c.code_hash = :h LIMIT 1');
        $stmt->execute([':h' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array{status?:string, q?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function findAll(array $filters = []): array
    {
        $wheres = [];
        $params = [];

        if (!empty($filters['status'])) {
            $wheres[] = 'c.status = :st';
            $params[':st'] = (string) $filters['status'];
        }
        if (!empty($filters['q'])) {
            $wheres[] = '(c.number LIKE :q1 OR k.label LIKE :q2)';
            $qv = '%' . (string) $filters['q'] . '%';
            $params[':q1'] = $qv; $params[':q2'] = $qv;
        }

        $sql = $this->baseSelect();
        if ($wheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }
        $sql .= ' ORDER BY c.number ASC LIMIT 500';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> */
    public function findAvailable(): array
    {
        return $this->findAll(['status' => 'AVAILABLE']);
    }

    public function insert(string $number, string $codeHash, ?string $last4, ?string $notes = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO nfc_cards (number, code_hash, code_last4, status, notes)
             VALUES (:number, :hash, :last4, \'AVAILABLE\', :notes)'
        );
        $stmt->execute([
            ':number' => $number,
            ':hash'   => $codeHash,
            ':last4'  => $last4,
            ':notes'  => $notes,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateNumber(int $id, string $number, ?string $notes): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE nfc_cards SET number = :number, notes = :notes WHERE id = :id'
        );
        $stmt->execute([':number' => $number, ':notes' => $notes, ':id' => $id]);
        return $stmt->rowCount();
    }

    public function setStatus(int $id, string $status): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE nfc_cards SET status = :st WHERE id = :id'
        );
        $stmt->execute([':st' => $status, ':id' => $id]);
        return $stmt->rowCount();
    }

    /**
     * Bind an available card to a key. Returns false when the card is already
     * bound to another key.
     */
    public function assignToKey(int $cardId, int $keyId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE nfc_cards
                SET current_key_id = :kid, status = 'ASSIGNED'
              WHERE id = :id AND (current_key_id IS NULL OR current_key_id = :kid2)"
        );
        $stmt->execute([':kid' => $keyId, ':kid2' => $keyId, ':id' => $cardId]);
        return $stmt->rowCount() > 0;
    }

    /** Release the card currently bound to a key (idempotent). */
    public function releaseFromKey(int $keyId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE nfc_cards
                SET current_key_id = NULL,
                    status = CASE WHEN status = 'DISABLED' THEN 'DISABLED' ELSE 'AVAILABLE' END
              WHERE current_key_id = :kid"
        );
        $stmt->execute([':kid' => $keyId]);
    }

    public function numberExists(string $number, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM nfc_cards WHERE number = :n';
        $params = [':n' => $number];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $exceptId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    public function codeExists(string $hash): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM nfc_cards WHERE code_hash = :h LIMIT 1');
        $stmt->execute([':h' => $hash]);
        return (bool) $stmt->fetchColumn();
    }

    private function baseSelect(): string
    {
        return 'SELECT c.id, c.number, c.code_hash, c.code_last4, c.status,
                       c.current_key_id, c.notes, c.created_at, c.updated_at,
                       k.label AS current_key_label
                FROM nfc_cards c
                LEFT JOIN access_keys k ON k.id = c.current_key_id';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id'                => (int) $row['id'],
            'number'            => (string) $row['number'],
            'code_last4'        => $row['code_last4'] !== null ? (string) $row['code_last4'] : null,
            'code_masked'       => $row['code_last4'] !== null ? '••••' . $row['code_last4'] : null,
            'status'            => (string) $row['status'],
            'current_key_id'    => $row['current_key_id'] !== null ? (int) $row['current_key_id'] : null,
            'current_key_label' => $row['current_key_label'] !== null ? (string) $row['current_key_label'] : null,
            'notes'             => $row['notes'] !== null ? (string) $row['notes'] : null,
            'created_at'        => (string) $row['created_at'],
            'updated_at'        => (string) $row['updated_at'],
        ];
    }
}
