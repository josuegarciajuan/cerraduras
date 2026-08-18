<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use App\Domain\Devices\SwitchService;
use App\Domain\Locks\AccessEvent;
use App\Domain\Locks\AccessEventRepositoryInterface;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Domain\Rooms\RoomTypeRepositoryInterface;
use App\Domain\Stays\Stay;
use App\Domain\Stays\StayRepositoryInterface;
use App\Domain\Stays\StayStateMachine;
use App\Domain\Workers\WorkerSessionRepositoryInterface;
use App\Infrastructure\Gateways\Lock\LockGatewayFactory;
use App\Domain\Anomalies\AnomalyService;
use App\Support\Clock;
use App\Support\Errors\NotFoundException;

/**
 * IotSessionService: processes sensor events and maintains derived IoT state.
 *
 * For each incoming presence event it:
 *   1. Inserts the raw event into presence_events (idempotent on source_event_id).
 *   2. Loads (or lazily creates) the iot_sessions row for the room.
 *   3. Updates door_state / presence_state and their timestamps.
 *   4. Delegates exit-rule evaluation to ExitRuleEvaluator (TSK-100).
 *   5. If exit confirmed: transitions stay OCCUPIED→EXITED, fires lock(),
 *      sets room.cooldown_until, writes ACCESS_EVENT(AUTO_LOCK).
 *
 * See RF-5, RF-7, TSK-091, TSK-101.
 */
final class IotSessionService
{
    private PresenceEventRepositoryInterface $presenceEvents;

    private IotSessionRepositoryInterface    $iotSessions;
    private RoomRepositoryInterface          $rooms;
    private RoomTypeRepositoryInterface      $roomTypes;
    private StayRepositoryInterface          $stays;
    private StayStateMachine                 $stateMachine;
    private AccessEventRepositoryInterface   $accessEvents;
    private ExitRuleEvaluator                $exitEvaluator;
    private ?SwitchService                   $switchService;
    private ?ExitActionService               $exitActionService;
    private ?AnomalyService                  $anomalyService;
    private ?WorkerSessionRepositoryInterface $workerSessionRepo;

    public function __construct(
        PresenceEventRepositoryInterface $presenceEvents,
        IotSessionRepositoryInterface    $iotSessions,
        RoomRepositoryInterface          $rooms,
        RoomTypeRepositoryInterface      $roomTypes,
        StayRepositoryInterface          $stays,
        StayStateMachine                 $stateMachine,
        AccessEventRepositoryInterface   $accessEvents,
        ExitRuleEvaluator                $exitEvaluator,
        ?SwitchService                   $switchService = null,
        ?ExitActionService               $exitActionService = null,
        ?AnomalyService                  $anomalyService = null,
        ?WorkerSessionRepositoryInterface $workerSessionRepo = null
    ) {
        $this->presenceEvents = $presenceEvents;
        $this->iotSessions    = $iotSessions;
        $this->rooms          = $rooms;
        $this->roomTypes      = $roomTypes;
        $this->stays          = $stays;
        $this->stateMachine   = $stateMachine;
        $this->accessEvents   = $accessEvents;
        $this->exitEvaluator    = $exitEvaluator;
        $this->switchService    = $switchService;
        $this->exitActionService = $exitActionService;
        $this->anomalyService    = $anomalyService;
        $this->workerSessionRepo = $workerSessionRepo;
    }

    /**
     * Process one normalised sensor event (output of SensorIngress::normalize()).
     *
     * @param array{
     *   room_id: int,
     *   sensor: string,
     *   value: string,
     *   provider: string,
     *   occurred_at: string,
     *   source_event_id: string|null,
     *   meta: array<string,mixed>|null
     * } $event
     *
     * @return array{accepted: bool, derived_state: array<string,mixed>}
     */
    public function processEvent(array $event, string $correlationId): array
    {
        $roomId       = (int)    $event['room_id'];
        $sensor       = (string) $event['sensor'];
        $value        = (string) $event['value'];
        $provider     = (string) $event['provider'];
        $occurredAt   = (string) $event['occurred_at'];
        $sourceEvtId  = $event['source_event_id'] ?? null;
        $meta         = $event['meta'] ?? null;

        // --- 1. Verify room ---
        $room = $this->rooms->findById($roomId);
        if ($room === null) {
            throw new NotFoundException('Room not found', ['room_id' => $roomId]);
        }

        // --- 2. Persist raw event (returns null on duplicate source_event_id) ---
        $inserted = $this->presenceEvents->insert(
            $roomId, $sensor, $value, $provider, $occurredAt, $sourceEvtId, $meta
        );

        // --- 3. Load (or create) the iot_session for this room ---
        $session = $this->iotSessions->findByRoomId($roomId);
        if ($session === null) {
            $session = new IotSession(
                0, $roomId, null,
                IotSession::DOOR_UNKNOWN,
                IotSession::PRESENCE_UNKNOWN,
                null, null, null, null, ''
            );
        }

        // If duplicate event, skip state mutation but return current state.
        if ($inserted === null) {
            return ['accepted' => true, 'derived_state' => $session->toArray()];
        }

        // --- 4. Update session state ---
        $now    = Clock::nowUtc();
        $nowTs  = $now->getTimestamp();
        // Canonical UTC string from the event's occurred_at
        $evtUtc = $this->isoToMysqlUtc($occurredAt);

        $activeStay = $this->stays->findActiveForRoom($roomId);
        $session->stayId = $activeStay !== null ? $activeStay->id : null;

        if ($sensor === PresenceEvent::SENSOR_PROXIMITY) {
            if ($value === PresenceEvent::VALUE_OPEN) {
                $session->doorState  = IotSession::DOOR_OPEN;
                $session->lastOpenAt = $evtUtc;

                // RF-16.2: Turn on room light when door physically opens (best-effort).
                if ($this->switchService !== null) {
                    try {
                        $this->switchService->turnOn($roomId);
                    } catch (\Throwable $e) {
                        error_log('[IotSessionService] Switch turnOn on door open failed (best-effort): ' . $e->getMessage());
                    }
                }
            } elseif ($value === PresenceEvent::VALUE_CLOSED) {
                $session->doorState = IotSession::DOOR_CLOSED;
                $session->lastCloseAt = $evtUtc; // F31: anchor exit rule to close time
                // RF-30: Start absence timer when door closes and no presence is detected.
                // This is necessary because the presence poller only forwards transitions;
                // if the sensor was already ABSENT, no ABSENT event arrives, and
                // lastAbsentSince would remain null, preventing the countdown.
                if ($session->presenceState === IotSession::PRESENCE_ABSENT
                    && $session->lastAbsentSince === null
                ) {
                    $session->lastAbsentSince = $evtUtc;
                }

                // F38 W14 Escenario B: Worker exit by door event.
                // If presence is still detected (guest is inside), but the door
                // just closed (worker left), close the most recent worker session.
                if ($this->workerSessionRepo !== null
                    && $session->presenceState === IotSession::PRESENCE_PRESENT
                ) {
                    $activeWorkerSessions = $this->workerSessionRepo->findActiveForRoom($roomId);
                    if (!empty($activeWorkerSessions)) {
                        $latest = $activeWorkerSessions[count($activeWorkerSessions) - 1];
                        $this->workerSessionRepo->close($latest->id, 'DOOR_EVENT', $evtUtc);
                        try {
                            $gw = LockGatewayFactory::make($room);
                            $this->accessEvents->insert(
                                $roomId, null,
                                AccessEvent::KIND_WORKER_EXIT,
                                AccessEvent::RESULT_OK,
                                null,
                                $gw->getProvider(),
                                $correlationId,
                                ['exit_kind' => 'DOOR_EVENT', 'worker_id' => $latest->workerId],
                                $latest->id
                            );
                        } catch (\Throwable $e) {
                            error_log('[IotSessionService] Failed to write WORKER_EXIT (door event): ' . $e->getMessage());
                        }
                    }
                }
            }
        } elseif ($sensor === PresenceEvent::SENSOR_PRESENCE) {
            if ($value === PresenceEvent::VALUE_PRESENT) {
                $session->presenceState   = IotSession::PRESENCE_PRESENT;
                $session->lastAbsentSince = null; // clear absence timer
            } elseif ($value === PresenceEvent::VALUE_ABSENT) {
                if ($session->presenceState !== IotSession::PRESENCE_ABSENT) {
                    // Transition to absent: record the start time
                    $session->lastAbsentSince = $evtUtc;
                }
                $session->presenceState = IotSession::PRESENCE_ABSENT;
            }
        }

        // --- 5. Persist updated session ---
        $this->iotSessions->upsert($session);

        // --- 5.5 F35: Anomaly detection (non-blocking) ---
        if ($this->anomalyService !== null) {
            try {
                $triggerEvent = new PresenceEvent(
                    0, $roomId, $sensor, $value, $provider,
                    $occurredAt, $now->format('Y-m-d H:i:s.v'),
                    $sourceEvtId, $meta
                );
                $this->anomalyService->detectAndPersist($session, $triggerEvent, $activeStay);
            } catch (\Throwable $e) {
                error_log('[IotSessionService] Anomaly detection failed (best-effort): ' . $e->getMessage());
            }
        }

        // --- 6. Evaluate exit rule (delegated to ExitRuleEvaluator, TSK-100) ---
        // RF-30: use room-level presence_check_seconds override if set
        $roomType  = $this->roomTypes->findById($room->roomTypeId);
        $gapSecs = $room->presenceCheckSeconds
            ?? ($roomType !== null ? $roomType->exitPresenceGapSeconds : 15);
        $exitFired = $this->exitEvaluator->evaluate($session, $gapSecs, $nowTs);

        if ($exitFired
            && $activeStay !== null
            && $activeStay->status === Stay::STATUS_OCCUPIED
        ) {
            if ($this->exitActionService !== null) {
                $this->exitActionService->execute($room, $session, $activeStay, $roomType, $correlationId, $now);
            } else {
                $this->handleAutoExit($room, $session, $activeStay, $roomType, $correlationId, $now);
            }
        } elseif ($exitFired && $this->workerSessionRepo !== null) {
            // F38 W14 Escenario A: Exit rule fired but no stay OCCUPIED.
            // Only workers were inside — close their sessions + lock + cooldown.
            $activeWorkerSessions = $this->workerSessionRepo->findActiveForRoom($roomId);
            if (!empty($activeWorkerSessions)) {
                $nowStr = $now->format('Y-m-d H:i:s.v');
                $gateway = LockGatewayFactory::make($room);
                foreach ($activeWorkerSessions as $ws) {
                    $this->workerSessionRepo->close($ws->id, 'EXIT_RULE', $nowStr);
                    try {
                        $this->accessEvents->insert(
                            $roomId, null,
                            AccessEvent::KIND_WORKER_EXIT,
                            AccessEvent::RESULT_OK,
                            null,
                            $gateway->getProvider(),
                            $correlationId,
                            ['exit_kind' => 'EXIT_RULE', 'worker_id' => $ws->workerId],
                            $ws->id
                        );
                    } catch (\Throwable $e) {
                        error_log('[IotSessionService] Failed to write WORKER_EXIT (exit rule): ' . $e->getMessage());
                    }
                }

                // Lock + cooldown (same as auto exit but without stay transition)
                $lockResult = $gateway->lock($roomId);
                try {
                    $this->accessEvents->insert(
                        $roomId, null,
                        AccessEvent::KIND_AUTO_LOCK,
                        $lockResult['ok'] ? AccessEvent::RESULT_OK : AccessEvent::RESULT_FAIL,
                        $lockResult['ok'] ? null : ($lockResult['error'] ?? 'gateway_error'),
                        $lockResult['provider'],
                        $correlationId,
                        ['source' => 'exit_rule_workers_only']
                    );
                } catch (\Throwable $e) {
                    error_log('[IotSessionService] Failed to write AUTO_LOCK (workers-only): ' . $e->getMessage());
                }

                $cooldownSecs = $roomType !== null ? $roomType->reentryCooldownSeconds : 20;
                $cooldownUntil = $now->modify("+{$cooldownSecs} seconds")->format('Y-m-d H:i:s.v');
                try {
                    $this->rooms->update($roomId, ['cooldown_until' => $cooldownUntil]);
                } catch (\Throwable $e) {
                    error_log('[IotSessionService] Failed to set cooldown (workers-only): ' . $e->getMessage());
                }

                // Turn off light
                if ($this->switchService !== null) {
                    try {
                        $this->switchService->turnOff($roomId);
                    } catch (\Throwable $e) {
                        error_log('[IotSessionService] Switch turnOff failed: ' . $e->getMessage());
                    }
                }

                // Mark exit evaluated
                $session->exitEvaluatedAt = $nowStr;
                $this->iotSessions->upsert($session);
            }
        }

        return ['accepted' => true, 'derived_state' => $session->toArray()];
    }

    /**
     * Return the current derived state (latest iot_session) and recent events.
     * Throws NotFoundException if the room does not exist.
     *
     * @return array<string,mixed>
     */
    public function getPresenceState(int $roomId, int $eventLimit = 20): array
    {
        if ($this->rooms->findById($roomId) === null) {
            throw new NotFoundException('Room not found', ['room_id' => $roomId]);
        }
        $session = $this->iotSessions->findByRoomId($roomId);
        $events  = $this->presenceEvents->listForRoom($roomId, $eventLimit);

        return [
            'room_id'       => $roomId,
            'derived_state' => $session !== null ? $session->toArray() : [
                'door_state'        => IotSession::DOOR_UNKNOWN,
                'presence_state'    => IotSession::PRESENCE_UNKNOWN,
                'last_open_at'      => null,
                'last_absent_since' => null,
            ],
            'recent_events' => array_map(static function (PresenceEvent $e): array {
                return [
                    'id'              => $e->id,
                    'sensor'          => $e->sensor,
                    'value'           => $e->value,
                    'provider'        => $e->provider,
                    'occurred_at'     => $e->occurredAt,
                    'source_event_id' => $e->sourceEventId,
                ];
            }, $events),
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    /**
     * Execute all side-effects when the exit rule fires.
     */
    private function handleAutoExit(
        \App\Domain\Rooms\Room    $room,
        IotSession                $session,
        \App\Domain\Stays\Stay    $stay,
        ?\App\Domain\Rooms\RoomType $roomType,
        string                    $correlationId,
        \DateTimeImmutable        $now
    ): void {
        // a) Transition stay OCCUPIED → EXITED
        $this->stateMachine->exitDetected($stay);

        // b) Lock the door
        $gateway    = LockGatewayFactory::make($room);
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
            error_log('[IotSessionService] Failed to write AUTO_LOCK event: ' . $e->getMessage());
        }

        // d) Set room cooldown
        $cooldownSecs = $roomType !== null ? $roomType->reentryCooldownSeconds : 20;
        $cooldownUntil = $now->modify("+{$cooldownSecs} seconds")->format('Y-m-d H:i:s.v');
        try {
            $this->rooms->update($room->id, ['cooldown_until' => $cooldownUntil]);
        } catch (\Throwable $e) {
            error_log('[IotSessionService] Failed to set room cooldown: ' . $e->getMessage());
        }

        // e) Mark exit_evaluated_at in session
        $session->exitEvaluatedAt = $now->format('Y-m-d H:i:s.v');
        $this->iotSessions->upsert($session);

        // f) Turn off room light via Smart Switch (best-effort, RF-16.3)
        if ($this->switchService !== null) {
            try {
                $this->switchService->turnOff($room->id);
            } catch (\Throwable $e) {
                error_log('[IotSessionService] Switch turnOff failed (best-effort): ' . $e->getMessage());
            }
        }
    }

    /**
     * Convert an ISO-8601 timestamp (any offset) to a MySQL UTC DATETIME(3)
     * string. Tolerates various formats including bare UTC.
     */
    private function isoToMysqlUtc(string $iso): string
    {
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $iso)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $iso)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $iso);

        if ($dt === false) {
            $ts = strtotime($iso);
            return $ts === false
                ? Clock::nowUtc()->format('Y-m-d H:i:s.v')
                : gmdate('Y-m-d H:i:s', $ts) . '.000';
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
