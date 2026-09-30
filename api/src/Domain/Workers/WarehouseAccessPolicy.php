<?php
declare(strict_types=1);

namespace App\Domain\Workers;

/**
 * WarehouseAccessPolicy: role grant + per-worker exception (F62/RF-69).
 *
 * Decision order (RF-69.3):
 *   1. If the worker has an explicit exception (ALLOW|DENY) it WINS.
 *   2. Otherwise the role grant (worker_role_room_types) decides.
 *
 * `resolve()` is pure and unit-testable; `canAccess()` adds the two DB lookups.
 * Without an exception the result is identical to the previous behaviour, so
 * the change is fully backwards compatible (RF-69.6).
 */
final class WarehouseAccessPolicy implements WarehouseAccessPolicyInterface
{
    public const EFFECT_ALLOW = 'ALLOW';
    public const EFFECT_DENY  = 'DENY';

    private WorkerRoomOverrideRepositoryInterface $overrides;
    private WorkerRoleRepositoryInterface $roles;

    public function __construct(
        WorkerRoomOverrideRepositoryInterface $overrides,
        WorkerRoleRepositoryInterface $roles
    ) {
        $this->overrides = $overrides;
        $this->roles = $roles;
    }

    public function canAccess(int $workerId, int $roleId, int $roomTypeId): bool
    {
        $effect = $this->overrides->getEffect($workerId, $roomTypeId);
        $roleAllows = $this->roles->canAccessRoomType($roleId, $roomTypeId);
        return self::resolve($effect, $roleAllows);
    }

    /**
     * Pure resolution rule.
     */
    public static function resolve(?string $overrideEffect, bool $roleAllows): bool
    {
        if ($overrideEffect === self::EFFECT_ALLOW) {
            return true;
        }
        if ($overrideEffect === self::EFFECT_DENY) {
            return false;
        }
        return $roleAllows;
    }

    /** @return list<string> */
    public static function validEffects(): array
    {
        return [self::EFFECT_ALLOW, self::EFFECT_DENY];
    }
}
