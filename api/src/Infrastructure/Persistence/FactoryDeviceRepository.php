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

    public function findClaimedById(int $id): ?FactoryDevice
    {
        return $this->fetchClaimFromAudit('entity_id = :id', [':id'=>(string)$id]);
    }

    public function announce(string $chipId, string $enrollmentHash): array
    {
        $this->pdo->beginTransaction();
        try {
            $existing = $this->fetchForUpdateByChip($chipId);
            if ($existing !== null) {
                $hashStmt = $this->pdo->prepare('SELECT enrollment_key_hash FROM factory_devices WHERE id=:id LIMIT 1');
                $hashStmt->execute([':id' => $existing->id]);
                $storedHash = $hashStmt->fetchColumn();
                if (!is_string($storedHash) || $storedHash === '') {
                    throw new \App\Support\Errors\ForbiddenException('legacy_credential_migration_required', 'Factory credential migration required');
                }
                if (!hash_equals($storedHash, $enrollmentHash)) {
                    throw new \App\Support\Errors\ForbiddenException('invalid_factory_credential', 'Factory credential rejected');
                }
                $touch = $this->pdo->prepare('UPDATE factory_devices SET last_announced_at=CURRENT_TIMESTAMP(3), updated_at=CURRENT_TIMESTAMP(3) WHERE id=:id');
                $touch->execute([':id' => $existing->id]);
                $device = $this->fetchForUpdate($existing->id);
                $this->pdo->commit();
                return [$device, false];
            }
            $claimed = $this->fetchClaimedByChip($chipId, $enrollmentHash);
            if ($claimed !== null) {
                $this->pdo->commit();
                return [$claimed, false];
            }
            $stmt = $this->pdo->prepare("INSERT INTO factory_devices (chip_id,enrollment_key_hash,status,first_announced_at,last_announced_at)
                VALUES (:chip,:hash,'PENDING',CURRENT_TIMESTAMP(3),CURRENT_TIMESTAMP(3))
                ON DUPLICATE KEY UPDATE last_announced_at=CURRENT_TIMESTAMP(3), updated_at=CURRENT_TIMESTAMP(3)");
            $stmt->execute([':chip'=>$chipId, ':hash'=>$enrollmentHash]);
            $device = $this->findByChipId($chipId);
            $created = $stmt->rowCount() === 1;
            $this->pdo->commit(); return [$device, $created];
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    public function claimAndAudit(int $id, string $actor, ?int $actorClientId = null, ?string $label = null, ?int $packId = null): ?FactoryDevice
    {
        $this->pdo->beginTransaction();
        try {
            $device = $this->fetchForUpdate($id);
            if ($device === null) { $this->pdo->commit(); return null; }
            $hashStmt = $this->pdo->prepare('SELECT enrollment_key_hash FROM factory_devices WHERE id=:id LIMIT 1 FOR UPDATE');
            $hashStmt->execute([':id' => $id]);
            $storedHash = $hashStmt->fetchColumn();
            if (!is_string($storedHash) || $storedHash === '') {
                $this->pdo->rollBack();
                throw new \App\Support\Errors\ConflictException('enrollment_required', 'Factory device has no enrollment record');
            }
            if ($device->status === FactoryDevice::STATUS_PENDING) {
                $before = $device->status;
                $findRpi = $this->pdo->prepare('SELECT id FROM devices WHERE kind = \'RPI\' AND external_id = :chip LIMIT 1 FOR UPDATE');
                $findRpi->execute([':chip' => $device->chipId]);
                $deviceRow = $findRpi->fetch(PDO::FETCH_ASSOC);
                if ($deviceRow === false) {
                    $clientStmt = $this->pdo->prepare("INSERT INTO api_clients (code,kind,api_key_hash,scopes_csv,active,device_id) VALUES (:code,'RPI',:hash,'qr:validate,rooms:read,presence:write',1,NULL)");
                    $clientStmt->execute([':code' => 'RPI-'.$device->chipId, ':hash' => $storedHash]);
                    $clientId = (int) $this->pdo->lastInsertId();
                    $createRpi = $this->pdo->prepare("INSERT INTO devices (room_id, pack_id, kind, external_id, label, api_client_id, meta_json) VALUES (NULL, :pack, 'RPI', :chip, :label, :client, :meta)");
                    $createRpi->execute([':chip' => $device->chipId, ':pack' => $packId, ':label' => $label ?? ('RPI '.$device->chipId), ':client' => $clientId, ':meta' => json_encode(['source' => 'factory_claim'], JSON_UNESCAPED_UNICODE)]);
                    $deviceId = (int) $this->pdo->lastInsertId();
                    $this->pdo->prepare('UPDATE api_clients SET device_id=:device WHERE id=:client')->execute([':device'=>$deviceId, ':client'=>$clientId]);
                } else {
                    $deviceId = (int) $deviceRow['id'];
                    $existingClient = $this->pdo->prepare('SELECT c.id, c.api_key_hash FROM api_clients c JOIN devices d ON d.api_client_id = c.id WHERE d.id=:device LIMIT 1 FOR UPDATE');
                    $existingClient->execute([':device' => $deviceId]);
                    $existingClientRow = $existingClient->fetch(PDO::FETCH_ASSOC);
                    if ($existingClientRow !== false && !hash_equals((string) $existingClientRow['api_key_hash'], $storedHash)) {
                        throw new \App\Support\Errors\ConflictException('device_client_conflict', 'RPI already has a different API client');
                    }
                    $clientId = $existingClientRow === false ? false : $existingClientRow['id'];
                    if ($clientId === false) {
                        $sameCode = $this->pdo->prepare('SELECT id FROM api_clients WHERE code=:code LIMIT 1 FOR UPDATE');
                        $sameCode->execute([':code' => 'RPI-'.$device->chipId]);
                        if ($sameCode->fetchColumn() !== false) {
                            throw new \App\Support\Errors\ConflictException('device_client_conflict', 'RPI API client code is already in use');
                        }
                        $createClient = $this->pdo->prepare("INSERT INTO api_clients (code,kind,api_key_hash,scopes_csv,active,device_id) VALUES (:code,'RPI',:hash,'qr:validate,rooms:read,presence:write',1,:device)");
                        $createClient->execute([':code' => 'RPI-'.$device->chipId, ':hash' => $storedHash, ':device' => $deviceId]);
                        $clientId = (int) $this->pdo->lastInsertId();
                    }
                    $this->pdo->prepare('UPDATE devices SET api_client_id=:client, pack_id=COALESCE(:pack, pack_id), label=COALESCE(:label, label) WHERE id=:device')->execute([':client'=>(int)$clientId, ':pack'=>$packId, ':label'=>$label, ':device'=>$deviceId]);
                }
                $stmt = $this->pdo->prepare("UPDATE factory_devices SET status='CLAIMED', claimed_at=CURRENT_TIMESTAMP(3), claimed_by=:actor, device_id=:device_id, updated_at=CURRENT_TIMESTAMP(3) WHERE id=:id");
                $stmt->execute([':id'=>$id, ':actor'=>$actor, ':device_id'=>$deviceId]);
                $device = $this->fetchForUpdate($id);
                $audit = $this->pdo->prepare('INSERT INTO audit_log (actor_client_id,scope,action,entity,entity_id,correlation_id,payload_json) VALUES (:client,\'factory:claim\',\'claim\',\'factory_device\',:id,:corr,:payload)');
                $audit->execute([':client'=>$actorClientId, ':id'=>(string)$id, ':corr'=>str_pad((string)$id,26,'0',STR_PAD_LEFT), ':payload'=>json_encode(['chip_id'=>$device->chipId,'device_id'=>$deviceId,'status_before'=>$before,'status_after'=>'CLAIMED','actor'=>$actor])]);
                $this->pdo->prepare('DELETE FROM factory_devices WHERE id=:id')->execute([':id'=>$id]);
            }
            $this->pdo->commit(); return $device;
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
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
    private function fetchForUpdateByChip(string $chipId): ?FactoryDevice { $s=$this->pdo->prepare('SELECT * FROM factory_devices WHERE chip_id=:chip LIMIT 1 FOR UPDATE'); $s->execute([':chip'=>$chipId]); $r=$s->fetch(PDO::FETCH_ASSOC); return $r===false?null:$this->hydrate($r); }
    private function fetchClaimedByChip(string $chipId, string $enrollmentHash): ?FactoryDevice
    {
        $s=$this->pdo->prepare("SELECT d.id AS device_id, d.external_id, c.api_key_hash FROM devices d JOIN api_clients c ON c.id=d.api_client_id WHERE d.kind='RPI' AND d.external_id=:chip LIMIT 1");
        $s->execute([':chip'=>$chipId]); $r=$s->fetch(PDO::FETCH_ASSOC);
        if ($r===false) return null;
        if (!hash_equals((string)$r['api_key_hash'], $enrollmentHash)) throw new \App\Support\Errors\ForbiddenException('invalid_factory_credential', 'Factory credential rejected');
        $claimed = $this->fetchClaimFromAudit('JSON_UNQUOTE(JSON_EXTRACT(payload_json, \'$.chip_id\')) = :chip', [':chip'=>$chipId], (int)$r['device_id']);
        if ($claimed !== null) return $claimed;
        $now = date('Y-m-d H:i:s.v');
        return new FactoryDevice(0, $chipId, FactoryDevice::STATUS_CLAIMED, $now, $now, $now, null, $now, $now, (int)$r['device_id']);
    }
    private function fetchClaimFromAudit(string $where, array $params, ?int $deviceId=null): ?FactoryDevice
    {
        $s=$this->pdo->prepare("SELECT payload_json, occurred_at FROM audit_log WHERE entity='factory_device' AND action='claim' AND {$where} ORDER BY occurred_at DESC, id DESC LIMIT 1");
        $s->execute($params); $r=$s->fetch(PDO::FETCH_ASSOC); if ($r===false) return null;
        $p=json_decode((string)$r['payload_json'], true); if (!is_array($p) || !isset($p['chip_id'])) return null;
        $at=(string)$r['occurred_at']; $resolvedDeviceId=$deviceId ?? (isset($p['device_id'])?(int)$p['device_id']:null);
        return new FactoryDevice(0,(string)$p['chip_id'],FactoryDevice::STATUS_CLAIMED,$at,$at,$at,isset($p['actor'])?(string)$p['actor']:null,$at,$at,$resolvedDeviceId);
    }
    private function hydrate(array $r): FactoryDevice { return new FactoryDevice((int)$r['id'],(string)$r['chip_id'],(string)$r['status'],(string)$r['first_announced_at'],(string)$r['last_announced_at'],$r['claimed_at']===null?null:(string)$r['claimed_at'],$r['claimed_by']===null?null:(string)$r['claimed_by'],(string)$r['created_at'],(string)$r['updated_at'],isset($r['device_id'])&&$r['device_id']!==null?(int)$r['device_id']:null); }
}
