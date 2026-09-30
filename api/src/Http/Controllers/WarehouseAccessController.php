<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Workers\WarehouseAccessPolicy;
use App\Domain\Workers\WarehouseAccessPolicyInterface;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\BadRequestException;
use App\Support\Errors\NotFoundException;
use App\Support\Errors\UnprocessableException;
use App\Support\Json\JsonBody;
use PDO;

/**
 * WarehouseAccessController: public LAN endpoints for the drinks warehouse
 * access permissions (F62/RF-69.5).
 *
 * Routes (no auth — LAN/MVP, same policy as the rest of /almacen-api):
 *   GET /almacen-api/access?room_type_id=N
 *   PUT /almacen-api/access/role/{id}    { room_type_id, allow }
 *   PUT /almacen-api/access/worker/{id}  { room_type_id, effect: ALLOW|DENY|null }
 */
final class WarehouseAccessController
{
    private PDO $pdo;
    private WarehouseAccessPolicyInterface $policy;

    public function __construct(PDO $pdo, WarehouseAccessPolicyInterface $policy)
    {
        $this->pdo = $pdo;
        $this->policy = $policy;
    }

    public function index(Request $request): Response
    {
        $roomTypeId = (int) ($request->query['room_type_id'] ?? 0);
        if ($roomTypeId <= 0) {
            throw new BadRequestException('room_type_id required', ['field' => 'room_type_id']);
        }
        $rt = $this->requireRoomType($roomTypeId);

        $roles = [];
        $roleAllowed = [];
        foreach ($this->pdo->query('SELECT id, name FROM worker_roles ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $rid = (int) $r['id'];
            $allowed = $this->roleAllows($rid, $roomTypeId);
            $roleAllowed[$rid] = $allowed;
            $roles[] = ['id' => $rid, 'name' => (string) $r['name'], 'allowed' => $allowed];
        }

        $workers = [];
        $stmt = $this->pdo->query(
            'SELECT id, name, role_id FROM workers WHERE active = 1 ORDER BY name ASC'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $w) {
            $wid = (int) $w['id'];
            $roleId = (int) $w['role_id'];
            $override = $this->overrideEffect($wid, $roomTypeId);
            $workers[] = [
                'id' => $wid,
                'name' => (string) $w['name'],
                'role_id' => $roleId,
                'role_allowed' => $roleAllowed[$roleId] ?? $this->roleAllows($roleId, $roomTypeId),
                'override' => $override,
                'effective' => WarehouseAccessPolicy::resolve($override, $roleAllowed[$roleId] ?? $this->roleAllows($roleId, $roomTypeId)),
            ];
        }

        return Response::json(200, [
            'room_type' => $rt,
            'roles' => $roles,
            'workers' => $workers,
        ]);
    }

    public function setRole(Request $request): Response
    {
        $roleId = (int) $request->routeParam('id');
        $body = JsonBody::require($request->jsonBody);
        $roomTypeId = JsonBody::int($body, 'room_type_id');
        if (!array_key_exists('allow', $body) || !is_bool($body['allow'])) {
            throw new UnprocessableException('validation_error', 'allow (boolean) required', ['field' => 'allow']);
        }
        $allow = (bool) $body['allow'];
        $this->requireRole($roleId);
        $this->requireRoomType($roomTypeId);

        if ($allow) {
            $this->pdo->prepare(
                'INSERT IGNORE INTO worker_role_room_types (role_id, room_type_id) VALUES (:r, :rt)'
            )->execute([':r' => $roleId, ':rt' => $roomTypeId]);
        } else {
            $this->pdo->prepare(
                'DELETE FROM worker_role_room_types WHERE role_id = :r AND room_type_id = :rt'
            )->execute([':r' => $roleId, ':rt' => $roomTypeId]);
        }

        return Response::json(200, [
            'role' => ['id' => $roleId, 'allowed' => $this->roleAllows($roleId, $roomTypeId)],
        ]);
    }

    public function setWorker(Request $request): Response
    {
        $workerId = (int) $request->routeParam('id');
        $body = JsonBody::require($request->jsonBody);
        $roomTypeId = JsonBody::int($body, 'room_type_id');
        $this->requireWorker($workerId);
        $this->requireRoomType($roomTypeId);

        if (!array_key_exists('effect', $body) || $body['effect'] === null) {
            $effect = null;
        } elseif (is_string($body['effect']) && in_array(strtoupper($body['effect']), WarehouseAccessPolicy::validEffects(), true)) {
            $effect = strtoupper($body['effect']);
        } else {
            throw new UnprocessableException(
                'validation_error',
                'effect must be ALLOW, DENY or null',
                ['field' => 'effect', 'allowed' => array_merge(WarehouseAccessPolicy::validEffects(), [null])]
            );
        }

        if ($effect === null) {
            $this->pdo->prepare(
                'DELETE FROM worker_room_overrides WHERE worker_id = :w AND room_type_id = :rt'
            )->execute([':w' => $workerId, ':rt' => $roomTypeId]);
        } else {
            $this->pdo->prepare(
                'INSERT INTO worker_room_overrides (worker_id, room_type_id, effect)
                 VALUES (:w, :rt, :e)
                 ON DUPLICATE KEY UPDATE effect = VALUES(effect)'
            )->execute([':w' => $workerId, ':rt' => $roomTypeId, ':e' => $effect]);
        }

        return Response::json(200, [
            'worker' => ['id' => $workerId, 'override' => $this->overrideEffect($workerId, $roomTypeId)],
        ]);
    }

    // -- helpers ---------------------------------------------------------------

    private function roleAllows(int $roleId, int $roomTypeId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM worker_role_room_types WHERE role_id = :r AND room_type_id = :rt LIMIT 1'
        );
        $stmt->execute([':r' => $roleId, ':rt' => $roomTypeId]);
        return (bool) $stmt->fetchColumn();
    }

    private function overrideEffect(int $workerId, int $roomTypeId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT effect FROM worker_room_overrides WHERE worker_id = :w AND room_type_id = :rt LIMIT 1'
        );
        $stmt->execute([':w' => $workerId, ':rt' => $roomTypeId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /** @return array{id:int,code:string,name:string} */
    private function requireRoomType(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, code, name FROM room_types WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new NotFoundException('Room type not found', ['room_type_id' => $id]);
        }
        return ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name']];
    }

    private function requireRole(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM worker_roles WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetchColumn()) {
            throw new NotFoundException('Worker role not found', ['role_id' => $id]);
        }
    }

    private function requireWorker(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM workers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetchColumn()) {
            throw new NotFoundException('Worker not found', ['worker_id' => $id]);
        }
    }
}
