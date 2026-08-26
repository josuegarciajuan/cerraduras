<?php declare(strict_types=1);

namespace App\Domain\Devices;

use PDO;

final class DevicePackRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return DevicePack[] */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT dp.*, MIN(r.id) as room_id FROM device_packs dp LEFT JOIN rooms r ON r.pack_id = dp.id GROUP BY dp.id ORDER BY dp.name ASC');
        $packs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pack = new DevicePack($row);
            $pack->devices = $this->resolveDevices($pack->id);
            $packs[] = $pack;
        }
        return $packs;
    }

    public function findById(int $id): ?DevicePack
    {
        $stmt = $this->pdo->prepare('SELECT dp.*, r.id as room_id FROM device_packs dp LEFT JOIN rooms r ON r.pack_id = dp.id WHERE dp.id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $pack = new DevicePack($row);
        $pack->devices = $this->resolveDevices($pack->id);
        return $pack;
    }

    public function findByCode(string $code): ?DevicePack
    {
        $stmt = $this->pdo->prepare('SELECT dp.*, r.id as room_id FROM device_packs dp LEFT JOIN rooms r ON r.pack_id = dp.id WHERE dp.code = :code');
        $stmt->bindValue(':code', $code);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $pack = new DevicePack($row);
        $pack->devices = $this->resolveDevices($pack->id);
        return $pack;
    }

    /** @return list<array{id:int, kind:string, external_id:string, label:?string, meta:?array, battery_pct:?int}> */
    private function resolveDevices(int $packId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, kind, external_id, label, meta_json, battery_pct FROM devices WHERE pack_id = :pid ORDER BY kind'
        );
        $stmt->bindValue(':pid', $packId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['meta'] = !empty($r['meta_json']) ? json_decode($r['meta_json'], true) : null;
            unset($r['meta_json']);
            $r['battery_pct'] = $r['battery_pct'] !== null ? (int) $r['battery_pct'] : null;
        }
        return $rows;
    }

    public function insert(string $code, string $name): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO device_packs (code, name, created_at, updated_at) VALUES (:code, :name, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
        );
        $stmt->bindValue(':code', $code);
        $stmt->bindValue(':name', $name);
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $code, string $name): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE device_packs SET code = :code, name = :name, updated_at = UTC_TIMESTAMP(3) WHERE id = :id'
        );
        $stmt->bindValue(':code', $code);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function delete(int $id): void
    {
        // RF-21: reset rooms that are RESERVED/OCCUPIED before unlinking
        $affected = $this->pdo->prepare(
            "SELECT id FROM rooms WHERE pack_id = :pid AND status IN ('RESERVED','OCCUPIED')"
        );
        $affected->execute([':pid' => $id]);
        $roomIds = $affected->fetchAll(PDO::FETCH_COLUMN);

        foreach ($roomIds as $rid) {
            $this->pdo->prepare(
                "UPDATE rooms SET status = 'FREE', cooldown_until = NULL WHERE id = :id"
            )->execute([':id' => (int) $rid]);

            $this->pdo->prepare(
                "UPDATE stays SET status = 'CLOSED', closed_at = UTC_TIMESTAMP(3)
                 WHERE room_id = :rid AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')"
            )->execute([':rid' => (int) $rid]);
        }

        // Unlink rooms first
        $this->pdo->prepare('UPDATE rooms SET pack_id = NULL WHERE pack_id = :pid')->execute([':pid' => $id]);
        // Devices stay (they just become unassigned)
        $this->pdo->prepare('UPDATE devices SET pack_id = NULL WHERE pack_id = :pid')->execute([':pid' => $id]);
        // Delete pack
        $stmt = $this->pdo->prepare('DELETE FROM device_packs WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }
}
