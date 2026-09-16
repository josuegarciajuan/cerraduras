<?php
declare(strict_types=1);

namespace App\Domain\Rooms;

use PDO;

/**
 * RoomTypeRepository: CRUD queries for room_types.
 *
 * All operations assume the caller is inside or outside a transaction; no
 * implicit BEGIN/COMMIT here. Errors bubble up as PDOException which the
 * ErrorHandler middleware converts to 500 unless caught higher up.
 */
final class RoomTypeRepository implements RoomTypeRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return list<RoomType> */
    public function listAll(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, code, name, grace_minutes, exit_presence_gap_seconds,
                    reentry_cooldown_seconds, qr_usage_window_minutes,
                    presence_entry_window_seconds, exit_check_seconds
             FROM room_types
             ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    public function findById(int $id): ?RoomType
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, name, grace_minutes, exit_presence_gap_seconds,
                    reentry_cooldown_seconds, qr_usage_window_minutes,
                    presence_entry_window_seconds, exit_check_seconds
             FROM room_types WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByCode(string $code): ?RoomType
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, name, grace_minutes, exit_presence_gap_seconds,
                    reentry_cooldown_seconds, qr_usage_window_minutes,
                    presence_entry_window_seconds, exit_check_seconds
             FROM room_types WHERE code = :c LIMIT 1'
        );
        $stmt->execute([':c' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(
        string $code,
        string $name,
        int $graceMinutes,
        int $exitPresenceGapSeconds,
        int $reentryCooldownSeconds,
        int $qrUsageWindowMinutes,
        int $presenceEntryWindowSeconds = 90,
        int $exitCheckSeconds = 40
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO room_types
                (code, name, grace_minutes, exit_presence_gap_seconds,
                 reentry_cooldown_seconds, qr_usage_window_minutes,
                 presence_entry_window_seconds, exit_check_seconds)
             VALUES (:code, :name, :g, :ex, :re, :qr, :ew, :xc)'
        );
        $stmt->execute([
            ':code' => $code,
            ':name' => $name,
            ':g' => $graceMinutes,
            ':ex' => $exitPresenceGapSeconds,
            ':re' => $reentryCooldownSeconds,
            ':qr' => $qrUsageWindowMinutes,
            ':ew' => $presenceEntryWindowSeconds,
            ':xc' => $exitCheckSeconds,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update only the provided fields. Keys correspond to RoomType properties
     * in snake_case (grace_minutes, etc.). Returns the number of affected rows.
     *
     * @param array<string,int|string> $fields
     */
    public function update(int $id, array $fields): int
    {
        if (empty($fields)) {
            return 0;
        }
        $allowed = [
            'code' => ':code',
            'name' => ':name',
            'grace_minutes' => ':g',
            'exit_presence_gap_seconds' => ':ex',
            'reentry_cooldown_seconds' => ':re',
            'qr_usage_window_minutes' => ':qr',
            'presence_entry_window_seconds' => ':ew',
            'exit_check_seconds' => ':xc',
        ];
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $col => $value) {
            if (!isset($allowed[$col])) {
                continue;
            }
            $sets[] = "`{$col}` = {$allowed[$col]}";
            $params[$allowed[$col]] = $value;
        }
        if (empty($sets)) {
            return 0;
        }
        $sql = 'UPDATE room_types SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * @param array<string,mixed> $row
     */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM room_types WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function hydrate(array $row): RoomType
    {
        return new RoomType(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['name'],
            (int) $row['grace_minutes'],
            (int) $row['exit_presence_gap_seconds'],
            (int) $row['reentry_cooldown_seconds'],
            (int) $row['qr_usage_window_minutes'],
            (int) ($row['presence_entry_window_seconds'] ?? 90),
            (int) ($row['exit_check_seconds'] ?? 40)
        );
    }
}
