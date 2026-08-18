<?php
declare(strict_types=1);

namespace App\Domain\Anomalies;

use App\Domain\Anomalies\Detectors\ExitWithoutDoorOpen;
use App\Domain\Presence\IotSession;
use App\Domain\Stays\Stay;
use App\Support\Clock;

/**
 * AnomalyService: orchestrates anomaly detection, persistence, and lifecycle.
 *
 * Called from three entry points:
 *   1. IotSessionService::processEvent() — on each incoming sensor event.
 *   2. ExitActionService::execute()       — to check for A5 after exit fires.
 *   3. bin/anomaly-scanner.php           — periodic evaluation (A4, A7, autoDismiss).
 *
 * See design.md §5 (F35).
 */
final class AnomalyService
{
    private AnomalyPipeline       $pipeline;
    private AnomalyRepositoryInterface $repo;
    private ExitWithoutDoorOpen   $detectorA5;

    public function __construct(
        AnomalyPipeline             $pipeline,
        AnomalyRepositoryInterface  $repo,
        ExitWithoutDoorOpen         $detectorA5
    ) {
        $this->pipeline   = $pipeline;
        $this->repo       = $repo;
        $this->detectorA5 = $detectorA5;
    }

    /**
     * Evaluate all detectors triggered by a sensor event and persist any
     * newly detected anomalies (with deduplication).
     *
     * Called from IotSessionService::processEvent().
     *
     * @return list<Anomaly> created anomalies (empty if none)
     */
    public function detectAndPersist(IotSession $session, \App\Domain\Presence\PresenceEvent $trigger, ?Stay $stay): array
    {
        $results = $this->pipeline->evaluate($session, $trigger, $stay);

        $created = [];
        $now     = Clock::nowUtc()->format('Y-m-d H:i:s.v');

        foreach ($results as $r) {
            // Deduplication: skip if an OPEN anomaly of this type already exists for the room.
            $existing = $this->repo->findOpenByRoomAndType($session->roomId, $r->type);
            if ($existing !== null) {
                continue;
            }

            $anomaly = new Anomaly(
                0,
                $session->roomId,
                $stay?->id,
                $r->type,
                $r->severity,
                Anomaly::STATUS_OPEN,
                $r->contextData,
                $now,
                null, null, null, null,
                $now, $now
            );

            $id = $this->repo->save($anomaly);
            $anomaly->id = $id;
            $created[] = $anomaly;
        }

        return $created;
    }

    /**
     * Check for A5 anomaly after an exit has been executed.
     *
     * Called from ExitActionService::execute().
     */
    public function checkA5AndPersist(
        IotSession $session,
        Stay       $stay,
        string     $exitDetectedAt
    ): ?Anomaly {
        $result = $this->detectorA5->check(
            $session, $stay,
            $exitDetectedAt,
            $session->lastOpenAt,
            $stay->firstEntryAt
        );

        if ($result === null) {
            return null;
        }

        // Deduplication
        $existing = $this->repo->findOpenByRoomAndType($session->roomId, $result->type);
        if ($existing !== null) {
            return null;
        }

        $now = Clock::nowUtc()->format('Y-m-d H:i:s.v');
        $anomaly = new Anomaly(
            0,
            $session->roomId,
            $stay->id,
            $result->type,
            $result->severity,
            Anomaly::STATUS_OPEN,
            $result->contextData,
            $now,
            null, null, null, null,
            $now, $now
        );

        $id = $this->repo->save($anomaly);
        $anomaly->id = $id;
        return $anomaly;
    }

    /**
     * Auto-resolve (dismiss) anomalies for a room whose triggering condition
     * is no longer present.
     *
     * Called from bin/anomaly-scanner.php periodically.
     *
     * @return int number of anomalies auto-resolved
     */
    public function autoResolveForRoom(int $roomId, IotSession $session, ?Stay $stay): int
    {
        // F37: include ACKNOWLEDGED in addition to OPEN so acknowledged
        // anomalies also get auto-dismissed when their condition clears.
        $resolvableAnomalies = $this->repo->findResolvableForRoom($roomId);
        $resolved = 0;
        $now = Clock::nowUtc()->format('Y-m-d H:i:s.v');

        foreach ($resolvableAnomalies as $anomaly) {
            $shouldResolve = $this->isConditionResolved($anomaly, $session, $stay);
            if ($shouldResolve) {
                $anomaly->autoDismiss($now);
                $this->repo->update($anomaly->id, [
                    'status'       => $anomaly->status,
                    'dismissed_at' => $anomaly->dismissedAt,
                    'dismissed_by' => $anomaly->dismissedBy,
                ]);
                $resolved++;
            }
        }

        return $resolved;
    }

    /**
     * Determine whether the condition that triggered an anomaly is no longer present.
     */
    private function isConditionResolved(Anomaly $anomaly, IotSession $session, ?Stay $stay): bool
    {
        return match ($anomaly->anomalyType) {
            Anomaly::TYPE_A1 => false, // A1 is event-driven, doesn't auto-resolve by state change alone
            Anomaly::TYPE_A2 => $session->presenceState === IotSession::PRESENCE_ABSENT
                || ($stay !== null && in_array($stay->status, [Stay::STATUS_OCCUPIED, Stay::STATUS_EXITED], true)),
            Anomaly::TYPE_A3 => $session->doorState === IotSession::DOOR_CLOSED
                || ($stay !== null && $stay->status === Stay::STATUS_OCCUPIED),
            Anomaly::TYPE_A4 => $session->presenceState === IotSession::PRESENCE_ABSENT,
            Anomaly::TYPE_A5 => false, // A5 is a historical event, doesn't auto-resolve
            Anomaly::TYPE_A6 => $session->presenceState === IotSession::PRESENCE_ABSENT,
            Anomaly::TYPE_A7 => $session->doorState === IotSession::DOOR_CLOSED
                || $session->presenceState === IotSession::PRESENCE_PRESENT,
            Anomaly::TYPE_A8 => false, // A8 requires a new count check, handled by the detector itself
            default => false,
        };
    }

    /**
     * Acknowledge an anomaly (OPEN → ACKNOWLEDGED).
     *
     * @throws \RuntimeException if not in OPEN status
     */
    public function acknowledge(int $anomalyId, string $actor): Anomaly
    {
        $anomaly = $this->repo->findById($anomalyId);
        if ($anomaly === null) {
            throw new \RuntimeException("Anomaly {$anomalyId} not found");
        }

        $now = Clock::nowUtc()->format('Y-m-d H:i:s.v');
        $anomaly->acknowledge($actor, $now);

        $this->repo->update($anomaly->id, [
            'status'          => $anomaly->status,
            'acknowledged_at' => $anomaly->acknowledgedAt,
            'acknowledged_by' => $anomaly->acknowledgedBy,
        ]);

        return $anomaly;
    }

    /**
     * Dismiss an anomaly (OPEN/ACKNOWLEDGED → DISMISSED).
     *
     * Used for manual dismissal of anomaly types that cannot auto-resolve
     * (A1, A5, A8) by an administrator.
     *
     * @throws \RuntimeException if anomaly not found or already DISMISSED
     */
    public function dismiss(int $anomalyId, string $actor): Anomaly
    {
        $anomaly = $this->repo->findById($anomalyId);
        if ($anomaly === null) {
            throw new \RuntimeException("Anomaly {$anomalyId} not found");
        }
        if ($anomaly->status === Anomaly::STATUS_DISMISSED) {
            throw new \RuntimeException("Anomaly {$anomalyId} is already DISMISSED");
        }

        $now = Clock::nowUtc()->format('Y-m-d H:i:s.v');
        $anomaly->autoDismiss($now);
        // Override autoDismiss's 'system' actor with the actual actor
        $anomaly->dismissedBy = $actor;

        $this->repo->update($anomaly->id, [
            'status'       => $anomaly->status,
            'dismissed_at' => $anomaly->dismissedAt,
            'dismissed_by' => $anomaly->dismissedBy,
        ]);

        return $anomaly;
    }

    /**
     * Find anomalies with optional filters (composition over repo).
     *
     * @param array{room_id?:int, anomaly_type?:string, severity?:string, status?:string} $filters
     * @return list<Anomaly>
     */
    public function findAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->repo->findAll($filters, $limit, $offset);
    }

    /**
     * Count anomalies matching filters.
     */
    public function count(array $filters = []): int
    {
        return $this->repo->count($filters);
    }
}
