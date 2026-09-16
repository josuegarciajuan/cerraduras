<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use App\Domain\Devices\SwitchService;
use App\Domain\Anomalies\AnomalyService;
use App\Domain\Locks\AccessEvent;
use App\Domain\Locks\AccessEventRepositoryInterface;
use App\Domain\Rooms\Room;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Rooms\RoomType;
use App\Domain\Rooms\RoomTypeRepositoryInterface;
use App\Domain\Stays\Stay;
use App\Domain\Stays\StayRepositoryInterface;
use App\Domain\Stays\StayStateMachine;
use App\Domain\Workers\WorkerSessionRepositoryInterface;
use App\Infrastructure\Gateways\Lock\LockGatewayFactory;
use App\Support\Clock;
use PDOException;

/**
 * ExitActionService: executes all side-effects when the exit rule fires.
 *
 * Fase 41 (RF-47, design.md §3.3): the webhook and the exit-scan worker both
 * call executeIfPending(). It re-locks the session + active stay, re-validates
 * the rule under the lock, claims the cycle with a conditional
 * markExitEvaluated() and only then applies the effects. That makes concurrent
 * evaluations produce a single stay transition.
 *
 * The legacy execute() is kept for callers that already hold the objects; it
 * applies the effects without the extra lock/claim.
 */
final class ExitActionService
{
    /** Same acquisition order as IotSessionService: iot_sessions, then stays. */
    private const MAX_TX_RETRIES = 3;

    private RoomRepositoryInterface        $rooms;
    private IotSessionRepositoryInterface  $iotSessions;
    private StayStateMachine               $stateMachine;
    private AccessEventRepositoryInterface $accessEvents;
    private ?SwitchService                 $switchService;
    private ?AnomalyService                $anomalyService;
    private ?WorkerSessionRepositoryInterface $workerSessionRepo;
    private ?StayRepositoryInterface       $stays;
    private ?ExitRuleEvaluator             $exitEvaluator;
    private ?RoomTypeRepositoryInterface   $roomTypes;

    public function __construct(
        RoomRepositoryInterface        $rooms,
        IotSessionRepositoryInterface  $iotSessions,
        StayStateMachine               $stateMachine,
        AccessEventRepositoryInterface $accessEvents,
        ?SwitchService                 $switchService = null,
        ?AnomalyService                $anomalyService = null,
        ?WorkerSessionRepositoryInterface $workerSessionRepo = null,
        ?StayRepositoryInterface       $stays = null,
        ?ExitRuleEvaluator             $exitEvaluator = null,
        ?RoomTypeRepositoryInterface   $roomTypes = null
    ) {
        $this->rooms         = $rooms;
        $this->iotSessions   = $iotSessions;
        $this->stateMachine  = $stateMachine;
        $this->accessEvents  = $accessEvents;
        $this->switchService = $switchService;
        $this->anomalyService = $anomalyService;
        $this->workerSessionRepo = $workerSessionRepo;
        $this->stays         = $stays;
        $this->exitEvaluator = $exitEvaluator;
        $this->roomTypes     = $roomTypes;
    }

    /**
     * Idempotent exit evaluation: locks, re-validates, claims the door cycle
     * and applies the effects. Safe to call from the webhook and from N
     * concurrent exit-scan instances.
     */
    public function executeIfPending(int $roomId, string $correlationId): bool
    {
        if ($this->stays === null || $this->exitEvaluator === null) {
            return false; // not wired for atomic exit (unit-test legacy path)
        }

        for ($attempt = 1; $attempt <= self::MAX_TX_RETRIES; $attempt++) {
            try {
                $this->iotSessions->beginTransaction();
                $session = $this->iotSessions->lockByRoomId($roomId);
                $stay    = $this->stays->lockActiveForRoom($roomId);
                $room    = $this->rooms->findById($roomId);

                if ($room === null || $stay === null) {
                    $this->iotSessions->commit();
                    return false;
                }

                $now      = Clock::nowUtc();
                $roomType = ($this->roomTypes !== null && $room->roomTypeId > 0)
                    ? $this->roomTypes->findById($room->roomTypeId)
                    : null;

                $fired = $this->exitEvaluator->evaluate(
                    $session,
                    $stay,
                    $this->exitEvaluator->resolveGapSeconds($room),
                    $now->getTimestamp()
                );

                if (!$fired) {
                    $this->iotSessions->commit();
                    return false;
                }

                $closeAt = $session->lastCloseAt ?? $now->format('Y-m-d H:i:s.v');
                if (!$this->iotSessions->markExitEvaluated($roomId, $closeAt)) {
                    // Another worker already claimed this door cycle.
                    $this->iotSessions->commit();
                    return false;
                }

                $this->applyEffects($room, $session, $stay, $roomType, $correlationId, $now);
                $this->iotSessions->commit();
                return true;
            } catch (PDOException $e) {
                $this->safeRollback();
                if (!$this->isRetryableDeadlock($e) || $attempt >= self::MAX_TX_RETRIES) {
                    error_log('[ExitActionService] executeIfPending failed room=' . $roomId . ': ' . $e->getMessage());
                    return false;
                }
                usleep(20_000 * $attempt + random_int(0, 10_000));
            } catch (\Throwable $e) {
                // Never leave the transaction open on an unexpected failure.
                $this->safeRollback();
                throw $e;
            }
        }

        return false;
    }

    /**
     * Legacy path: execute all side-effects for an already loaded stay.
     *
     * @param \DateTimeImmutable|null $now override for testing
     */
    public function execute(
        Room                       $room,
        IotSession                 $session,
        Stay                       $stay,
        ?RoomType                  $roomType,
        string                     $correlationId,
        ?\DateTimeImmutable        $now = null
    ): void {
        $now = $now ?? Clock::nowUtc();

        $this->applyEffects($room, $session, $stay, $roomType, $correlationId, $now);
        $session->exitEvaluatedAt = $now->format('Y-m-d H:i:s.v');
        $this->iotSessions->upsert($session);
    }

    /**
     * Apply the exit side-effects. DB-only work: must be called inside the
     * caller's transaction (executeIfPending) or after validation (execute).
     */
    private function applyEffects(
        Room                       $room,
        IotSession                 $session,
        Stay                       $stay,
        ?RoomType                  $roomType,
        string                     $correlationId,
        \DateTimeImmutable         $now
    ): void {
        $nowStr = $now->format('Y-m-d H:i:s.v');

        // F38 W13: close active worker sessions for this room.
        $gateway    = LockGatewayFactory::make($room);
        $gwProvider = $gateway->getProvider();

        if ($this->workerSessionRepo !== null) {
            $activeWorkerSessions = $this->workerSessionRepo->findActiveForRoom($room->id);
            foreach ($activeWorkerSessions as $ws) {
                $this->workerSessionRepo->close($ws->id, 'EXIT_RULE', $nowStr);
                try {
                    $this->accessEvents->insert(
                        $room->id, null,
                        AccessEvent::KIND_WORKER_EXIT,
                        AccessEvent::RESULT_OK,
                        null,
                        $gwProvider,
                        $correlationId,
                        ['exit_kind' => 'EXIT_RULE', 'worker_id' => $ws->workerId],
                        $ws->id
                    );
                } catch (\Throwable $e) {
                    error_log('[ExitActionService] Failed to write WORKER_EXIT event: ' . $e->getMessage());
                }
            }
        }

        // a) Transition stay OCCUPIED → EXITED
        $this->stateMachine->exitDetected($stay);

        // b) Lock the door
        $lockResult = $gateway->lock($room->id);

        // c) Write AUTO_LOCK access_event
        try {
            $this->accessEvents->insert(
                $room->id, $stay->id,
                AccessEvent::KIND_AUTO_LOCK,
                $lockResult['ok'] ? AccessEvent::RESULT_OK : AccessEvent::RESULT_FAIL,
                $lockResult['ok'] ? null : ($lockResult['error'] ?? 'gateway_error'),
                $lockResult['provider'],
                $correlationId,
                ['source' => 'exit_rule']
            );
        } catch (\Throwable $e) {
            error_log('[ExitActionService] Failed to write AUTO_LOCK event: ' . $e->getMessage());
        }

        // d) Set room cooldown (anti-reentry)
        $cooldownSecs = $roomType !== null ? $roomType->reentryCooldownSeconds : 20;
        $cooldownUntil = $now->modify("+{$cooldownSecs} seconds")->format('Y-m-d H:i:s.v');
        try {
            $this->rooms->update($room->id, ['cooldown_until' => $cooldownUntil]);
        } catch (\Throwable $e) {
            error_log('[ExitActionService] Failed to set room cooldown: ' . $e->getMessage());
        }

        // e) F28: Set room status → FREE
        try {
            $this->rooms->update($room->id, ['status' => Room::STATUS_FREE]);
        } catch (\Throwable $e) {
            error_log('[ExitActionService] Failed to set room FREE: ' . $e->getMessage());
        }

        // f) Turn off room light via Smart Switch (best-effort)
        if ($this->switchService !== null) {
            try {
                $this->switchService->turnOff($room->id);
            } catch (\Throwable $e) {
                error_log('[ExitActionService] Switch turnOff failed (best-effort): ' . $e->getMessage());
            }
        }

        // g) F35: Check for A5 anomaly (exited without door-open)
        if ($this->anomalyService !== null) {
            try {
                $this->anomalyService->checkA5AndPersist(
                    $session, $stay,
                    $stay->exitDetectedAt ?? $now->format('Y-m-d H:i:s.v')
                );
            } catch (\Throwable $e) {
                error_log('[ExitActionService] A5 anomaly check failed (best-effort): ' . $e->getMessage());
            }
        }
    }

    private function safeRollback(): void
    {
        try {
            $this->iotSessions->rollBack();
        } catch (\Throwable $e) {
            error_log('[ExitActionService] rollback failed: ' . $e->getMessage());
        }
    }

    private function isRetryableDeadlock(PDOException $e): bool
    {
        $code = (string) $e->getCode();
        $msg  = $e->getMessage();
        return $code === '40001'
            || str_contains($msg, '1213')
            || str_contains($msg, '1205')
            || str_contains($msg, 'Deadlock')
            || str_contains($msg, 'Lock wait timeout');
    }
}
