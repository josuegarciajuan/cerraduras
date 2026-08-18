<?php
declare(strict_types=1);

namespace App\Domain\Locks;

use App\Domain\Rooms\RoomRepositoryInterface;
use App\Infrastructure\Gateways\Lock\LockGatewayFactory;
use App\Infrastructure\Gateways\Lock\LockGatewayInterface;
use App\Support\Clock;
use App\Support\Errors\ForbiddenException;
use App\Support\Errors\NotFoundException;

/**
 * LockService: admin-facing lock operations (RF-4, TSK-072).
 *
 * Handles:
 *   POST /locks/{room_id}/open  — manual open (scope locks:open).
 *   POST /locks/{room_id}/lock  — manual lock (scope locks:lock).
 *
 * Cooldown policy (design.md §8.4):
 *   - open() is blocked if rooms.cooldown_until > now UNLESS override=true is
 *     passed (requires scope locks:override, enforced by the controller).
 *   - lock() is never blocked by cooldown.
 *
 * Access events are always written regardless of outcome.
 */
final class LockService
{
    private RoomRepositoryInterface $rooms;
    private AccessEventRepositoryInterface $accessEvents;

    public function __construct(
        RoomRepositoryInterface $rooms,
        AccessEventRepositoryInterface $accessEvents
    ) {
        $this->rooms        = $rooms;
        $this->accessEvents = $accessEvents;
    }

    /**
     * Open the lock for a room (admin/manual).
     *
     * @return array{result:string, provider:string}
     *
     * @throws NotFoundException   if room not found.
     * @throws ForbiddenException  room_cooldown if cooldown active and no override.
     */
    public function open(
        int    $roomId,
        string $reason,
        bool   $overrideCooldown,
        string $correlationId
    ): array {
        $room = $this->rooms->findById($roomId);
        if ($room === null) {
            throw new NotFoundException('Room not found', ['room_id' => $roomId]);
        }

        // Cooldown check (anti-reentry). Admin can override with the
        // locks:override scope (controller must validate the scope before
        // passing overrideCooldown=true).
        if (!$overrideCooldown && $room->cooldownUntil !== null) {
            // cooldown_until is stored in UTC; append ' UTC' so strtotime()
            // does not misinterpret it using the local (Europe/Madrid) timezone.
            $cooldownTs = strtotime($room->cooldownUntil . ' UTC');
            $nowTs      = Clock::nowUtc()->getTimestamp();
            if ($cooldownTs !== false && $cooldownTs > $nowTs) {
                $this->writeEvent(
                    $room->id, null,
                    AccessEvent::KIND_DENIED, AccessEvent::RESULT_FAIL,
                    'room_cooldown', 'SIMULATED', $correlationId
                );
                throw new ForbiddenException(
                    'room_cooldown',
                    'Room is in anti-reentry cooldown period',
                    ['room_id' => $roomId, 'cooldown_until' => $room->cooldownUntil]
                );
            }
        }

        $gateway = LockGatewayFactory::make($room);
        $result  = $gateway->open($roomId, ['reason' => $reason]);

        $this->writeEvent(
            $room->id, null,
            AccessEvent::KIND_OPEN,
            $result['ok'] ? AccessEvent::RESULT_OK : AccessEvent::RESULT_FAIL,
            $result['ok'] ? null : ($result['error'] ?? 'gateway_error'),
            $result['provider'],
            $correlationId,
            ['reason' => $reason, 'override_cooldown' => $overrideCooldown]
        );

        return [
            'result'   => $result['ok'] ? 'ok' : 'error',
            'provider' => $result['provider'],
        ];
    }

    /**
     * Lock (secure) the lock for a room (admin/manual).
     *
     * @return array{result:string, provider:string}
     *
     * @throws NotFoundException   if room not found.
     */
    public function lockRoom(
        int    $roomId,
        string $reason,
        string $correlationId
    ): array {
        $room = $this->rooms->findById($roomId);
        if ($room === null) {
            throw new NotFoundException('Room not found', ['room_id' => $roomId]);
        }

        $gateway = LockGatewayFactory::make($room);
        $result  = $gateway->lock($roomId, ['reason' => $reason]);

        $this->writeEvent(
            $room->id, null,
            AccessEvent::KIND_MANUAL_LOCK,
            $result['ok'] ? AccessEvent::RESULT_OK : AccessEvent::RESULT_FAIL,
            $result['ok'] ? null : ($result['error'] ?? 'gateway_error'),
            $result['provider'],
            $correlationId,
            ['reason' => $reason]
        );

        return [
            'result'   => $result['ok'] ? 'ok' : 'error',
            'provider' => $result['provider'],
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $meta
     */
    private function writeEvent(
        int     $roomId,
        ?int    $stayId,
        string  $kind,
        string  $result,
        ?string $reason,
        string  $provider,
        string  $correlationId,
        ?array  $meta = null
    ): void {
        try {
            $this->accessEvents->insert(
                $roomId, $stayId, $kind, $result,
                $reason, $provider, $correlationId, $meta
            );
        } catch (\Throwable $e) {
            error_log('[LockService] Failed to write access_event: ' . $e->getMessage());
        }
    }
}
