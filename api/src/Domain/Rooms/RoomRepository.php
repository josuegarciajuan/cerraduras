<?php
declare(strict_types=1);

namespace App\Domain\Rooms;

use PDO;

/**
 * RoomRepository: persistence for rooms.
 */
final class RoomRepository implements RoomRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string,mixed> $filters Supported: status, room_type_id
     * @param int $limit
     * @param int $offset
     * @return list<Room>
     */
    public function listFiltered(array $filters, int $limit = 50, int $offset = 0): array
    {
        $wheres = [];
        $params = [];
        if (isset($filters['status']) && $filters['status'] !== '') {
            $wheres[] = 'status = :status';
            $params[':status'] = (string) $filters['status'];
        }
        if (isset($filters['room_type_id']) && $filters['room_type_id'] !== '') {
            $wheres[] = 'room_type_id = :rt';
            $params[':rt'] = (int) $filters['room_type_id'];
        }
        $sql = 'SELECT id, code, room_type_id, status, simulated_override, cooldown_until, notes, pack_id, presence_check_seconds
                FROM rooms';
        if (!empty($wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }
        $sql .= ' ORDER BY id ASC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findById(int $id): ?Room
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, room_type_id, status, simulated_override, cooldown_until, notes, pack_id, presence_check_seconds
             FROM rooms WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByCode(string $code): ?Room
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, room_type_id, status, simulated_override, cooldown_until, notes, pack_id, presence_check_seconds
             FROM rooms WHERE code = :c LIMIT 1'
        );
        $stmt->execute([':c' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(string $code, int $roomTypeId, ?bool $simulatedOverride, ?string $notes = null): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO rooms (code, room_type_id, simulated_override, status, notes)
             VALUES (:c, :rt, :sim, 'FREE', :notes)"
        );
        $stmt->execute([
            ':c' => $code,
            ':rt' => $roomTypeId,
            ':sim' => $simulatedOverride === null ? null : ($simulatedOverride ? 1 : 0),
            ':notes' => $notes,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): int
    {
        if (empty($fields)) {
            return 0;
        }
        $allowed = [
            'code' => ':code',
            'room_type_id' => ':rt',
            'pack_id' => ':pk',
            'status' => ':st',            // F31: needed by ExitActionService
            'cooldown_until' => ':cu',    // F31: needed by ExitActionService
            'presence_check_seconds' => ':pcs',
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
        $sql = 'UPDATE rooms SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * After removing a pack from a room, reset status to FREE
     * and close active stays if the room was RESERVED or OCCUPIED.
     * No-op for any other status.
     *
     * RF-21, design §21.
     */
    public function resetAfterPackRemoval(int $roomId): void
    {
        $stmt = $this->pdo->prepare('SELECT status FROM rooms WHERE id = :id');
        $stmt->execute([':id' => $roomId]);
        $status = $stmt->fetchColumn();

        if (!in_array($status, [Room::STATUS_RESERVED, Room::STATUS_OCCUPIED], true)) {
            return;
        }

        $this->pdo->prepare(
            "UPDATE rooms SET status = 'FREE', cooldown_until = NULL WHERE id = :id"
        )->execute([':id' => $roomId]);

        $this->pdo->prepare(
            "UPDATE stays SET status = 'CLOSED', closed_at = UTC_TIMESTAMP(3)
             WHERE room_id = :rid AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')"
        )->execute([':rid' => $roomId]);
    }

    /**
     * @param array<string,mixed> $row
     */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rooms WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function findByPackId(int $packId): ?Room
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, room_type_id, status, simulated_override, cooldown_until, notes, pack_id, presence_check_seconds
             FROM rooms WHERE pack_id = :pid LIMIT 1'
        );
        $stmt->execute([':pid' => $packId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    private function hydrate(array $row): Room
    {
        $sim = $row['simulated_override'];
        return new Room(
            (int) $row['id'],
            (string) $row['code'],
            (int) $row['room_type_id'],
            isset($row['pack_id']) && $row['pack_id'] !== null ? (int) $row['pack_id'] : null,
            (string) $row['status'],
            $sim === null ? null : (bool) ((int) $sim),
            $row['cooldown_until'] === null ? null : (string) $row['cooldown_until'],
            isset($row['notes']) ? ($row['notes'] === null ? null : (string) $row['notes']) : null,
            isset($row['presence_check_seconds']) && $row['presence_check_seconds'] !== null ? (int) $row['presence_check_seconds'] : null
        );
    }
}
