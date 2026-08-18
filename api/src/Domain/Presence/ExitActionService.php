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
use App\Domain\Stays\Stay;
use App\Domain\Stays\StayStateMachine;
use App\Domain\Workers\WorkerSessionRepositoryInterface;
use App\Infrastructure\Gateways\Lock\LockGatewayFactory;

/**
 * ExitActionService: executes all side-effects when the exit rule fires.
 *
 * Called from two places:
 *   1. IotSessionService::processEvent() — on incoming sensor events.
 *   2. bin/exit-scan.php — periodic background evaluation (F28).
 *
 * Side-effects (design.md §8.2, F28):
 *   a) Transition stay OCCUPIED → EXITED (StayStateMachine).
 *   b) Lock the door (LockGateway).
 *   c) Write AUTO_LOCK access_event.
 *   d) Set room cooldown (anti-reentry).
 *   e) Set room status → FREE (F28: room is free after confirmed exit).
 *   f) Turn off room light (best-effort).
 *   g) Mark exit_evaluated_at in iot_sessions.
 */
final class ExitActionService
{
    private RoomRepositoryInterface        $rooms;
    private IotSessionRepositoryInterface  $iotSessions;
    private StayStateMachine               $stateMachine;
    private AccessEventRepositoryInterface $accessEvents;
    private ?SwitchService                 $switchService;
    private ?AnomalyService                $anomalyService;
    private ?WorkerSessionRepositoryInterface $workerSessionRepo;

    public function __construct(
        RoomRepositoryInterface        $rooms,
        IotSessionRepositoryInterface  $iotSessions,
        StayStateMachine               $stateMachine,
        AccessEventRepositoryInterface $accessEvents,
        ?SwitchService                 $switchService = null,
        ?AnomalyService                $anomalyService = null,
        ?WorkerSessionRepositoryInterface $workerSessionRepo = null
    ) {
        $this->rooms         = $rooms;
        $this->iotSessions   = $iotSessions;
        $this->stateMachine  = $stateMachine;
        $this->accessEvents  = $accessEvents;
        $this->switchService = $switchService;
        $this->anomalyService = $anomalyService;
        $this->workerSessionRepo = $workerSessionRepo;
    }

    /**
     * Execute all side-effects when the exit rule fires for a room.
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
        $now = $now ?? \App\Support\Clock::nowUtc();
        $nowStr = $now->format('Y-m-d H:i:s.v');

        // F38 W13: Close active worker sessions for this room BEFORE processing the stay
        $gateway = LockGatewayFactory::make($room);
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
                        $ws->id     // worker_session_id
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

        // e) F28: Set room status → FREE (room is free after confirmed exit)
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

        // g) Mark exit_evaluated_at in session
        $session->exitEvaluatedAt = $now->format('Y-m-d H:i:s.v');
        $this->iotSessions->upsert($session);

        // h) F35: Check for A5 anomaly (exited without door-open)
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
}
