<?php
declare(strict_types=1);

namespace App\Support\Idempotency;

use PDO;

/**
 * IdempotencyStore: CRUD against the `idempotency_keys` table.
 *
 * Semantics (contracts.md §1.5):
 *   - A record is uniquely keyed by (scope, key_value, client_id).
 *   - Stored response is the JSON body + status to replay for matching requests.
 *   - Records older than expires_at are treated as non-existent by callers.
 */
final class IdempotencyStore
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array{id:int, request_hash:string, response_status:int, response_body:?string, expires_at:string}|null
     */
    public function find(string $scope, string $key, int $clientId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, request_hash, response_status, response_body, expires_at
             FROM idempotency_keys
             WHERE scope = :scope AND key_value = :k AND client_id = :c
               AND expires_at > UTC_TIMESTAMP(3)
             LIMIT 1'
        );
        $stmt->execute([':scope' => $scope, ':k' => $key, ':c' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'request_hash' => (string) $row['request_hash'],
            'response_status' => (int) $row['response_status'],
            'response_body' => $row['response_body'] === null ? null : (string) $row['response_body'],
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    public function insert(
        string $scope,
        string $key,
        int $clientId,
        string $requestHash,
        int $responseStatus,
        string $responseBody,
        int $ttlSeconds
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO idempotency_keys
                (scope, key_value, client_id, request_hash, response_status, response_body, expires_at)
             VALUES
                (:scope, :k, :c, :h, :st, :b, DATE_ADD(UTC_TIMESTAMP(3), INTERVAL :ttl SECOND))'
        );
        $stmt->execute([
            ':scope' => $scope,
            ':k' => $key,
            ':c' => $clientId,
            ':h' => $requestHash,
            ':st' => $responseStatus,
            ':b' => $responseBody,
            ':ttl' => $ttlSeconds,
        ]);
    }

    public function purgeExpired(): int
    {
        return (int) $this->pdo->exec('DELETE FROM idempotency_keys WHERE expires_at <= UTC_TIMESTAMP(3)');
    }
}
