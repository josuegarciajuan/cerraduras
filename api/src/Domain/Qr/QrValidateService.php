<?php
declare(strict_types=1);

namespace App\Domain\Qr;

use App\Domain\Devices\DeviceRepositoryInterface;
use App\Domain\Devices\SwitchService;
use App\Domain\Locks\AccessEvent;
use App\Domain\Locks\AccessEventRepositoryInterface;
use App\Domain\Presence\IotSession;
use App\Domain\Presence\IotSessionRepositoryInterface;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Stays\Stay;
use App\Domain\Stays\StayRepositoryInterface;
use App\Domain\Stays\StayStateMachine;
use App\Infrastructure\Gateways\Lock\LockGatewayInterface;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Errors\ApiException;
use App\Support\Errors\ForbiddenException;
use App\Support\Errors\NotFoundException;
use App\Support\Qr\QrTokenizer;

/** Tolerance in seconds for clock skew (design.md §5.2). */

/**
 * QrValidateService: orchestrates QR validation and door opening (RF-3, RF-4).
 *
 * Validation steps (design.md §5.3):
 *   1. Parse + verify HMAC signature → qr_invalid_signature / qr_expired
 *   2. Load QrCredential by jti from DB.
 *   3. Check revoked_at                → qr_revoked (403)
 *   4. Load Stay (needed for duracion_minutos) and evaluate the two-phase
 *      QR lifetime (Fase 51 / Bug 4):
 *        - never used + past arrival deadline  → qr_expired {window:'arrival'}
 *        - never used + inside arrival window  → allowed (first use will be claimed)
 *        - already used + past usage deadline  → qr_expired {window:'usage'}
 *        - already used + inside usage window  → re-entry allowed
 *   5. Load Room.
 *   6. Verify device_id maps to the room in the token → device_mismatch (403).
 *      In SIMULATED_MODE with no registered device, skip (dev-friendly).
 *   7. Check room cooldown            → room_cooldown (403)
 *   8. Verify Stay status RESERVED | OCCUPIED.
 *
 * On success:
 *   - Atomically claim the first use (markFirstUse: first_used_at=consumed_at=now,
 *     valid_until = now + stay.duracion_minutos) on the first scan only.
 *   - Transition stay RESERVED → OCCUPIED via StayStateMachine.
 *   - Call LockGateway::open().
 *   - Write access_event(OPEN, OK, provider).
 *
 * On failure at any step:
 *   - Write access_event(DENIED, FAIL, reason).
 *   - Re-throw the original exception.
 *
 * Returns array matching contracts.md §2.4 POST /qr/validate 200 body.
 */
final class QrValidateService
{
    private QrTokenizer $tokenizer;
    private QrCredentialRepositoryInterface $credentials;
    private RoomRepositoryInterface $rooms;
    private DeviceRepositoryInterface $devices;
    private StayRepositoryInterface $stays;
    private StayStateMachine $stateMachine;
    private LockGatewayInterface $lockGateway;
    private AccessEventRepositoryInterface $accessEvents;
    private ?IotSessionRepositoryInterface $iotSessions;
    private ?SwitchService $switchService;

    public function __construct(
        QrTokenizer $tokenizer,
        QrCredentialRepositoryInterface $credentials,
        RoomRepositoryInterface $rooms,
        DeviceRepositoryInterface $devices,
        StayRepositoryInterface $stays,
        StayStateMachine $stateMachine,
        LockGatewayInterface $lockGateway,
        AccessEventRepositoryInterface $accessEvents,
        ?IotSessionRepositoryInterface $iotSessions = null,
        ?SwitchService $switchService = null
    ) {
        $this->tokenizer    = $tokenizer;
        $this->credentials  = $credentials;
        $this->rooms        = $rooms;
        $this->devices      = $devices;
        $this->stays        = $stays;
        $this->stateMachine = $stateMachine;
        $this->lockGateway  = $lockGateway;
        $this->accessEvents = $accessEvents;
        $this->iotSessions  = $iotSessions;
        $this->switchService = $switchService;
    }

    /**
     * Validate a QR and open the door.
     *
     * @return array{
     *   allow:bool, stay_id:int, room_id:int, action:string,
     *   reason:null, cooldown_until:null
     * }
     *
     * @throws ForbiddenException  on any validation failure (403 codes).
     * @throws NotFoundException   if jti or room not found.
     */
    public function validate(
        string $qrText,
        string $deviceId,
        string $correlationId
    ): array {
        // ----------------------------------------------------------------
        // Step 1: parse + verify signature / expiry
        // ----------------------------------------------------------------
        try {
            $payload = $this->tokenizer->parse($qrText);
        } catch (\Throwable $e) {
            // qr_invalid_signature or qr_expired or malformed token
            // Write a DENIED access_event so the dashboard shows rejection feedback.
            // Room resolution is best-effort: try extracting from token first segment,
            // then fall back to device → pack → room lookup. Use room_id=0 if all fail.
            $deniedRoomId = $this->resolveRoomIdForDeniedEvent($qrText, $deviceId);
            // Guard against FK violation: access_events.room_id references rooms(id).
            // If the room cannot be resolved (room_id=0 / null), skip the DENIED
            // event — we cannot show it on any dashboard room. This edge case only
            // occurs when a device's pack has been unassigned (e.g. during tests).
            if ($deniedRoomId !== null && $deniedRoomId > 0) {
                $reason = $e instanceof ApiException
                    ? $e->errorCode()
                    : ($e->getCode() ? (string) $e->getCode() : 'qr_invalid_signature');
                $this->writeAccessEvent(
                    $deniedRoomId, null, AccessEvent::KIND_DENIED,
                    AccessEvent::RESULT_FAIL, $reason,
                    $correlationId
                );
            }
            throw $e;
        }

        $tokenRoomId = (int) $payload['room_id'];
        $tokenStayId = (int) $payload['stay_id'];
        $jti         = (string) $payload['jti'];

        // ----------------------------------------------------------------
        // Step 1b: expiration check (tokenizer does NOT do this)
        //
        // Production (QR_EXP_FROM_DB=false): the signed exp in the token
        // payload is the single source of truth for expiration. The DB
        // column qr_credentials.expires_at is informational.
        //
        // Dev/testing (QR_EXP_FROM_DB=true): the token exp is ignored so
        // the validity window can be extended by updating the DB column
        // without regenerating the physical QR. Expiration is checked in
        // step 2 after the credential is loaded.
        //
        // Tolerance of ±30 s for clock skew (design.md §5.2).
        // ----------------------------------------------------------------
        if (!Config::getBool('QR_EXP_FROM_DB', false)) {
            $nowEpoch = Clock::nowUtc()->getTimestamp();
            if ($payload['exp'] + 30 < $nowEpoch) {
                throw new ForbiddenException(
                    'qr_expired',
                    'QR token has expired',
                    ['jti' => $jti, 'exp' => $payload['exp'], 'now' => $nowEpoch]
                );
            }
        }

        // ----------------------------------------------------------------
        // Step 2: load credential
        // ----------------------------------------------------------------
        $cred = $this->credentials->findByJti($jti);
        if ($cred === null) {
            $this->writeAccessEvent(
                $tokenRoomId, $tokenStayId, AccessEvent::KIND_DENIED,
                AccessEvent::RESULT_FAIL, 'not_found', $correlationId
            );
            throw new NotFoundException('QR credential not found', ['jti' => $jti]);
        }

        // ----------------------------------------------------------------
        // Step 2b: DB-based expiration check (only when QR_EXP_FROM_DB=true)
        //
        // In dev/testing mode, the validity window is controlled by the
        // editable qr_credentials.expires_at column. The signed exp in
        // the token is ignored (see step 1b).
        //
        // Same tolerance of ±30 s and same error code as the token-based
        // check, so the API contract is unchanged.
        // ----------------------------------------------------------------
        if (Config::getBool('QR_EXP_FROM_DB', false)) {
            $dbExpTs = strtotime($cred->expiresAt . ' UTC');
            if ($dbExpTs !== false && $dbExpTs + 30 < Clock::nowUtc()->getTimestamp()) {
                $this->writeAccessEvent(
                    $tokenRoomId, $tokenStayId, AccessEvent::KIND_DENIED,
                    AccessEvent::RESULT_FAIL, 'qr_expired', $correlationId
                );
                throw new ForbiddenException(
                    'qr_expired',
                    'QR credential has expired (DB expires_at)',
                    ['jti' => $jti, 'db_expires_at' => $cred->expiresAt]
                );
            }
        }

        // ----------------------------------------------------------------
        // Step 3: revoked check
        // ----------------------------------------------------------------
        if ($cred->isRevoked()) {
            $this->writeAccessEvent(
                $tokenRoomId, $tokenStayId, AccessEvent::KIND_DENIED,
                AccessEvent::RESULT_FAIL, 'qr_revoked', $correlationId
            );
            throw new ForbiddenException(
                'qr_revoked',
                'QR credential has been revoked',
                ['jti' => $jti]
            );
        }

        // ----------------------------------------------------------------
        // Step 4: QR lifetime — arrival window (pre-first-use) or usage
        // window (first_used_at + stay.duracion_minutos). Fase 51 / Bug 4.
        //
        // The Stay is loaded here (earlier than before) because its
        // duracion_minutos defines the usage window. The instance is reused in
        // step 8. NOTE: for a missing/mismatched stay the 404 not_found now
        // surfaces before the room/device/cooldown checks instead of after.
        // ----------------------------------------------------------------
        $stay = $this->stays->findById($tokenStayId);
        if ($stay === null || $stay->roomId !== $tokenRoomId) {
            $this->writeAccessEvent(
                $tokenRoomId, $tokenStayId, AccessEvent::KIND_DENIED,
                AccessEvent::RESULT_FAIL, 'not_found', $correlationId
            );
            throw new NotFoundException('Stay not found or mismatched', ['stay_id' => $tokenStayId]);
        }

        $nowEpoch        = Clock::nowUtc()->getTimestamp();
        $issuedEpoch     = self::epochFromSql($cred->issuedAt) ?? $nowEpoch;
        $firstUsedEpoch  = self::epochFromSql($cred->firstUsedAt)
            ?? self::epochFromSql($cred->consumedAt);
        $validUntilEpoch = self::epochFromSql($cred->validUntil);
        $arrivalMinutes  = Config::getInt('QR_ARRIVAL_WINDOW_MINUTES', 15) ?? 15;

        // For legacy rows used without a stored valid_until, evaluate() derives
        // the usage deadline from first_used_at + duracion_minutos.
        $windowState = QrWindows::evaluate(
            $issuedEpoch,
            $firstUsedEpoch,
            $validUntilEpoch,
            $stay->duracionMinutos,
            $arrivalMinutes,
            $nowEpoch
        );

        if ($windowState === QrWindows::STATE_EXPIRED_ARRIVAL) {
            $this->writeAccessEvent(
                $tokenRoomId, $tokenStayId, AccessEvent::KIND_DENIED,
                AccessEvent::RESULT_FAIL, 'qr_expired', $correlationId
            );
            throw new ForbiddenException(
                'qr_expired',
                'QR credential was not used within the arrival window',
                [
                    'jti' => $jti,
                    'window' => 'arrival',
                    'arrival_deadline' => self::utcSql(
                        QrWindows::arrivalDeadline($issuedEpoch, $arrivalMinutes)
                    ),
                ]
            );
        }

        if ($windowState === QrWindows::STATE_EXPIRED_USAGE) {
            $this->writeAccessEvent(
                $tokenRoomId, $tokenStayId, AccessEvent::KIND_DENIED,
                AccessEvent::RESULT_FAIL, 'qr_expired', $correlationId
            );
            throw new ForbiddenException(
                'qr_expired',
                'QR credential usage window has expired',
                ['jti' => $jti, 'window' => 'usage', 'valid_until' => $cred->validUntil]
            );
        }

        // The first use is claimed atomically at the end (only after every
        // other check passes) so a rejected scan does not burn the QR.
        $needsFirstUse = ($windowState === QrWindows::STATE_OK_UNUSED);

        // ----------------------------------------------------------------
        // Step 5: room exists
        // ----------------------------------------------------------------
        $room = $this->rooms->findById($tokenRoomId);
        if ($room === null) {
            $this->writeAccessEvent(
                $tokenRoomId, $tokenStayId, AccessEvent::KIND_DENIED,
                AccessEvent::RESULT_FAIL, 'not_found', $correlationId
            );
            throw new NotFoundException('Room not found', ['room_id' => $tokenRoomId]);
        }

        // ----------------------------------------------------------------
        // Step 6: device_id → room_id verification
        // ----------------------------------------------------------------
        $this->verifyDevice($deviceId, $tokenRoomId, $tokenStayId, $correlationId);

        // ----------------------------------------------------------------
        // Step 7: cooldown check
        // ----------------------------------------------------------------
        if ($room->cooldownUntil !== null) {
            // cooldown_until is stored UTC; append ' UTC' to prevent
            // strtotime() misinterpreting it as local (Europe/Madrid) time.
            $cooldownUntilTs = strtotime($room->cooldownUntil . ' UTC');
            $nowTs           = Clock::nowUtc()->getTimestamp();
            if ($cooldownUntilTs !== false && $cooldownUntilTs > $nowTs) {
                $this->writeAccessEvent(
                    $tokenRoomId, $tokenStayId, AccessEvent::KIND_DENIED,
                    AccessEvent::RESULT_FAIL, 'room_cooldown', $correlationId
                );
                throw new ForbiddenException(
                    'room_cooldown',
                    'Room is in anti-reentry cooldown period',
                    ['room_id' => $tokenRoomId, 'cooldown_until' => $room->cooldownUntil]
                );
            }
        }

        // ----------------------------------------------------------------
        // Step 8: stay is in a valid state (instance loaded in step 4)
        // ----------------------------------------------------------------
        if (!in_array($stay->status, [Stay::STATUS_RESERVED, Stay::STATUS_OCCUPIED], true)) {
            $this->writeAccessEvent(
                $tokenRoomId, $tokenStayId, AccessEvent::KIND_DENIED,
                AccessEvent::RESULT_FAIL, 'stay_wrong_state', $correlationId
            );
            throw new ForbiddenException(
                'stay_wrong_state',
                'Stay is not in a valid state for QR validation',
                ['stay_id' => $tokenStayId, 'status' => $stay->status]
            );
        }

        // ----------------------------------------------------------------
        // All checks passed — perform mutations
        // ----------------------------------------------------------------

        // Claim the first use (only on the first scan; re-entry skips this).
        // markFirstUse() is atomic (WHERE consumed_at IS NULL) so only one
        // concurrent caller wins; it records first_used_at / consumed_at and
        // valid_until = now + stay.duracion_minutos. The winning caller is
        // also the only one allowed to transition RESERVED → OCCUPIED.
        $claimedFirstUse = false;
        if ($needsFirstUse) {
            $newValidUntil = $nowEpoch + $stay->duracionMinutos * 60;
            $claimedFirstUse = $this->credentials->markFirstUse($jti, self::utcSql($newValidUntil));
            if ($claimedFirstUse && $stay->status === Stay::STATUS_RESERVED) {
                $this->stateMachine->firstEntry($stay);
            }
        }

        // The request that claimed the first use reports 'open'; every other
        // allowed scan (already-used re-entry, or a lost concurrent race) is
        // reported as 're_entry'.
        $isReEntry = !($needsFirstUse && $claimedFirstUse);

        // Write QR_VALIDATE event so the dashboard can detect recent scans
        $this->writeAccessEvent(
            $tokenRoomId, $tokenStayId,
            AccessEvent::KIND_QR_VALIDATE,
            AccessEvent::RESULT_OK,
            null,
            $correlationId,
            ['device_id' => $deviceId, 'jti' => $jti]
        );

        // Open the lock
        $gatewayResult = $this->lockGateway->open($tokenRoomId);
        $provider      = $gatewayResult['provider'];

        // Light control: deferred to IotSessionService::processEvent which
        // calls switchService->turnOn() when a real PROXIMITY OPEN sensor
        // event arrives, ensuring the light activates in response to the
        // physical door opening, not at QR validation time.
        // See dashboard.md — Bug 1 fix: light must follow door open, not QR scan.

        // Write success access_event
        $this->writeAccessEvent(
            $tokenRoomId, $tokenStayId,
            AccessEvent::KIND_OPEN,
            $gatewayResult['ok'] ? AccessEvent::RESULT_OK : AccessEvent::RESULT_FAIL,
            $gatewayResult['error'],
            $correlationId,
            ['device_id' => $deviceId, 'jti' => $jti]
        );

        // Note: IoT session door_state is no longer faked here (F28).
        // Only real sensor events (Tuya/ESP32) update door_state.
        if (false && $this->iotSessions !== null) {
            // Disabled: QR validation no longer sets door_state to OPEN.
            // The door state is driven exclusively by physical sensors.
        }

        return [
            'allow'          => true,
            'stay_id'        => $tokenStayId,
            'room_id'        => $tokenRoomId,
            'action'         => $isReEntry ? 're_entry' : 'open',
            'reason'         => null,
            'cooldown_until' => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Best-effort room resolution for DENIED access events when the token
     * cannot be fully parsed (corrupt signature, malformed segments, etc.).
     *
     * Priority:
     *  1. Try to decode the first segment of the QR token as base64url JSON
     *     → extract room_id from the (unsigned) payload.
     *  2. Look up the scanning device by external_id → pack_id → room via pack.
     *  3. Return null if nothing can be resolved (caller falls back to 0).
     *
     * @return int|null  room_id, or null if unresolvable
     */
    private function resolveRoomIdForDeniedEvent(string $qrText, string $deviceId): ?int
    {
        // --- Strategy 1: decode first segment of the token ---
        $firstDot = strpos($qrText, '.');
        if ($firstDot !== false) {
            $firstSegment = substr($qrText, 0, $firstDot);
            // Inline base64url decode (QrTokenizer::base64UrlDecode is private)
            $padded = strtr($firstSegment, '-_', '+/');
            // Pad to multiple of 4 (base64 requirement for strict decode)
            $padLen = 4 - (strlen($padded) % 4);
            if ($padLen < 4) { $padded .= str_repeat('=', (int) $padLen); }
            $decoded = base64_decode($padded, true);
            if ($decoded !== false) {
                $parsed = json_decode($decoded, true);
                if (is_array($parsed) && isset($parsed['room_id'])) {
                    return (int) $parsed['room_id'];
                }
            }
        }

        // --- Strategy 2: resolve from device → pack → room ---
        try {
            $device = $this->devices->findByExternalId($deviceId);
            if ($device !== null && $device->packId !== null) {
                $room = $this->rooms->findByPackId($device->packId);
                if ($room !== null) {
                    return $room->id;
                }
            }
        } catch (\Throwable $e) {
            // Non-critical: fall through to null
        }

        return null; // unresolvable → caller will use room_id=0
    }

    /**
     * Verify that device_id is registered as an RPI for the expected room.
     *
     * In SIMULATED_MODE, if no device is registered in the DB for the given
     * device_id, the check is skipped (dev-friendly, allows testing without
     * pre-registering every Raspberry).
     */
    private function verifyDevice(
        string $deviceId,
        int    $expectedRoomId,
        int    $stayId,
        string $correlationId
    ): void {
        $device = $this->devices->findByKindAndExternalId('RPI', $deviceId);

        if ($device !== null) {
            // Track liveness: update last_seen_at on the RPI device
            try {
                $this->devices->updateLastSeen($device->id);
            } catch (\Throwable $e) {
                // Non-critical: don't block validation if tracking fails
            }
        }

        if ($device === null) {
            // No device registered: in simulated mode we allow it (RF-11 dev
            // flow); in real mode we deny.
            if (!Config::getBool('SIMULATED_MODE', true)) {
                $this->writeAccessEvent(
                    $expectedRoomId, $stayId, AccessEvent::KIND_DENIED,
                    AccessEvent::RESULT_FAIL, 'device_mismatch', $correlationId
                );
                throw new ForbiddenException(
                    'device_mismatch',
                    'Device not registered in this system',
                    ['device_id' => $deviceId]
                );
            }
            // Simulated mode: skip device check.
            return;
        }

        // Canonical model (F30): the device's room always resolves through its
        // pack. A device not linked to a pack resolves to no room.
        $deviceRoomId = $device->packId !== null
            ? $this->devices->resolveRoomId($device->id)
            : null;

        if ($deviceRoomId !== $expectedRoomId) {
            $this->writeAccessEvent(
                $expectedRoomId, $stayId, AccessEvent::KIND_DENIED,
                AccessEvent::RESULT_FAIL, 'device_mismatch', $correlationId
            );
            throw new ForbiddenException(
                'device_mismatch',
                'Device belongs to a different room',
                ['device_id' => $deviceId, 'device_pack_id' => $device->packId, 'device_room_id' => $deviceRoomId, 'token_room_id' => $expectedRoomId]
            );
        }
    }

    /**
     * @param array<string,mixed>|null $meta
     */
    private function writeAccessEvent(
        int     $roomId,
        ?int    $stayId,
        string  $kind,
        string  $result,
        ?string $reason,
        string  $correlationId,
        ?array  $meta = null
    ): void {
        try {
            $this->accessEvents->insert(
                $roomId, $stayId, $kind, $result, $reason,
                $this->lockGateway->getProvider(),
                $correlationId, $meta
            );
        } catch (\Throwable $e) {
            // Never let audit failure propagate; log silently.
            // In a production system this would go to a secondary logger.
            error_log('[QrValidateService] Failed to write access_event: ' . $e->getMessage());
        }
    }

    /**
     * Parse a DB DATETIME(3) UTC string into an epoch, or null when absent /
     * unparsable. DATETIME columns are stored UTC so ' UTC' is appended to
     * avoid strtotime() interpreting them as Europe/Madrid local time.
     */
    private static function epochFromSql(?string $sql): ?int
    {
        if ($sql === null || $sql === '') {
            return null;
        }
        $ts = strtotime($sql . ' UTC');
        return $ts === false ? null : $ts;
    }

    /** UTC DATETIME(3) string usable directly with MySQL. */
    private static function utcSql(int $epoch): string
    {
        return gmdate('Y-m-d H:i:s', $epoch) . '.000';
    }
}
