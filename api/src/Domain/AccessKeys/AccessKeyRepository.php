<?php
declare(strict_types=1);

namespace App\Domain\AccessKeys;

use PDO;

/**
 * AccessKeyRepository: PDO persistence for access keys and their audit trail.
 *
 * F96. A key belongs to exactly one owner: a worker or a role. Worker keys are
 * personal; role keys are shared and anonymous by design.
 */
final class AccessKeyRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare($this->baseSelect() . ' WHERE k.id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row, true);
    }

    /** @return array<string,mixed>|null */
    public function findByJti(string $jti): ?array
    {
        $stmt = $this->pdo->prepare($this->baseSelect() . ' WHERE k.token_jti = :jti LIMIT 1');
        $stmt->execute([':jti' => $jti]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row, true);
    }

    /** @return array<string,mixed>|null */
    public function findByTokenHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare($this->baseSelect() . ' WHERE k.token_hash = :h LIMIT 1');
        $stmt->execute([':h' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row, true);
    }

    /** @return array<string,mixed>|null */
    public function findByCardId(int $cardId): ?array
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . " WHERE k.nfc_card_id = :cid AND k.status = 'ACTIVE'
             ORDER BY k.id DESC LIMIT 1"
        );
        $stmt->execute([':cid' => $cardId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row, true);
    }

    /**
     * @param array{status?:string, owner_type?:string, worker_id?:int, role_id?:int,
     *              key_type?:string, q?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function findAll(array $filters = []): array
    {
        $wheres = [];
        $params = [];

        if (!empty($filters['owner_type'])) {
            $wheres[] = 'k.owner_type = :ot';
            $params[':ot'] = (string) $filters['owner_type'];
        }
        if (!empty($filters['worker_id'])) {
            $wheres[] = 'k.worker_id = :wid';
            $params[':wid'] = (int) $filters['worker_id'];
        }
        if (!empty($filters['role_id'])) {
            $wheres[] = 'k.role_id = :rid';
            $params[':rid'] = (int) $filters['role_id'];
        }
        if (!empty($filters['key_type'])) {
            $wheres[] = 'k.key_type = :kt';
            $params[':kt'] = (string) $filters['key_type'];
        }
        if (!empty($filters['q'])) {
            $wheres[] = '(k.label LIKE :q1 OR w.name LIKE :q2 OR r.name LIKE :q3 OR c.number LIKE :q4)';
            $qv = '%' . (string) $filters['q'] . '%';
            $params[':q1'] = $qv; $params[':q2'] = $qv; $params[':q3'] = $qv; $params[':q4'] = $qv;
        }
        if (!empty($filters['status'])) {
            $st = (string) $filters['status'];
            if ($st === 'ACTIVE') {
                $wheres[] = "k.status = 'ACTIVE' AND k.valid_until >= UTC_TIMESTAMP(3)";
            } elseif ($st === 'EXPIRED') {
                $wheres[] = "k.status = 'ACTIVE' AND k.valid_until < UTC_TIMESTAMP(3)";
            } elseif ($st === 'REVOKED') {
                $wheres[] = "k.status = 'REVOKED'";
            }
        }

        $sql = $this->baseSelect();
        if ($wheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }
        $sql .= ' ORDER BY k.created_at DESC, k.id DESC LIMIT 500';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(
            fn(array $row): array => $this->hydrate($row, true),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    public function insert(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO access_keys
                (label, owner_type, worker_id, role_id, key_type, token_hash, token_jti,
                 all_room_types, nfc_card_id, valid_until, status, notes)
             VALUES
                (:label, :owner_type, :worker_id, :role_id, :key_type, :token_hash, :token_jti,
                 :all_rt, :card_id, :valid_until, \'ACTIVE\', :notes)'
        );
        $stmt->execute([
            ':label'       => $data['label'],
            ':owner_type'  => $data['owner_type'],
            ':worker_id'   => $data['worker_id'],
            ':role_id'     => $data['role_id'],
            ':key_type'    => $data['key_type'],
            ':token_hash'  => $data['token_hash'],
            ':token_jti'   => $data['token_jti'],
            ':all_rt'      => !empty($data['all_room_types']) ? 1 : 0,
            ':card_id'     => $data['nfc_card_id'],
            ':valid_until' => $data['valid_until'],
            ':notes'       => $data['notes'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): int
    {
        $allowed = [
            'label'          => ':label',
            'all_room_types' => ':all_rt',
            'nfc_card_id'    => ':card_id',
            'valid_until'    => ':valid_until',
            'status'         => ':status',
            'revoked_at'     => ':revoked_at',
            'notes'          => ':notes',
        ];
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $col => $value) {
            if (!isset($allowed[$col])) {
                continue;
            }
            $sets[] = "`{$col}` = {$allowed[$col]}";
            $params[$allowed[$col]] = $col === 'all_room_types' ? (!empty($value) ? 1 : 0) : $value;
        }
        if ($sets === []) {
            return 0;
        }
        $stmt = $this->pdo->prepare('UPDATE access_keys SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** @param list<int> $roomTypeIds */
    public function setRoomTypes(int $keyId, array $roomTypeIds): void
    {
        $this->pdo->prepare('DELETE FROM access_key_room_types WHERE key_id = :kid')
            ->execute([':kid' => $keyId]);

        if ($roomTypeIds === []) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO access_key_room_types (key_id, room_type_id)
             VALUES (:kid, :rt)'
        );
        foreach ($roomTypeIds as $rt) {
            $stmt->execute([':kid' => $keyId, ':rt' => (int) $rt]);
        }
    }

    /** @return list<int> */
    public function getRoomTypes(int $keyId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT room_type_id FROM access_key_room_types WHERE key_id = :kid ORDER BY room_type_id'
        );
        $stmt->execute([':kid' => $keyId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return list<int> */
    public function getRoleRoomTypes(int $roleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT room_type_id FROM worker_role_room_types WHERE role_id = :rid ORDER BY room_type_id'
        );
        $stmt->execute([':rid' => $roleId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function canAccessRoomType(array $key, int $roomTypeId): bool
    {
        if (!empty($key['all_room_types'])) {
            return true;
        }
        $roomTypes = $key['room_type_ids'] ?? $this->getRoomTypes((int) $key['id']);
        return in_array($roomTypeId, array_map('intval', $roomTypes), true);
    }

    public function revoke(int $id): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE access_keys
                SET status = 'REVOKED', revoked_at = UTC_TIMESTAMP(3)
              WHERE id = :id AND status <> 'REVOKED'"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount();
    }

    /**
     * @param array{key_id?:int, worker_id?:int, role_id?:int, room_id?:int,
     *              result?:string, from?:string, to?:string, limit?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function findAccessEvents(array $filters = []): array
    {
        $wheres = [];
        $params = [];
        if (!empty($filters['key_id'])) {
            $wheres[] = 'e.key_id = :kid';
            $params[':kid'] = (int) $filters['key_id'];
        }
        if (!empty($filters['worker_id'])) {
            $wheres[] = 'e.worker_id = :wid';
            $params[':wid'] = (int) $filters['worker_id'];
        }
        if (!empty($filters['role_id'])) {
            $wheres[] = 'e.role_id = :rid';
            $params[':rid'] = (int) $filters['role_id'];
        }
        if (!empty($filters['room_id'])) {
            $wheres[] = 'e.room_id = :room';
            $params[':room'] = (int) $filters['room_id'];
        }
        if (!empty($filters['result'])) {
            $wheres[] = 'e.result = :res';
            $params[':res'] = (string) $filters['result'];
        }
        if (!empty($filters['from'])) {
            $wheres[] = 'e.occurred_at >= :from';
            $params[':from'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $wheres[] = 'e.occurred_at < :to';
            $params[':to'] = (string) $filters['to'];
        }

        $limit = min((int) ($filters['limit'] ?? 200), 500);
        $sql = 'SELECT e.*, k.label AS key_label, k.key_type,
                       w.name AS worker_name, r.name AS role_name,
                       ro.code AS room_code, rt.name AS room_type_name
                FROM key_access_events e
                LEFT JOIN access_keys k ON k.id = e.key_id
                LEFT JOIN workers w ON w.id = e.worker_id
                LEFT JOIN worker_roles r ON r.id = e.role_id
                LEFT JOIN rooms ro ON ro.id = e.room_id
                LEFT JOIN room_types rt ON rt.id = e.room_type_id';
        if ($wheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }
        $sql .= ' ORDER BY e.occurred_at DESC, e.id DESC LIMIT :lim';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['key_id'] = $r['key_id'] !== null ? (int) $r['key_id'] : null;
            $r['worker_id'] = $r['worker_id'] !== null ? (int) $r['worker_id'] : null;
            $r['role_id'] = $r['role_id'] !== null ? (int) $r['role_id'] : null;
            $r['room_id'] = $r['room_id'] !== null ? (int) $r['room_id'] : null;
            $r['room_type_id'] = $r['room_type_id'] !== null ? (int) $r['room_type_id'] : null;
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function insertAccessEvent(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO key_access_events
                (key_id, owner_type, worker_id, role_id, room_id, room_type_id, device_id,
                 credential_type, result, reason, provider, correlation_id, worker_session_id)
             VALUES
                (:key_id, :owner_type, :worker_id, :role_id, :room_id, :room_type_id, :device_id,
                 :credential_type, :result, :reason, :provider, :correlation_id, :wsid)'
        );
        $stmt->execute([
            ':key_id'          => $data['key_id'] ?? null,
            ':owner_type'      => $data['owner_type'] ?? null,
            ':worker_id'       => $data['worker_id'] ?? null,
            ':role_id'         => $data['role_id'] ?? null,
            ':room_id'         => $data['room_id'] ?? null,
            ':room_type_id'    => $data['room_type_id'] ?? null,
            ':device_id'       => $data['device_id'] ?? null,
            ':credential_type' => $data['credential_type'],
            ':result'          => $data['result'],
            ':reason'          => $data['reason'] ?? null,
            ':provider'        => $data['provider'] ?? null,
            ':correlation_id'  => $data['correlation_id'] ?? null,
            ':wsid'            => $data['worker_session_id'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function baseSelect(): string
    {
        return 'SELECT k.*, w.name AS worker_name, w.active AS worker_active,
                       r.name AS role_name, c.number AS nfc_card_number
                FROM access_keys k
                LEFT JOIN workers w ON w.id = k.worker_id
                LEFT JOIN worker_roles r ON r.id = k.role_id
                LEFT JOIN nfc_cards c ON c.id = k.nfc_card_id';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row, bool $withRoomTypes): array
    {
        $id = (int) $row['id'];
        $status = (string) $row['status'];
        $validUntil = (string) $row['valid_until'];
        $effective = $status;
        if ($status === 'ACTIVE') {
            $ts = strtotime($validUntil . ' UTC');
            $effective = ($ts !== false && $ts < time()) ? 'EXPIRED' : 'ACTIVE';
        }

        $out = [
            'id'             => $id,
            'label'          => (string) $row['label'],
            'owner_type'     => (string) $row['owner_type'],
            'worker_id'      => $row['worker_id'] !== null ? (int) $row['worker_id'] : null,
            'role_id'        => $row['role_id'] !== null ? (int) $row['role_id'] : null,
            'key_type'       => (string) $row['key_type'],
            'token_hash'     => $row['token_hash'] !== null ? (string) $row['token_hash'] : null,
            'token_jti'      => $row['token_jti'] !== null ? (string) $row['token_jti'] : null,
            'all_room_types' => (bool) $row['all_room_types'],
            'nfc_card_id'    => $row['nfc_card_id'] !== null ? (int) $row['nfc_card_id'] : null,
            'nfc_card_number' => $row['nfc_card_number'] !== null ? (string) $row['nfc_card_number'] : null,
            'valid_until'    => $validUntil,
            'status'         => $status,
            'effective_status' => $effective,
            'revoked_at'     => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
            'notes'          => $row['notes'] !== null ? (string) $row['notes'] : null,
            'worker_name'    => $row['worker_name'] !== null ? (string) $row['worker_name'] : null,
            'role_name'      => $row['role_name'] !== null ? (string) $row['role_name'] : null,
            'worker_active'  => $row['worker_active'] !== null ? (bool) $row['worker_active'] : null,
            'created_at'     => (string) $row['created_at'],
            'updated_at'     => (string) $row['updated_at'],
        ];
        if ($withRoomTypes) {
            $out['room_type_ids'] = $this->getRoomTypes($id);
        }
        return $out;
    }
}
