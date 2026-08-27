<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\FactoryDevices\FactoryDevice;
use App\Domain\FactoryDevices\FactoryDeviceRepositoryInterface;
use PDO;

final class FactoryDeviceRepository implements FactoryDeviceRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function findById(int $id): ?FactoryDevice { return $this->fetch('id = :id', [':id'=>$id]); }
    public function findByChipId(string $chipId): ?FactoryDevice { return $this->fetch('chip_id = :chip', [':chip'=>$chipId]); }

    public function announce(string $chipId): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("INSERT INTO factory_devices (chip_id,status,first_announced_at,last_announced_at)
                VALUES (:chip,'PENDING',CURRENT_TIMESTAMP(3),CURRENT_TIMESTAMP(3))
                ON DUPLICATE KEY UPDATE last_announced_at=CURRENT_TIMESTAMP(3), updated_at=CURRENT_TIMESTAMP(3)");
            $stmt->execute([':chip'=>$chipId]);
            $device = $this->findByChipId($chipId);
            $created = $stmt->rowCount() === 1;
            $this->pdo->commit(); return [$device, $created];
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    public function claimAndAudit(int $id, string $actor, ?int $actorClientId = null): ?FactoryDevice
    {
        $this->pdo->beginTransaction();
        try {
            $device = $this->fetchForUpdate($id);
            if ($device === null) { $this->pdo->commit(); return null; }
            if ($device->status === FactoryDevice::STATUS_PENDING) {
                $before = $device->status;
                $findRpi = $this->pdo->prepare('SELECT id FROM devices WHERE kind = \'RPI\' AND external_id = :chip LIMIT 1 FOR UPDATE');
                $findRpi->execute([':chip' => $device->chipId]);
                $deviceRow = $findRpi->fetch(PDO::FETCH_ASSOC);
                if ($deviceRow === false) {
                    $createRpi = $this->pdo->prepare("INSERT INTO devices (room_id, pack_id, kind, external_id, label, api_client_id, meta_json) VALUES (NULL, NULL, 'RPI', :chip, :label, NULL, :meta)");
                    $createRpi->execute([':chip' => $device->chipId, ':label' => 'RPI '.$device->chipId, ':meta' => json_encode(['source' => 'factory_claim'], JSON_UNESCAPED_UNICODE)]);
                    $deviceId = (int) $this->pdo->lastInsertId();
                } else {
                    $deviceId = (int) $deviceRow['id'];
                }
                $stmt = $this->pdo->prepare("UPDATE factory_devices SET status='CLAIMED', claimed_at=CURRENT_TIMESTAMP(3), claimed_by=:actor, device_id=:device_id, updated_at=CURRENT_TIMESTAMP(3) WHERE id=:id");
                $stmt->execute([':id'=>$id, ':actor'=>$actor, ':device_id'=>$deviceId]);
                $device = $this->fetchForUpdate($id);
                $audit = $this->pdo->prepare('INSERT INTO audit_log (actor_client_id,scope,action,entity,entity_id,correlation_id,payload_json) VALUES (:client,\'factory:claim\',\'claim\',\'factory_device\',:id,:corr,:payload)');
                $audit->execute([':client'=>$actorClientId, ':id'=>(string)$id, ':corr'=>str_pad((string)$id,26,'0',STR_PAD_LEFT), ':payload'=>json_encode(['chip_id'=>$device->chipId,'status_before'=>$before,'status_after'=>'CLAIMED','actor'=>$actor])]);
            }
            $this->pdo->commit(); return $device;
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    public function list(string $status): array
    {
        $sql = 'SELECT * FROM factory_devices'; $params=[];
        if ($status !== 'ALL') { $sql .= ' WHERE status=:status'; $params[':status']=$status; }
        $sql .= ' ORDER BY first_announced_at ASC'; $stmt=$this->pdo->prepare($sql); $stmt->execute($params);
        return array_map([$this,'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }


    private function fetch(string $where, array $params): ?FactoryDevice { $s=$this->pdo->prepare('SELECT * FROM factory_devices WHERE '.$where.' LIMIT 1'); $s->execute($params); $r=$s->fetch(PDO::FETCH_ASSOC); return $r===false?null:$this->hydrate($r); }
    private function fetchForUpdate(int $id): ?FactoryDevice { $s=$this->pdo->prepare('SELECT * FROM factory_devices WHERE id=:id LIMIT 1 FOR UPDATE'); $s->execute([':id'=>$id]); $r=$s->fetch(PDO::FETCH_ASSOC); return $r===false?null:$this->hydrate($r); }
    private function hydrate(array $r): FactoryDevice { return new FactoryDevice((int)$r['id'],(string)$r['chip_id'],(string)$r['status'],(string)$r['first_announced_at'],(string)$r['last_announced_at'],$r['claimed_at']===null?null:(string)$r['claimed_at'],$r['claimed_by']===null?null:(string)$r['claimed_by'],(string)$r['created_at'],(string)$r['updated_at'],isset($r['device_id'])&&$r['device_id']!==null?(int)$r['device_id']:null); }
}
