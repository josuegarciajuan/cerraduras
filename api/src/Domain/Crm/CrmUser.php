<?php declare(strict_types=1);

namespace App\Domain\Crm;

final class CrmUser
{
    public int $id;
    public string $username;
    public string $passwordHash;
    public string $role;       // 'admin' | 'operator' | 'viewer'
    public ?string $displayName;
    public bool $active;
    public ?string $lastLoginAt;
    public string $createdAt;
    public string $updatedAt;

    /** @var string[] */
    private const ROLES = ['admin', 'operator', 'viewer'];

    public function __construct(array $props)
    {
        $this->id           = (int) ($props['id'] ?? 0);
        $this->username     = (string) ($props['username'] ?? '');
        $this->passwordHash = (string) ($props['password_hash'] ?? '');
        $this->role         = (string) ($props['role'] ?? 'operator');
        $this->displayName  = isset($props['display_name']) ? (string) $props['display_name'] : null;
        $this->active       = (bool) ($props['active'] ?? true);
        $this->lastLoginAt  = isset($props['last_login_at']) ? (string) $props['last_login_at'] : null;
        $this->createdAt    = (string) ($props['created_at'] ?? '');
        $this->updatedAt    = (string) ($props['updated_at'] ?? '');
    }

    public static function isValidRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    public static function validRoles(): array
    {
        return self::ROLES;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'username'     => $this->username,
            'role'         => $this->role,
            'display_name' => $this->displayName,
            'active'       => $this->active,
            'last_login_at' => $this->lastLoginAt,
            'created_at'   => $this->createdAt,
            'updated_at'   => $this->updatedAt,
        ];
    }
}
