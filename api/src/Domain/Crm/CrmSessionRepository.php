<?php declare(strict_types=1);

namespace App\Domain\Crm;

use PDO;

final class CrmSessionRepository
{
    private PDO $pdo;

    /** Default session TTL: 8 hours */
    public const DEFAULT_TTL_SECONDS = 28800;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Creates a session and returns the token.
     */
    public function create(int $userId, string $ipAddress, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): string
    {
        $token = hash('sha256', random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s.v', time() + $ttlSeconds);

        $stmt = $this->pdo->prepare(
            'INSERT INTO crm_sessions (user_id, token, ip_address, expires_at, created_at)
             VALUES (:uid, :token, :ip, :exp, UTC_TIMESTAMP(3))'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':token', $token);
        $stmt->bindValue(':ip', $ipAddress);
        $stmt->bindValue(':exp', $expiresAt);
        $stmt->execute();

        return $token;
    }

    /**
     * Validates a session token and returns the associated user ID, or null.
     */
    public function validate(string $token): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM crm_sessions WHERE token = :token AND expires_at > UTC_TIMESTAMP(3)'
        );
        $stmt->bindValue(':token', $token);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['user_id'] : null;
    }

    /**
     * Destroys a session by token.
     */
    public function destroy(string $token): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM crm_sessions WHERE token = :token');
        $stmt->bindValue(':token', $token);
        $stmt->execute();
    }

    /**
     * Cleans up expired sessions.
     */
    public function cleanupExpired(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM crm_sessions WHERE expires_at <= UTC_TIMESTAMP(3)');
        $stmt->execute();
        return $stmt->rowCount();
    }
}
