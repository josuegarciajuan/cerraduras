<?php
declare(strict_types=1);

namespace App\Domain\Qr;

use PDO;

/**
 * QrCredentialRepository: PDO implementation of QrCredentialRepositoryInterface.
 */
final class QrCredentialRepository implements QrCredentialRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function insert(
        int $stayId,
        int $roomId,
        string $jti,
        string $tokenHash,
        string $issuedAtUtc,
        string $expiresAtUtc
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO qr_credentials
                (stay_id, room_id, jti, token_hash, issued_at, expires_at)
             VALUES
                (:s, :r, :j, :h, :i, :e)'
        );
        $stmt->execute([
            ':s' => $stayId,
            ':r' => $roomId,
            ':j' => $jti,
            ':h' => $tokenHash,
            ':i' => $issuedAtUtc,
            ':e' => $expiresAtUtc,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findByJti(string $jti): ?QrCredential
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, stay_id, room_id, jti, token_hash, issued_at, expires_at, consumed_at, revoked_at
             FROM qr_credentials WHERE jti = :j LIMIT 1'
        );
        $stmt->execute([':j' => $jti]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByTokenHash(string $tokenHash): ?QrCredential
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, stay_id, room_id, jti, token_hash, issued_at, expires_at, consumed_at, revoked_at
             FROM qr_credentials WHERE token_hash = :h LIMIT 1'
        );
        $stmt->execute([':h' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function markConsumed(string $jti): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE qr_credentials
             SET consumed_at = UTC_TIMESTAMP(3)
             WHERE jti = :j AND consumed_at IS NULL AND revoked_at IS NULL'
        );
        $stmt->execute([':j' => $jti]);
        return $stmt->rowCount() === 1;
    }

    public function markRevoked(string $jti): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE qr_credentials
             SET revoked_at = UTC_TIMESTAMP(3)
             WHERE jti = :j AND revoked_at IS NULL'
        );
        $stmt->execute([':j' => $jti]);
        return $stmt->rowCount() === 1;
    }

    /** @return array<int, array<string,mixed>> */
    public function findAllFiltered(array $filters, int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['room_id'])) {
            $where[] = 'qc.room_id = :rid';
            $params[':rid'] = (int) $filters['room_id'];
        }
        if (!empty($filters['stay_id'])) {
            $where[] = 'qc.stay_id = :sid';
            $params[':sid'] = (int) $filters['stay_id'];
        }
        if (!empty($filters['jti'])) {
            $where[] = 'qc.jti LIKE :jti';
            $params[':jti'] = '%' . $filters['jti'] . '%';
        }

        // Status filter
        $status = $filters['status'] ?? null;
        if ($status === 'active') {
            $where[] = 'qc.consumed_at IS NULL AND qc.revoked_at IS NULL AND qc.expires_at > UTC_TIMESTAMP(3)';
        } elseif ($status === 'consumed') {
            $where[] = 'qc.consumed_at IS NOT NULL';
        } elseif ($status === 'revoked') {
            $where[] = 'qc.revoked_at IS NOT NULL';
        } elseif ($status === 'expired') {
            $where[] = 'qc.expires_at <= UTC_TIMESTAMP(3) AND qc.consumed_at IS NULL AND qc.revoked_at IS NULL';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT qc.*, r.code as room_code
                FROM qr_credentials qc
                JOIN rooms r ON r.id = qc.room_id
                {$whereSql}
                ORDER BY qc.issued_at DESC
                LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): QrCredential
    {
        return new QrCredential(
            (int) $row['id'],
            (int) $row['stay_id'],
            (int) $row['room_id'],
            (string) $row['jti'],
            (string) $row['token_hash'],
            (string) $row['issued_at'],
            (string) $row['expires_at'],
            $row['consumed_at'] === null ? null : (string) $row['consumed_at'],
            $row['revoked_at'] === null ? null : (string) $row['revoked_at']
        );
    }
}
