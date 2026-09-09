<?php
declare(strict_types=1);

namespace App\Domain\Devices;

use PDO;

/**
 * CommandQueueRepository: persistence for the `device_commands` table (F33).
 *
 * The ESP32 is pull-based: the API enqueues a `check` command for a chip and
 * the device picks it up on its heartbeat cycle. Every command is keyed by the
 * OWNER chipId (the RPI external_id of the pack), because that is the only id
 * the firmware knows and sends (heartbeat / pending-command / command-result).
 *
 * Status flow: pending -> picked_up -> done (or -> timeout).
 */
final class CommandQueueRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** True if the chip has at least one command still pending (not yet picked up). */
    public function hasPending(string $externalId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM device_commands WHERE external_id = :x AND status = 'pending'"
        );
        $stmt->execute([':x' => $externalId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Enqueue a new command for the chip. Returns the new command id.
     * Idempotency: if there is already a `pending` command of the same type for
     * the chip, it is not duplicated.
     */
    public function enqueue(string $externalId, string $command = 'check'): ?int
    {
        if ($command === '') {
            $command = 'check';
        }
        $stmt = $this->pdo->prepare(
            "SELECT id FROM device_commands
             WHERE external_id = :x AND command = :c AND status = 'pending' LIMIT 1"
        );
        $stmt->execute([':x' => $externalId, ':c' => $command]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }

        $ins = $this->pdo->prepare(
            "INSERT INTO device_commands (external_id, command, status) VALUES (:x, :c, 'pending')"
        );
        $ins->execute([':x' => $externalId, ':c' => $command]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Expire this chip's `picked_up` commands that never reported a result.
     * A chip that picked a command and then went silent (offline / watchdog
     * reset) would otherwise leave it `picked_up` forever. Re-armed the next
     * time the chip contacts the API so the queue does not wedge or leak.
     */
    public function expirePickedUp(string $externalId, int $staleSeconds = 120): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE device_commands
             SET status = 'timeout', updated_at = CURRENT_TIMESTAMP(3)
             WHERE external_id = :x AND status = 'picked_up'
               AND updated_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL :s SECOND)"
        );
        $stmt->execute([':x' => $externalId, ':s' => $staleSeconds]);
        return $stmt->rowCount();
    }

    /**
     * Return the oldest `pending` command for the chip and atomically mark it
     * `picked_up`. Returns null when there is nothing to do.
     *
     * @return array<string,mixed>|null
     */
    public function pickUp(string $externalId): ?array
    {
        // First expire this chip's wedged picked_up commands.
        $this->expirePickedUp($externalId);

        $stmt = $this->pdo->prepare(
            "SELECT id, external_id, command, payload_json, created_at
             FROM device_commands
             WHERE external_id = :x AND status = 'pending'
             ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([':x' => $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $mark = $this->pdo->prepare(
            "UPDATE device_commands
             SET status = 'picked_up', updated_at = CURRENT_TIMESTAMP(3)
             WHERE id = :id AND external_id = :x AND status = 'pending'"
        );
        $mark->execute([':id' => $row['id'], ':x' => $externalId]);

        return [
            'id'           => (int) $row['id'],
            'external_id'  => $row['external_id'],
            'command'      => $row['command'],
            'payload'      => $row['payload_json'] !== null ? json_decode($row['payload_json'], true) : null,
            'created_at'   => $row['created_at'],
        ];
    }

    /**
     * Complete a command with its results. Only the owning chip may finish it.
     * Returns the number of rows updated (0 => not found or not owned).
     *
     * @param array<string,mixed> $results
     */
    public function finish(int $commandId, string $externalId, array $results): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE device_commands
             SET status = 'done', result_json = :r, updated_at = CURRENT_TIMESTAMP(3)
             WHERE id = :id AND external_id = :x AND status IN ('pending','picked_up')"
        );
        $stmt->execute([
            ':r' => json_encode($results),
            ':id' => $commandId,
            ':x' => $externalId,
        ]);
        return $stmt->rowCount();
    }

    /** Clear pending/picked_up commands older than $olderThanHours (default 24). */
    public function purgeStale(int $olderThanHours = 24): int
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM device_commands
             WHERE status IN ('pending','picked_up')
               AND updated_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL :h HOUR)"
        );
        $stmt->execute([':h' => $olderThanHours]);
        return $stmt->rowCount();
    }
}
