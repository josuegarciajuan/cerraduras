<?php
declare(strict_types=1);

namespace App\Domain\Qr;

/**
 * QrCredentialRepositoryInterface: persistence for QR credentials.
 *
 * issued_at / expires_at are stored as DATETIME(3) UTC strings. The service
 * layer is responsible for converting clock-domain values into those.
 */
interface QrCredentialRepositoryInterface
{
    public function insert(
        int $stayId,
        int $roomId,
        string $jti,
        string $tokenHash,
        string $issuedAtUtc,
        string $expiresAtUtc
    ): int;

    public function findByJti(string $jti): ?QrCredential;

    public function findByTokenHash(string $tokenHash): ?QrCredential;

    /**
     * Mark consumed_at = UTC now if and only if currently NULL and not revoked.
     * Returns true on success, false otherwise.
     */
    public function markConsumed(string $jti): bool;

    /**
     * Mark revoked_at = UTC now if and only if currently NULL.
     * Returns true on success, false otherwise.
     */
    public function markRevoked(string $jti): bool;
}
