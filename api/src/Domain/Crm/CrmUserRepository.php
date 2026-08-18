<?php declare(strict_types=1);

namespace App\Domain\Crm;

use PDO;

final class CrmUserRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findById(int $id): ?CrmUser
    {
        $stmt = $this->pdo->prepare('SELECT * FROM crm_users WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new CrmUser($row) : null;
    }

    public function findByUsername(string $username): ?CrmUser
    {
        $stmt = $this->pdo->prepare('SELECT * FROM crm_users WHERE username = :username');
        $stmt->bindValue(':username', $username, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new CrmUser($row) : null;
    }

    /** @return CrmUser[] */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM crm_users ORDER BY username ASC');
        return array_map(fn(array $row) => new CrmUser($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function insert(string $username, string $passwordHash, string $role, ?string $displayName): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO crm_users (username, password_hash, role, display_name, created_at, updated_at)
             VALUES (:username, :phash, :role, :dname, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
        );
        $stmt->bindValue(':username', $username);
        $stmt->bindValue(':phash', $passwordHash);
        $stmt->bindValue(':role', $role);
        $stmt->bindValue(':dname', $displayName);
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        if (empty($fields)) return;

        $allowed = ['username', 'password_hash', 'role', 'display_name', 'active'];
        $setClauses = [];
        $params = [':id' => $id];

        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $setClauses[] = "`{$k}` = :{$k}";
            $params[":{$k}"] = $v;
        }
        if (empty($setClauses)) return;

        $sql = 'UPDATE crm_users SET ' . implode(', ', $setClauses) . ', updated_at = UTC_TIMESTAMP(3) WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function recordLogin(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE crm_users SET last_login_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3) WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM crm_users WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }
}
