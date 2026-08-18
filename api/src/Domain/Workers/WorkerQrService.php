<?php
declare(strict_types=1);

namespace App\Domain\Workers;

use App\Domain\Locks\AccessEvent;
use App\Domain\Locks\AccessEventRepositoryInterface;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Rooms\RoomTypeRepositoryInterface;
use App\Infrastructure\Gateways\Lock\LockGatewayFactory;
use App\Support\Clock;
use App\Support\Errors\ApiException;
use App\Support\Errors\ForbiddenException;
use App\Support\Errors\NotFoundException;
use App\Support\Qr\QrTokenizer;

/**
 * WorkerQrService: validates master QR tokens and opens doors for workers.
 *
 * Unlike guest QR validation, workers:
 *   - Are NOT constrained by stays, cooldowns, or time slots.
 *   - Are constrained by role → room_type access.
 *   - Cannot enter the same room twice without leaving first.
 *
 * See RF-W3, RF-W5, TSK-W10.
 */
final class WorkerQrService
{
    private QrTokenizer $tokenizer;
    private WorkerRepositoryInterface $workerRepo;
    private WorkerRoleRepositoryInterface $roleRepo;
    private WorkerSessionRepositoryInterface $sessionRepo;
    private RoomRepositoryInterface $roomRepo;
    private RoomTypeRepositoryInterface $roomTypeRepo;
    private AccessEventRepositoryInterface $accessEvents;

    public function __construct(
        QrTokenizer $tokenizer,
        WorkerRepositoryInterface $workerRepo,
        WorkerRoleRepositoryInterface $roleRepo,
        WorkerSessionRepositoryInterface $sessionRepo,
        RoomRepositoryInterface $roomRepo,
        RoomTypeRepositoryInterface $roomTypeRepo,
        AccessEventRepositoryInterface $accessEvents
    ) {
        $this->tokenizer    = $tokenizer;
        $this->workerRepo   = $workerRepo;
        $this->roleRepo     = $roleRepo;
        $this->sessionRepo  = $sessionRepo;
        $this->roomRepo     = $roomRepo;
        $this->roomTypeRepo = $roomTypeRepo;
        $this->accessEvents = $accessEvents;
    }

    /**
     * Validate a worker QR token and open the door.
     *
     * @param string $token        QR token (JWT-like)
     * @param int    $roomId       Room being accessed
     * @param string $deviceId     Scanning device identifier (for audit)
     * @param string $provider     e.g. 'TUYA', 'SIMULATED', 'ESP32'
     * @param string $correlationId Request correlation ID
     *
     * @return array{ok:bool, worker:array, worker_session_id:int, action:string}
     *
     * @throws ForbiddenException  qr_revoked, access_denied
     * @throws NotFoundException   token or room not found
     */
    public function validate(
        string $token,
        int $roomId,
        string $deviceId,
        string $provider,
        string $correlationId
    ): array {
        // 1. Parse and verify token
        try {
            $claims = $this->tokenizer->parse($token);
        } catch (ApiException $e) {
            $this->writeDenied($roomId, null, 'qr_invalid_signature', $provider, $correlationId);
            throw $e;
        }

        // 2. Ensure this is a worker token
        if (($claims['sub'] ?? 'guest') !== 'worker') {
            $this->writeDenied($roomId, null, 'qr_invalid_signature', $provider, $correlationId);
            throw new ForbiddenException(
                'qr_invalid_signature',
                'Token is not a worker QR'
            );
        }

        $workerId = (int) $claims['wid'];
        $jti      = (string) $claims['jti'];

        // 3. Look up worker by JTI and verify active
        $worker = $this->workerRepo->findByJti($jti);
        if ($worker === null || !$worker->isActive()) {
            $this->writeDenied($roomId, null, 'qr_revoked', $provider, $correlationId);
            throw new ForbiddenException(
                'qr_revoked',
                'Worker QR no longer valid'
            );
        }

        // 4. Verify room exists and get its room_type
        $room = $this->roomRepo->findById($roomId);
        if ($room === null) {
            $this->writeDenied($roomId, null, 'not_found', $provider, $correlationId);
            throw new NotFoundException('Room not found', ['room_id' => $roomId]);
        }

        $roomType = $this->roomTypeRepo->findById($room->roomTypeId);

        // 5. Check role → room_type access
        if (!$this->roleRepo->canAccessRoomType($worker->roleId, $room->roomTypeId)) {
            $this->writeDenied(
                $roomId, null, 'access_denied',
                $provider, $correlationId,
                ['worker_id' => $workerId, 'role_id' => $worker->roleId, 'room_type_id' => $room->roomTypeId]
            );
            throw new ForbiddenException(
                'access_denied',
                'Worker role cannot access this room type'
            );
        }

        // 6. Check worker doesn't already have active session in this room
        $activeSessions = $this->sessionRepo->findActiveForWorker($workerId);
        foreach ($activeSessions as $as) {
            if ($as->roomId === $roomId) {
                throw new ApiException(
                    409,
                    'already_inside',
                    'Worker already has an active session in this room',
                    ['worker_id' => $workerId, 'room_id' => $roomId, 'worker_session_id' => $as->id]
                );
            }
        }

        // 7. Open the lock
        $gateway   = LockGatewayFactory::make($room);
        $openResult = $gateway->open($roomId);
        $gwProvider = $openResult['provider'] ?? $provider;

        // 8. Create worker session
        $now             = Clock::nowUtc();
        $nowStr          = $now->format('Y-m-d H:i:s.v');
        $session         = new WorkerSession(0, $workerId, $roomId, $nowStr, null, null, $correlationId, '', '');
        $workerSessionId = $this->sessionRepo->insert($session);

        // 9. Write QR_VALIDATE access event
        try {
            $this->accessEvents->insert(
                $roomId,
                null,                           // no stay_id for workers
                AccessEvent::KIND_QR_VALIDATE,
                AccessEvent::RESULT_OK,
                null,
                $gwProvider,
                $correlationId,
                [
                    'device_id' => $deviceId,
                    'jti'       => $jti,
                    'worker_id' => $workerId,
                ],
                $workerSessionId               // worker_session_id
            );
        } catch (\Throwable $e) {
            error_log('[WorkerQrService] Failed to write access_event: ' . $e->getMessage());
        }

        return [
            'ok'                 => true,
            'worker'             => [
                'id'   => $worker->id,
                'name' => $worker->name,
            ],
            'worker_session_id'  => $workerSessionId,
            'action'             => 'open',
        ];
    }

    // -- private helpers -------------------------------------------------------

    /**
     * @param array<string,mixed>|null $meta
     */
    private function writeDenied(
        int $roomId,
        ?int $stayId,
        string $reason,
        string $provider,
        string $correlationId,
        ?array $meta = null
    ): void {
        try {
            $this->accessEvents->insert(
                $roomId, $stayId,
                AccessEvent::KIND_DENIED,
                AccessEvent::RESULT_FAIL,
                $reason, $provider, $correlationId,
                $meta
            );
        } catch (\Throwable $e) {
            error_log('[WorkerQrService] Failed to write DENIED event: ' . $e->getMessage());
        }
    }
}
