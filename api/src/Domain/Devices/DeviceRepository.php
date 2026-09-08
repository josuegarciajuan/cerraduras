<?php
declare(strict_types=1);

namespace App\Domain\Devices;

use PDO;

/**
 * DeviceRepository: persistence for the `devices` table.
 *
 * Canonical model (F30): devices are resolved ONLY through packs:
 *   room → room.pack_id → devices.pack_id
 * There is no devices.room_id anymore.
 */
final class DeviceRepository implements DeviceRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return list<Device> */
    public function listForRoom(int $roomId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.pack_id, d.kind, d.external_id, d.api_client_id, d.meta_json, d.is_identified, d.identified_at
             FROM devices d
             JOIN rooms r ON r.pack_id = d.pack_id
             WHERE r.id = :rid AND r.pack_id IS NOT NULL
             ORDER BY d.kind ASC'
        );
        $stmt->execute([':rid' => $roomId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<Device> */
    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, pack_id, kind, external_id, label, api_client_id, meta_json, battery_pct, is_identified, identified_at
             FROM devices
             ORDER BY (pack_id IS NOT NULL) DESC, kind ASC, id ASC'
        );
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findById(int $id): ?Device
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, pack_id, kind, external_id, label, api_client_id, meta_json, is_identified, identified_at
             FROM devices WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findForRoomKind(int $roomId, string $kind): ?Device
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.pack_id, d.kind, d.external_id, d.api_client_id, d.meta_json, d.is_identified, d.identified_at
             FROM devices d
             JOIN rooms r ON r.pack_id = d.pack_id
             WHERE d.kind = :k AND r.id = :rid AND r.pack_id IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute([':k' => $kind, ':rid' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByKindAndExternalId(string $kind, string $externalId): ?Device
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, pack_id, kind, external_id, label, api_client_id, meta_json, is_identified, identified_at
             FROM devices WHERE kind = :k AND external_id = :x LIMIT 1'
        );
        $stmt->execute([':k' => $kind, ':x' => $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByExternalId(string $externalId): ?Device
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, pack_id, kind, external_id, label, api_client_id, meta_json, is_identified, identified_at
             FROM devices WHERE external_id = :x LIMIT 1'
        );
        $stmt->execute([':x' => $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string,mixed>|null $meta
     */
    public function insert(int $packId, string $kind, string $externalId, ?string $label, ?int $apiClientId, ?array $meta): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO devices (pack_id, kind, external_id, label, api_client_id, meta_json)
             VALUES (:pid, :k, :x, :lbl, :ac, :m)'
        );
        $stmt->execute([
            ':pid' => $packId,
            ':k' => $kind,
            ':x' => $externalId,
            ':lbl' => $label,
            ':ac' => $apiClientId,
            ':m' => $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
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
            'external_id' => ':x',
            'label' => ':lbl',
            'api_client_id' => ':ac',
            'meta_json' => ':m',
            'pack_id' => ':pid',
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
        $sql = 'UPDATE devices SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** @return list<Device> */
    public function findByPackAndKind(int $packId, string $kind): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, pack_id, kind, external_id, label, api_client_id, meta_json, is_identified, identified_at
             FROM devices WHERE pack_id = :pid AND kind = :k'
        );
        $stmt->execute([':pid' => $packId, ':k' => $kind]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findOneByPackAndKind(int $packId, string $kind): ?Device
    {
        $list = $this->findByPackAndKind($packId, $kind);
        return $list[0] ?? null;
    }

    public function patch(int $id, array $fields): void
    {
        $allowed = ['external_id' => ':x', 'meta_json' => ':m', 'pack_id' => ':pid'];
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $col => $value) {
            if (!isset($allowed[$col])) continue;
            $sets[] = "`{$col}` = {$allowed[$col]}";
            $params[$allowed[$col]] = $value;
        }
        if (empty($sets)) return;
        $this->pdo->prepare('UPDATE devices SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    public function delete(int $id): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM devices WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount();
    }

    public function markIdentified(string $externalId, bool $state): ?Device
    {
        // Only RPI-kind devices can be identified
        $device = $this->findByKindAndExternalId(Device::KIND_RPI, $externalId);
        if ($device === null) {
            return null;
        }

        if ($state) {
            $stmt = $this->pdo->prepare(
                'UPDATE devices SET is_identified = 1, identified_at = UTC_TIMESTAMP(3), last_seen_at = UTC_TIMESTAMP(3) WHERE id = :id'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE devices SET is_identified = 0, last_seen_at = UTC_TIMESTAMP(3) WHERE id = :id'
            );
        }
        $stmt->execute([':id' => $device->id]);

        return $this->findById($device->id);
    }

    /** @return list<array{room_id:int,code:string,device_id:int,external_id:string,identified_at:string}> */
    public function findIdentified(): array
    {
        // Canonical (F30): the room is resolved through the pack (rooms.pack_id),
        // never through a direct devices.room_id column.
        $stmt = $this->pdo->query(
            'SELECT r.id AS room_id, r.code, d.id AS device_id, d.external_id, d.identified_at
             FROM devices d
             LEFT JOIN rooms r ON r.pack_id = d.pack_id
             WHERE d.kind = \'RPI\' AND d.is_identified = 1
             ORDER BY d.identified_at DESC'
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $r): array {
            return [
                'room_id'       => isset($r['room_id']) && $r['room_id'] !== null ? (int) $r['room_id'] : 0,
                'code'          => (string) $r['code'],
                'device_id'     => (int) $r['device_id'],
                'external_id'   => (string) $r['external_id'],
                'identified_at' => $r['identified_at'] ?? '',
            ];
        }, $rows);
    }

    public function updateLastSeen(int $deviceId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE devices SET last_seen_at = UTC_TIMESTAMP(3) WHERE id = :id'
        );
        $stmt->execute([':id' => $deviceId]);
    }

    /**
     * Refresh last_seen_at for every device of a given kind inside a pack.
     * Used by the ESP32 heartbeat to mark its sub-devices online without
     * depending on them sharing the chip external_id.
     *
     * @return int number of devices touched
     */
    public function touchPackKind(int $packId, string $kind): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE devices SET last_seen_at = UTC_TIMESTAMP(3)
             WHERE pack_id = :pid AND kind = :k'
        );
        $stmt->execute([':pid' => $packId, ':k' => $kind]);
        return $stmt->rowCount();
    }


    public function updateBattery(int $deviceId, ?int $pct): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE devices SET battery_pct = :pct WHERE id = :id'
        );
        $stmt->execute([':pct' => $pct, ':id' => $deviceId]);
    }

    public function resolveRoomId(int $deviceId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id FROM rooms r
             JOIN devices d ON d.pack_id = r.pack_id
             WHERE d.id = :did AND r.pack_id IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute([':did' => $deviceId]);
        $roomId = $stmt->fetchColumn();
        return $roomId !== false ? (int) $roomId : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): Device
    {
        $meta = null;
        if ($row['meta_json'] !== null) {
            $decoded = json_decode((string) $row['meta_json'], true);
            $meta = is_array($decoded) ? $decoded : null;
        }
        return new Device(
            (int) $row['id'],
            isset($row['pack_id']) && $row['pack_id'] !== null ? (int) $row['pack_id'] : null,
            (string) $row['kind'],
            (string) $row['external_id'],
            isset($row['label']) && $row['label'] !== null ? (string) $row['label'] : null,
            $row['api_client_id'] === null ? null : (int) $row['api_client_id'],
            $meta,
            isset($row['battery_pct']) && $row['battery_pct'] !== null ? (int) $row['battery_pct'] : null,
            (bool) ($row['is_identified'] ?? false),
            isset($row['identified_at']) && $row['identified_at'] !== null ? (string) $row['identified_at'] : null
        );
    }
}
