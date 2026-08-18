<?php declare(strict_types=1);

namespace App\Domain\Crm;

use App\Support\Errors\ApiException;

final class CrmUserService
{
    private CrmUserRepository $userRepo;
    private CrmSessionRepository $sessionRepo;

    public function __construct(CrmUserRepository $userRepo, CrmSessionRepository $sessionRepo)
    {
        $this->userRepo    = $userRepo;
        $this->sessionRepo = $sessionRepo;
    }

    /**
     * Authenticates a user and returns a session token.
     * @throws ApiException on invalid credentials or inactive account.
     */
    public function login(string $username, string $password, string $ipAddress): array
    {
        $user = $this->userRepo->findByUsername($username);
        if (!$user || !password_verify($password, $user->passwordHash)) {
            throw new ApiException(401, 'auth_invalid_credentials', 'Usuario o contraseña incorrectos');
        }
        if (!$user->active) {
            throw new ApiException(403, 'auth_account_disabled', 'Cuenta deshabilitada');
        }

        $token = $this->sessionRepo->create($user->id, $ipAddress);
        $this->userRepo->recordLogin($user->id);

        return [
            'token'           => $token,
            'expires_in'      => CrmSessionRepository::DEFAULT_TTL_SECONDS,
            'user'            => $user->toArray(),
        ];
    }

    /**
     * Destroys a session.
     */
    public function logout(string $token): void
    {
        $this->sessionRepo->destroy($token);
    }

    /**
     * Validates a session token and returns the user.
     * @throws ApiException if token invalid or expired.
     */
    public function validateSession(string $token): CrmUser
    {
        $userId = $this->sessionRepo->validate($token);
        if ($userId === null) {
            throw new ApiException(401, 'auth_session_expired', 'Sesión expirada o inválida');
        }

        $user = $this->userRepo->findById($userId);
        if (!$user || !$user->active) {
            $this->sessionRepo->destroy($token);
            throw new ApiException(403, 'auth_account_disabled', 'Cuenta deshabilitada');
        }

        return $user;
    }

    // ---- CRUD (admin only) ----

    /** @return CrmUser[] */
    public function listAll(): array
    {
        return $this->userRepo->findAll();
    }

    public function getOrFail(int $id): CrmUser
    {
        $user = $this->userRepo->findById($id);
        if (!$user) {
            throw new ApiException(404, 'not_found', 'Usuario no encontrado');
        }
        return $user;
    }

    public function create(string $username, string $password, string $role, ?string $displayName): CrmUser
    {
        $username = trim($username);
        if (mb_strlen($username) < 3 || mb_strlen($username) > 64) {
            throw new ApiException(400, 'bad_request', 'Username debe tener entre 3 y 64 caracteres');
        }
        if (mb_strlen($password) < 6) {
            throw new ApiException(400, 'bad_request', 'Contraseña debe tener al menos 6 caracteres');
        }
        if (!CrmUser::isValidRole($role)) {
            throw new ApiException(400, 'bad_request', 'Rol inválido: ' . $role);
        }

        $existing = $this->userRepo->findByUsername($username);
        if ($existing) {
            throw new ApiException(409, 'conflict', 'Ya existe un usuario con ese nombre');
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $id = $this->userRepo->insert($username, $passwordHash, $role, $displayName);
        return $this->userRepo->findById($id);
    }

    public function update(int $id, array $fields): CrmUser
    {
        $user = $this->getOrFail($id);
        $updates = [];

        if (array_key_exists('username', $fields)) {
            $newUsername = trim((string) $fields['username']);
            if (mb_strlen($newUsername) < 3 || mb_strlen($newUsername) > 64) {
                throw new ApiException(400, 'bad_request', 'Username debe tener entre 3 y 64 caracteres');
            }
            $existing = $this->userRepo->findByUsername($newUsername);
            if ($existing && $existing->id !== $id) {
                throw new ApiException(409, 'conflict', 'Ya existe un usuario con ese nombre');
            }
            $updates['username'] = $newUsername;
        }

        if (array_key_exists('password', $fields)) {
            $newPass = (string) $fields['password'];
            if (mb_strlen($newPass) < 6) {
                throw new ApiException(400, 'bad_request', 'Contraseña debe tener al menos 6 caracteres');
            }
            $updates['password_hash'] = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 10]);
        }

        if (array_key_exists('role', $fields)) {
            $role = (string) $fields['role'];
            if (!CrmUser::isValidRole($role)) {
                throw new ApiException(400, 'bad_request', 'Rol inválido: ' . $role);
            }
            $updates['role'] = $role;
        }

        if (array_key_exists('display_name', $fields)) {
            $updates['display_name'] = $fields['display_name'] !== null ? (string) $fields['display_name'] : null;
        }

        if (array_key_exists('active', $fields)) {
            $updates['active'] = (bool) $fields['active'] ? 1 : 0;
        }

        $this->userRepo->update($id, $updates);
        return $this->userRepo->findById($id);
    }

    public function delete(int $id): void
    {
        $this->getOrFail($id); // throws if not found
        $this->userRepo->delete($id);
    }
}
