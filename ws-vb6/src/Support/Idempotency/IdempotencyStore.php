<?php
declare(strict_types=1);

namespace Ws\Support\Idempotency;

use PDO;
use PDOException;

/**
 * IdempotencyStore (WS-VB6): stores/retrieves cached responses in the
 * vb6_bridge_idempotency table on the auxiliary DB.
 *
 * Semantics:
 *  - Same (scope + key + requestHash) → return cached response.
 *  - Same (scope + key) but different requestHash → conflict (409).
 *  - New (scope + key) → null (caller processes and then calls store()).
 *
 * TTL: 7 days (configurable).
 */
final class IdempotencyStore
{
    private PDO $pdo;
    private int $ttlSeconds;

    public function __construct(PDO $pdo, int $ttlSeconds = 7 * 86400)
    {
        $this->pdo        = $pdo;
        $this->ttlSeconds = $ttlSeconds;
    }

    /**
     * Look up an existing idempotency record.
     *
     * Returns:
     *   ['status' => int, 'body' => string]  if found (same hash → replay).
     *   'conflict'                            if same key but different hash.
     *   null                                  if not found.
     */
    public function lookup(string $scope, string $key, string $requestHash): mixed
    {
        $stmt = $this->pdo->prepare(
            'SELECT request_hash, response_status, response_body
             FROM vb6_bridge_idempotency
             WHERE scope = :s AND `key` = :k AND expires_at > UTC_TIMESTAMP(3)
             LIMIT 1'
        );
        $stmt->execute([':s' => $scope, ':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }
        if ($row['request_hash'] !== $requestHash) {
            return 'conflict';
        }
        return [
            'status' => (int) $row['response_status'],
            'body'   => (string) $row['response_body'],
        ];
    }

    /**
     * Store a successful response.
     *
     * @param array<string,mixed>|null $vb6Summary
     */
    public function store(
        string  $scope,
        string  $key,
        string  $requestHash,
        int     $status,
        string  $body,
        ?array  $vb6Summary = null
    ): void {
        $expires = date(
            'Y-m-d H:i:s.000',
            time() + $this->ttlSeconds
        );
        try {
            $this->pdo->prepare(
                'INSERT INTO vb6_bridge_idempotency
                    (scope, `key`, request_hash, response_status, response_body, vb6_write_summary, expires_at)
                 VALUES (:s, :k, :h, :st, :b, :v, :exp)
                 ON DUPLICATE KEY UPDATE
                    request_hash    = VALUES(request_hash),
                    response_status = VALUES(response_status),
                    response_body   = VALUES(response_body),
                    vb6_write_summary = VALUES(vb6_write_summary),
                    expires_at      = VALUES(expires_at)'
            )->execute([
                ':s'   => $scope,
                ':k'   => $key,
                ':h'   => $requestHash,
                ':st'  => $status,
                ':b'   => $body,
                ':v'   => $vb6Summary === null ? null : json_encode($vb6Summary),
                ':exp' => $expires,
            ]);
        } catch (PDOException $e) {
            // Non-fatal: log and continue (idempotency is best-effort).
            error_log('[IdempotencyStore] Failed to store: ' . $e->getMessage());
        }
    }
}
