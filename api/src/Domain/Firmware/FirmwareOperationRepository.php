<?php
declare(strict_types=1);

namespace App\Domain\Firmware;

use PDO;

/**
 * FirmwareOperationRepository — F100/RF-181.
 *
 * One row per requested update. The row is the panel's source of truth: it
 * survives page reloads and the browser being closed. All timestamps are UTC.
 */
final class FirmwareOperationRepository
{
    /** Active = the device may still be working on it (or result is unknown). */
    private const ACTIVE = ['WAITING', 'PREPARING', 'DOWNLOADING', 'VERIFYING', 'REBOOTING', 'VALIDATING', 'UNCONFIRMED'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array<string,mixed>|null */
    public function findActiveForDevice(int $deviceId): ?array
    {
        $in = implode(',', array_fill(0, count(self::ACTIVE), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM device_firmware_operations
              WHERE device_id = ? AND status IN ({$in})
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(array_merge([$deviceId], self::ACTIVE));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->normalizeRow($row);
    }

    /** @return list<array<string,mixed>> */
    public function listForDevice(int $deviceId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM device_firmware_operations
              WHERE device_id = :id ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute([':id' => $deviceId]);
        return array_map([$this, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM device_firmware_operations WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->normalizeRow($row);
    }

    /**
     * Oldest operation accepted by this chip that still needs device work.
     * The ESP asks for one manifest at a time.
     *
     * @return array<string,mixed>|null
     */
    public function findNextForExternal(string $externalId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM device_firmware_operations
              WHERE external_id = :x
                AND status IN ('WAITING','PREPARING','DOWNLOADING','VERIFYING','UNCONFIRMED')
              ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([':x' => $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->normalizeRow($row);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO device_firmware_operations
                (device_id, external_id, release_id, requested_by, from_variant,
                 from_version, to_variant, to_version, status, progress_pct, expires_at)
             VALUES
                (:device_id, :external_id, :release_id, :requested_by, :from_variant,
                 :from_version, :to_variant, :to_version, 'WAITING', 0,
                 DATE_ADD(UTC_TIMESTAMP(3), INTERVAL :ttl MINUTE))"
        );
        $stmt->execute([
            ':device_id'    => (int) $data['device_id'],
            ':external_id'  => (string) $data['external_id'],
            ':release_id'   => (int) $data['release_id'],
            ':requested_by' => $data['requested_by'] ?? null,
            ':from_variant' => $data['from_variant'] ?? null,
            ':from_version' => $data['from_version'] ?? null,
            ':to_variant'   => strtoupper((string) $data['to_variant']),
            ':to_version'   => (string) $data['to_version'],
            ':ttl'          => max(5, (int) ($data['ttl_minutes'] ?? 1440)),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function markAccepted(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE device_firmware_operations
                SET status = CASE WHEN status = 'WAITING' THEN 'PREPARING' ELSE status END,
                    accepted_at = COALESCE(accepted_at, UTC_TIMESTAMP(3)),
                    last_report_at = UTC_TIMESTAMP(3)
              WHERE id = :id"
        )->execute([':id' => $id]);
    }

    /**
     * Apply a device report. Transitions are validated by the service before
     * calling this method; here we additionally make progress monotonic.
     *
     * @param array<string,mixed>|null $extra
     */
    public function updateStatus(
        int $id,
        string $status,
        int $progress,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        bool $finished = false
    ): void {
        $this->pdo->prepare(
            "UPDATE device_firmware_operations
                SET status = :st,
                    progress_pct = GREATEST(progress_pct, :p),
                    error_code = :ec,
                    error_message = :em,
                    last_report_at = UTC_TIMESTAMP(3),
                    finished_at = CASE WHEN :fin = 1 THEN UTC_TIMESTAMP(3) ELSE finished_at END
              WHERE id = :id"
        )->execute([
            ':st' => strtoupper($status),
            ':p'  => max(0, min(100, $progress)),
            ':ec' => $errorCode,
            ':em' => $errorMessage,
            ':fin' => $finished ? 1 : 0,
            ':id' => $id,
        ]);
    }

    public function cancel(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE device_firmware_operations
                SET status = 'CANCELLED', finished_at = UTC_TIMESTAMP(3), last_report_at = UTC_TIMESTAMP(3)
              WHERE id = :id AND status = 'WAITING'"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function expireStale(): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE device_firmware_operations
                SET status = 'EXPIRED', finished_at = UTC_TIMESTAMP(3), last_report_at = UTC_TIMESTAMP(3)
              WHERE status = 'WAITING' AND expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP(3)"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** @param array<string,mixed> $row */
    private function normalizeRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['device_id'] = (int) $row['device_id'];
        $row['release_id'] = (int) $row['release_id'];
        $row['progress_pct'] = (int) $row['progress_pct'];
        $row['status_label'] = FirmwareOperationState::publicLabel((string) $row['status']);
        $row['finished'] = FirmwareOperationState::isTerminal((string) $row['status']);
        return $row;
    }
}
