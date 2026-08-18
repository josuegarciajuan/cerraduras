<?php
declare(strict_types=1);

namespace App\Domain\Qr;

use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Rooms\RoomTypeRepositoryInterface;
use App\Domain\Stays\Stay;
use App\Domain\Stays\StayRepositoryInterface;
use App\Domain\TimeSlots\TimeSlot;
use App\Domain\TimeSlots\TimeSlotService;
use App\Support\Clock;
use App\Support\Errors\ConflictException;
use App\Support\Errors\NotFoundException;
use App\Support\Errors\UnprocessableException;
use App\Support\Qr\QrTokenizer;
use App\Support\Uuid;

/**
 * QrIssueService: orchestrates the issuance of a QR for a room (RF-2).
 *
 * Steps:
 *   1. Verify the room exists.
 *   2. Verify duracion_minutos is in [DUR_MIN, DUR_MAX] (D3 = 30..720).
 *   3. Verify there's no active stay for the room (room_busy).
 *   4. Verify the current local-time slot for the room's room_type is RENTABLE
 *      (slot_not_rentable). Missing slot configuration is treated as
 *      slot_not_rentable to fail closed.
 *   5. Insert a stay in RESERVED, capturing the supplied vb6 refs.
 *   6. Generate a UUID v4 jti, sign a token using QrTokenizer with
 *      exp = now + room_type.qr_usage_window_minutes.
 *   7. Persist a qr_credentials row with the SHA-256 hash of the token.
 *   8. Return the plaintext token to the caller (this is the only point in
 *      time where the raw token exists outside the printed ticket).
 *
 * Errors map to contracts.md:
 *   - 404 not_found (room or room_type)
 *   - 422 duration_out_of_range
 *   - 409 room_busy
 *   - 422 slot_not_rentable
 */
final class QrIssueService
{
    public const DUR_MIN_MINUTES = 30;
    public const DUR_MAX_MINUTES = 720;

    private RoomRepositoryInterface $rooms;
    private RoomTypeRepositoryInterface $roomTypes;
    private StayRepositoryInterface $stays;
    private TimeSlotService $timeSlots;
    private QrCredentialRepositoryInterface $credentials;
    private QrTokenizer $tokenizer;

    public function __construct(
        RoomRepositoryInterface $rooms,
        RoomTypeRepositoryInterface $roomTypes,
        StayRepositoryInterface $stays,
        TimeSlotService $timeSlots,
        QrCredentialRepositoryInterface $credentials,
        QrTokenizer $tokenizer
    ) {
        $this->rooms = $rooms;
        $this->roomTypes = $roomTypes;
        $this->stays = $stays;
        $this->timeSlots = $timeSlots;
        $this->credentials = $credentials;
        $this->tokenizer = $tokenizer;
    }

    /**
     * @param array<string,mixed> $vb6Refs Already-validated refs (controller
     *   normalizes formats before calling).
     *
     * @return array{
     *   stay_id:int, jti:string, qr_text:string,
     *   issued_at:string, expires_at:string, room_id:int
     * }
     */
    public function issue(int $roomId, int $duracionMinutos, array $vb6Refs): array
    {
        if ($duracionMinutos < self::DUR_MIN_MINUTES || $duracionMinutos > self::DUR_MAX_MINUTES) {
            throw new UnprocessableException(
                'duration_out_of_range',
                'duracion_minutos out of allowed range',
                ['min' => self::DUR_MIN_MINUTES, 'max' => self::DUR_MAX_MINUTES, 'given' => $duracionMinutos]
            );
        }

        $room = $this->rooms->findById($roomId);
        if ($room === null) {
            throw new NotFoundException('Room not found', ['room_id' => $roomId]);
        }
        $roomType = $this->roomTypes->findById($room->roomTypeId);
        if ($roomType === null) {
            // Should not happen given FK, but be defensive: surface as 404 on
            // the room_type side rather than 500.
            throw new NotFoundException('Room type not found', ['room_type_id' => $room->roomTypeId]);
        }

        // Slot validation is checked before room_busy so a misconfigured time
        // window surfaces clearly even if a previous stay is somehow active.
        $nowUtc = Clock::nowUtc();
        $kind = $this->timeSlots->currentKind($room->roomTypeId, $nowUtc);
        if ($kind !== TimeSlot::KIND_RENTABLE) {
            throw new UnprocessableException(
                'slot_not_rentable',
                'Current time is not within a RENTABLE slot for this room type',
                ['room_id' => $roomId, 'room_type_id' => $room->roomTypeId, 'slot_kind' => $kind]
            );
        }

        $active = $this->stays->findActiveForRoom($roomId);
        if ($active !== null) {
            throw new ConflictException(
                'room_busy',
                'There is already an active stay for this room',
                ['room_id' => $roomId, 'active_stay_id' => $active->id]
            );
        }

        // 1) Create the stay.
        $stayId = $this->stays->insertReserved($roomId, $duracionMinutos, $vb6Refs);

        // 2) Mint the token.
        $iat = $nowUtc->getTimestamp();
        $exp = $iat + ($roomType->qrUsageWindowMinutes * 60);
        $jti = Uuid::v4();
        $token = $this->tokenizer->issue($roomId, $stayId, $jti, $iat, $exp);
        $tokenHash = QrTokenizer::hashForStorage($token);

        // 3) Persist the credential row (we never store the raw token).
        $issuedAtSql = self::utcSql($iat);
        $expiresAtSql = self::utcSql($exp);
        $this->credentials->insert($stayId, $roomId, $jti, $tokenHash, $issuedAtSql, $expiresAtSql);

        return [
            'stay_id' => $stayId,
            'jti' => $jti,
            'qr_text' => $token,
            'issued_at' => self::iso($iat),
            'expires_at' => self::iso($exp),
            'room_id' => $roomId,
        ];
    }

    /**
     * Revoke a credential by its jti. Returns the revoked record. Throws 404
     * if not found, 409 if already revoked.
     */
    public function revoke(string $jti, ?string $reason = null): QrCredential
    {
        $cred = $this->credentials->findByJti($jti);
        if ($cred === null) {
            throw new NotFoundException('QR credential not found', ['jti' => $jti]);
        }
        if ($cred->isRevoked()) {
            throw new ConflictException(
                'qr_already_revoked',
                'QR credential is already revoked',
                ['jti' => $jti]
            );
        }
        $ok = $this->credentials->markRevoked($jti);
        if (!$ok) {
            // Race with another revoker.
            throw new ConflictException(
                'qr_already_revoked',
                'QR credential is already revoked',
                ['jti' => $jti]
            );
        }
        // Reload to capture revoked_at.
        $reloaded = $this->credentials->findByJti($jti);
        if ($reloaded === null) {
            throw new \RuntimeException('Credential disappeared after revoke');
        }
        return $reloaded;
    }

    /**
     * UTC DATETIME(3) string format usable directly with MySQL.
     */
    private static function utcSql(int $epoch): string
    {
        return gmdate('Y-m-d H:i:s', $epoch) . '.000';
    }

    private static function iso(int $epoch): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $epoch);
    }
}
