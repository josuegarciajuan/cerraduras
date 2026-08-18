<?php
declare(strict_types=1);

namespace App\Domain\Qr;

/**
 * QrCredential: a row from qr_credentials.
 *
 * The original token is NOT carried in this object: only its SHA-256 hash
 * and the public jti are persisted. The plaintext token is returned to the
 * caller exactly once at issuance time and from that point on it lives only
 * on the printed ticket.
 */
final class QrCredential
{
    public int $id;
    public int $stayId;
    public int $roomId;
    public string $jti;
    public string $tokenHash;
    public string $issuedAt;
    public string $expiresAt;
    public ?string $consumedAt;
    public ?string $revokedAt;

    public function __construct(
        int $id,
        int $stayId,
        int $roomId,
        string $jti,
        string $tokenHash,
        string $issuedAt,
        string $expiresAt,
        ?string $consumedAt,
        ?string $revokedAt
    ) {
        $this->id = $id;
        $this->stayId = $stayId;
        $this->roomId = $roomId;
        $this->jti = $jti;
        $this->tokenHash = $tokenHash;
        $this->issuedAt = $issuedAt;
        $this->expiresAt = $expiresAt;
        $this->consumedAt = $consumedAt;
        $this->revokedAt = $revokedAt;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }
}
