<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use App\Domain\Devices\SwitchService;
use App\Domain\Locks\AccessEvent;
use App\Domain\Locks\AccessEventRepositoryInterface;
use App\Domain\Rooms\Room;
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
use PDOException;

/**
 * IotSessionService: single authoritative writer of the per-room IoT state.
 *
 * Fase 41 (RF-43/44): processEvent() is now
 *   audit (outside tx) → begin tx → lock row → decide → mutate → updateState
 *   → commit → post-commit effects (switch, anomalies, exit rule).
 *
 * No external I/O happens while the row is locked; the exit side-effects run
 * through ExitActionService::executeIfPending() after the commit.
 *
 * See design.md §1.3 and §2.4.
 */
final class IotSessionService
{
    /** Deadlock / lock-wait retry budget (design.md §1.4). */
    private const MAX_TX_RETRIES = 3;

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

        // --- 1. Verify room (cheap, before auditing) ---
        $room = $this->rooms->findById($roomId);
        if ($room === null) {
            throw new NotFoundException('Room not found', ['room_id' => $roomId]);
        }

        // --- 2. (A) Raw audit outside the state transaction (RF-44.3) ---
        $raw       = $this->presenceEvents->insertOrGet(
            $roomId, $sensor, $value, $provider, $occurredAt, $sourceEvtId, $meta
        );
        $rawEvent  = $raw['event'];
        $isNewFact = $raw['is_new'];

        $now    = Clock::nowUtc();
        $evtUtc = $this->isoToMysqlUtc($occurredAt);

        $session = null;
        $applied = false;

        // --- 3. (B/C) Atomic decide + mutate + update ---
        for ($attempt = 1; $attempt <= self::MAX_TX_RETRIES; $attempt++) {
            try {
                $this->iotSessions->beginTransaction();
                $session = $this->iotSessions->lockByRoomId($roomId);

                $decision = SensorEventDecision::decide($event, $session, !$isNewFact);

                if ($decision !== SensorEventDecision::APPLY) {
                    $this->presenceEvents->markAudit($rawEvent->id, false, $decision);
                    $this->iotSessions->commit();
                    break;
                }

                $activeStay = $this->stays->lockActiveForRoom($roomId);
                $session->stayId = $activeStay !== null ? $activeStay->id : null;

                $this->mutate($room, $session, $event, $evtUtc, $activeStay, $correlationId, $now);

                $this->iotSessions->updateState($session);
                $this->presenceEvents->markAudit($rawEvent->id, true, null);
                $this->iotSessions->commit();
                $applied = true;
                break;
            } catch (PDOException $e) {
                $this->safeRollback();
                if (!$this->isRetryableDeadlock($e) || $attempt >= self::MAX_TX_RETRIES) {
                    throw $e;
                }
                // Backoff + jitter (20 ms * attempt, capped).
                usleep(20_000 * $attempt + random_int(0, 10_000));
            } catch (\Throwable $e) {
                // Never leave the transaction open on an unexpected failure.
                $this->safeRollback();
                throw $e;
            }
        }

        // --- 4. (D) Post-commit effects (never under the row lock) ---
        if ($applied && $session !== null) {
            if ($sensor === PresenceEvent::SENSOR_PROXIMITY
                && $value === PresenceEvent::VALUE_OPEN
                && $this->switchService !== null
            ) {
                try {
                    $this->switchService->turnOn($roomId);
                } catch (\Throwable $e) {
                    error_log('[IotSessionService] Switch turnOn on door open failed (best-effort): ' . $e->getMessage());
                }
            }

            if ($this->anomalyService !== null) {
                try {
                    $triggerEvent = new PresenceEvent(
                        0, $roomId, $sensor, $value, $provider,
                        $occurredAt, $now->format('Y-m-d H:i:s.v'),
                        $sourceEvtId, $meta
                    );
                    $this->anomalyService->detectAndPersist(
                        $session, $triggerEvent, $this->stays->findActiveForRoom($roomId)
                    );
                } catch (\Throwable $e) {
                    error_log('[IotSessionService] Anomaly detection failed (best-effort): ' . $e->getMessage());
                }
            }

            if ($this->exitActionService !== null) {
                try {
                    $this->exitActionService->executeIfPending($roomId, $correlationId);
                } catch (\Throwable $e) {
                    error_log('[IotSessionService] Exit evaluation failed (best-effort): ' . $e->getMessage());
                }
            }
        }

        if ($session === null) {
            $session = $this->iotSessions->findByRoomId($roomId);
        }
        if ($session === null) {
            $session = new IotSession(
                0, $roomId, null,
                IotSession::DOOR_UNKNOWN,
                IotSession::PRESENCE_UNKNOWN,
                null, null, null, null, ''
            );
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
     * Pure mutation of the locked session snapshot for an APPLY decision.
     *
     * @param array<string,mixed> $event
     */
    private function mutate(
        Room     $room,
        IotSession $session,
        array    $event,
        string   $evtUtc,
        ?Stay    $activeStay,
        string   $correlationId,
        \DateTimeImmutable $now
    ): void {
        $sensor = (string) $event['sensor'];
        $value  = (string) $event['value'];

        if ($sensor === PresenceEvent::SENSOR_PROXIMITY) {
            $session->lastDoorEventAt = $evtUtc;
            $session->lastDoorValue   = $value;

            if ($value === PresenceEvent::VALUE_OPEN) {
                $session->doorState  = IotSession::DOOR_OPEN;
                $session->lastOpenAt = $evtUtc;
            } elseif ($value === PresenceEvent::VALUE_CLOSED) {
                $session->doorState   = IotSession::DOOR_CLOSED;
                $session->lastCloseAt = $evtUtc;

                // RF-30: start the absence timer when the door closes with no presence.
                if ($session->presenceState === IotSession::PRESENCE_ABSENT
                    && $session->lastAbsentSince === null
                ) {
                    $session->lastAbsentSince = $evtUtc;
                }

                // F41 (RF-46.2): consolidate entry when the door closes with
                // presence confirmed or seen during the opening.
                $this->consolidateEntry($session, $activeStay, $evtUtc);

                // F38 W14 Escenario B: worker exit by door event.
                $this->handleWorkerExitOnDoorClose($room, $session, $evtUtc, $correlationId, $now);
            }
        } elseif ($sensor === PresenceEvent::SENSOR_PRESENCE) {
            $session->lastPresenceEventAt = $evtUtc;
            $session->lastPresenceValue   = $value;

            if ($value === PresenceEvent::VALUE_PRESENT) {
                $session->presenceState   = IotSession::PRESENCE_PRESENT;
                $session->lastAbsentSince = null; // cancels the exit countdown (RF-47.3)
            } elseif ($value === PresenceEvent::VALUE_ABSENT) {
                if ($session->presenceState !== IotSession::PRESENCE_ABSENT) {
                    $session->lastAbsentSince = $evtUtc;
                }
                $session->presenceState = IotSession::PRESENCE_ABSENT;
            }
        }
    }

    /**
     * F41: set stays.entry_confirmed_at when a CLOSE follows an opening during
     * which presence was seen (contracts.md §1.2 / design.md §2.4).
     */
    private function consolidateEntry(IotSession $session, ?Stay $activeStay, string $evtUtc): void
    {
        if ($activeStay === null || $activeStay->entryConfirmedAt !== null) {
            return;
        }

        $seenDuringOpen = $session->presenceState === IotSession::PRESENCE_PRESENT;
        if (!$seenDuringOpen
            && $session->lastPresenceValue === PresenceEvent::VALUE_PRESENT
            && $session->lastPresenceEventAt !== null
            && $session->lastOpenAt !== null
        ) {
            $presenceTs = strtotime($session->lastPresenceEventAt . ' UTC');
            $openTs     = strtotime($session->lastOpenAt . ' UTC');
            $seenDuringOpen = $presenceTs !== false && $openTs !== false && $presenceTs >= $openTs;
        }

        if ($seenDuringOpen) {
            $activeStay->entryConfirmedAt = $evtUtc;
            $this->stays->update($activeStay->id, ['entry_confirmed_at' => $evtUtc]);
        }
    }

    /**
     * F38 W14 Escenario B: close the most recent worker session when the door
     * closes while presence is still detected (guest stayed inside, worker left).
     */
    private function handleWorkerExitOnDoorClose(
        Room     $room,
        IotSession $session,
        string   $evtUtc,
        string   $correlationId,
        \DateTimeImmutable $now
    ): void {
        if ($this->workerSessionRepo === null
            || $session->presenceState !== IotSession::PRESENCE_PRESENT
        ) {
            return;
        }

        $activeWorkerSessions = $this->workerSessionRepo->findActiveForRoom($room->id);
        if (empty($activeWorkerSessions)) {
            return;
        }

        $latest = $activeWorkerSessions[count($activeWorkerSessions) - 1];
        $this->workerSessionRepo->close($latest->id, 'DOOR_EVENT', $evtUtc);
        try {
            $gw = LockGatewayFactory::make($room);
            $this->accessEvents->insert(
                $room->id, null,
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

    private function safeRollback(): void
    {
        try {
            $this->iotSessions->rollBack();
        } catch (\Throwable $e) {
            error_log('[IotSessionService] rollback failed: ' . $e->getMessage());
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
