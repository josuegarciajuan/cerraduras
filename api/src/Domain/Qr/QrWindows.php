<?php
declare(strict_types=1);

namespace App\Domain\Qr;

/**
 * QrWindows: pure, side-effect-free logic for the guest QR two-phase lifetime
 * (Fase 51 / Bug 4).
 *
 * The guest QR now has TWO consecutive windows:
 *
 *   1. ARRIVAL window (phase 1) — from issuance until the FIRST scan.
 *      Governed by a single global `QR_ARRIVAL_WINDOW_MINUTES` (default 15),
 *      common to every room. The QR is valid (and multi-... no, single-use in
 *      this phase: it waits to be scanned once).
 *
 *   2. USAGE window (phase 2) — from the first scan until
 *      `first_used_at + stay.duracion_minutos`. During this window the QR is
 *      MULTI-USE (re-entry) for the same stay.
 *
 * Once either window is exhausted the credential is invalid (`qr_expired`).
 *
 * Everything here is epoch-based and deterministic so it can be unit-tested
 * without a database, clock or HTTP layer.
 */
final class QrWindows
{
    /** Never used and still inside the arrival window. */
    public const STATE_OK_UNUSED = 'ok_unused';

    /** Never used and the arrival deadline has passed → qr_expired{arrival}. */
    public const STATE_EXPIRED_ARRIVAL = 'expired_arrival';

    /** Already used and still inside the stay usage window → re-entry allowed. */
    public const STATE_OK_IN_USE = 'ok_in_use';

    /** Already used and the usage deadline has passed → qr_expired{usage}. */
    public const STATE_EXPIRED_USAGE = 'expired_usage';

    /** Epoch at which the un-scanned QR stops being valid. */
    public static function arrivalDeadline(int $issuedAtEpoch, int $arrivalMinutes): int
    {
        return $issuedAtEpoch + $arrivalMinutes * 60;
    }

    /** Epoch at which a first-used QR stops being valid. */
    public static function usageDeadline(int $firstUsedAtEpoch, int $duracionMinutes): int
    {
        return $firstUsedAtEpoch + $duracionMinutes * 60;
    }

    /**
     * Evaluate which phase/window the credential is in.
     *
     * @param int      $issuedAtEpoch   credential issued_at (epoch UTC)
     * @param int|null $firstUsedAtEpoch first_used_at (or consumed_at fallback); null if never used
     * @param int|null $validUntilEpoch  valid_until; when null the usage deadline is derived
     *                                   from first_used_at + duracion_minutos
     * @param int      $duracionMinutes  stay duration
     * @param int      $arrivalMinutes   global arrival window
     * @param int      $nowEpoch        current epoch UTC
     */
    public static function evaluate(
        int $issuedAtEpoch,
        ?int $firstUsedAtEpoch,
        ?int $validUntilEpoch,
        int $duracionMinutes,
        int $arrivalMinutes,
        int $nowEpoch
    ): string {
        if ($firstUsedAtEpoch === null) {
            return $nowEpoch > self::arrivalDeadline($issuedAtEpoch, $arrivalMinutes)
                ? self::STATE_EXPIRED_ARRIVAL
                : self::STATE_OK_UNUSED;
        }

        $deadline = $validUntilEpoch ?? self::usageDeadline($firstUsedAtEpoch, $duracionMinutes);
        return $nowEpoch > $deadline ? self::STATE_EXPIRED_USAGE : self::STATE_OK_IN_USE;
    }
}
