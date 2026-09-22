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
     *
     * @deprecated Fase 51 (Bug 4): kept for backward compatibility/auditing.
     *   New guest-QR flows must use markFirstUse() so the usage window
     *   (valid_until) is recorded atomically with the first use.
     */
    public function markConsumed(string $jti): bool;

    /**
     * Fase 51 (Bug 4): atomically claim the FIRST use of a QR.
     *
     * Sets `first_used_at = consumed_at = UTC now` and `valid_until = $validUntilUtc`
     * if and only if `consumed_at IS NULL` (and not revoked). Only one concurrent
     * caller wins (rowCount === 1); the losers keep the winner's window.
     *
     * Returns true for the winning caller, false otherwise.
     */
    public function markFirstUse(string $jti, string $validUntilUtc): bool;

    /**
     * Mark revoked_at = UTC now if and only if currently NULL.
     * Returns true on success, false otherwise.
     */
    public function markRevoked(string $jti): bool;
}
