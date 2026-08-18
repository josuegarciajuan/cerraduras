<?php
declare(strict_types=1);

namespace App\Domain\Debts;

use PDO;
use PDOException;

/**
 * OutboxVb6Repository: manages the `outbox_vb6` queue used for reliable
 * delivery of events/debts to the WS-VB6 bridge.
 *
 * Only enqueue() is needed for F10. The full worker (fetchDue, markSent,
 * markFailed, backoff) is implemented in F14 (TSK-151/152).
 *
 * See design.md §4.14, §13.
 */
final class OutboxVb6Repository implements OutboxVb6RepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Enqueue a topic event for delivery to WS-VB6.
     *
     * Uses INSERT IGNORE so re-enqueueing with the same idempotency_key is a
     * no-op (idempotent by design).
     *
     * @param array<string,mixed> $payload
     * @return int  New row ID, or 0 if the key already existed (IGNORE).
     */
    public function enqueue(string $topic, array $payload, string $idempotencyKey): int
    {
        try {
            $this->pdo->prepare(
                "INSERT IGNORE INTO outbox_vb6
                    (topic, payload_json, idempotency_key, status, next_attempt_at)
                 VALUES
                    (:topic, :payload, :idem, 'PENDING', UTC_TIMESTAMP(3))"
            )->execute([
                ':topic'   => $topic,
                ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':idem'    => $idempotencyKey,
            ]);
        } catch (PDOException $e) {
            // Swallow duplicate-key errors from the UNIQUE on idempotency_key.
            // Any other DB error propagates.
            if (!str_contains($e->getMessage(), '1062') && !str_contains((string)$e->getCode(), '23000')) {
                throw $e;
            }
        }
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Mark an outbox entry so its next_attempt_at = now (immediate retry).
     * Used by POST /debts/{id}/resync.
     */
    public function scheduleRetry(string $idempotencyKey): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE outbox_vb6
             SET next_attempt_at = UTC_TIMESTAMP(3), status = 'PENDING'
             WHERE idempotency_key = :idem
               AND status IN ('PENDING','FAILED')"
        );
        $stmt->execute([':idem' => $idempotencyKey]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Fetch outbox items that are due for processing.
     *
     * @return list<array<string,mixed>>
     */
    public function fetchDue(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, topic, payload_json, idempotency_key, attempts
             FROM outbox_vb6
             WHERE status = 'PENDING'
               AND next_attempt_at <= UTC_TIMESTAMP(3)
             ORDER BY next_attempt_at ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Mark an outbox item as successfully sent.
     */
    public function markSent(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE outbox_vb6
             SET status = 'SENT'
             WHERE id = :id"
        )->execute([':id' => $id]);
    }

    /**
     * Mark an outbox item as failed and schedule a retry with exponential backoff.
     *
     * Backoff: min(2^attempts, 300) seconds.
     * Max retries: 20. After that, status = 'FAILED'.
     */
    public function markFailed(int $id, string $error, int $attempts): void
    {
        $nextAttempts = $attempts + 1;
        $backoffSeconds = min((int) pow(2, $attempts), 300);

        $newStatus = $nextAttempts >= 20 ? 'FAILED' : 'PENDING';

        $this->pdo->prepare(
            "UPDATE outbox_vb6
             SET attempts        = :attempts,
                 status          = :status,
                 last_error      = :error,
                 next_attempt_at = DATE_ADD(UTC_TIMESTAMP(3), INTERVAL :backoff SECOND)
             WHERE id = :id"
        )->execute([
            ':attempts' => $nextAttempts,
            ':status'   => $newStatus,
            ':error'    => substr($error, 0, 512),
            ':backoff'  => $backoffSeconds,
            ':id'       => $id,
        ]);
    }
}
