<?php
declare(strict_types=1);

namespace App\Domain\Stays;

use App\Support\Clock;
use App\Support\Errors\ConflictException;

/**
 * StayStateMachine: guarded transitions for a Stay.
 *
 * Each public method corresponds to a meaningful business event. Services
 * that drive the lifecycle call these methods; the machine validates the
 * source state and emits the required side effects via the StayRepository.
 *
 * Transitions (design.md §6.2):
 *   RESERVED                                --firstEntry()-->       OCCUPIED
 *   OCCUPIED                                --exitDetected()-->     EXITED
 *   {OCCUPIED, EXITED}                      --overstayDetected()--> OVERSTAY
 *   {EXITED, OVERSTAY, OCCUPIED, RESERVED}  --close()-->            CLOSED
 *   RESERVED                                --cancel()-->           CANCELED
 *
 * Any violation throws ConflictException(stay_wrong_state) so controllers
 * surface it as 409.
 *
 * Note: this service does NOT perform the lateral effects that the design
 * pairs with some transitions (open/lock, outbox enqueue, audit). Those will
 * be orchestrated by higher-level services (QrValidateService, ExitRule
 * Evaluator, DebtsService) in later tasks. Keeping the state machine focused
 * on the stay row simplifies unit testing.
 */
final class StayStateMachine
{
    private StayRepositoryInterface $repo;

    public function __construct(StayRepositoryInterface $repo)
    {
        $this->repo = $repo;
    }

    /**
     * RESERVED -> OCCUPIED. Sets first_entry_at if not already set.
     */
    public function firstEntry(Stay $stay): Stay
    {
        if ($stay->status !== Stay::STATUS_RESERVED) {
            throw $this->wrongState($stay, Stay::STATUS_OCCUPIED);
        }
        $nowUtc = Clock::nowUtc()->format('Y-m-d H:i:s.v');
        $rows = $this->repo->update($stay->id, [
            'status' => Stay::STATUS_OCCUPIED,
            'first_entry_at' => $nowUtc,
        ], Stay::STATUS_RESERVED);
        if ($rows === 0) {
            throw new ConflictException('stay_wrong_state', 'Stay state changed concurrently', ['stay_id' => $stay->id]);
        }
        $stay->status = Stay::STATUS_OCCUPIED;
        $stay->firstEntryAt = $nowUtc;
        return $stay;
    }

    /**
     * OCCUPIED -> EXITED. Records exit_detected_at.
     */
    public function exitDetected(Stay $stay): Stay
    {
        if ($stay->status !== Stay::STATUS_OCCUPIED) {
            throw $this->wrongState($stay, Stay::STATUS_EXITED);
        }
        $nowUtc = Clock::nowUtc()->format('Y-m-d H:i:s.v');
        $rows = $this->repo->update($stay->id, [
            'status' => Stay::STATUS_EXITED,
            'exit_detected_at' => $nowUtc,
        ], Stay::STATUS_OCCUPIED);
        if ($rows === 0) {
            throw new ConflictException('stay_wrong_state', 'Stay state changed concurrently', ['stay_id' => $stay->id]);
        }
        $stay->status = Stay::STATUS_EXITED;
        $stay->exitDetectedAt = $nowUtc;
        return $stay;
    }

    /**
     * {OCCUPIED, EXITED} -> OVERSTAY. Idempotent: if already OVERSTAY just
     * returns the stay unchanged.
     *
     * Rationale: the overstay scan job may fire this method repeatedly; we
     * want it to be a no-op on the second call rather than an error.
     */
    public function overstayDetected(Stay $stay): Stay
    {
        if ($stay->status === Stay::STATUS_OVERSTAY) {
            return $stay;
        }
        if (!in_array($stay->status, [Stay::STATUS_OCCUPIED, Stay::STATUS_EXITED], true)) {
            throw $this->wrongState($stay, Stay::STATUS_OVERSTAY);
        }
        $this->repo->update($stay->id, ['status' => Stay::STATUS_OVERSTAY]);
        $stay->status = Stay::STATUS_OVERSTAY;
        return $stay;
    }

    /**
     * Close a stay (checkout or terminal closure).
     * Accepts the stay from OCCUPIED, EXITED, OVERSTAY, and RESERVED (the
     * last one becomes a "closed without ever starting" case, e.g. aborted
     * just before/after checkout but not canceled).
     */
    public function close(Stay $stay): Stay
    {
        if ($stay->isTerminal()) {
            throw $this->wrongState($stay, Stay::STATUS_CLOSED);
        }
        $nowUtc = Clock::nowUtc()->format('Y-m-d H:i:s.v');
        $this->repo->update($stay->id, [
            'status' => Stay::STATUS_CLOSED,
            'closed_at' => $nowUtc,
        ]);
        $stay->status = Stay::STATUS_CLOSED;
        $stay->closedAt = $nowUtc;
        return $stay;
    }

    /**
     * RESERVED -> CANCELED. Used when the stay must be discarded before any
     * use (e.g. payment refunded, QR never used and expired).
     */
    public function cancel(Stay $stay): Stay
    {
        if ($stay->status !== Stay::STATUS_RESERVED) {
            throw $this->wrongState($stay, Stay::STATUS_CANCELED);
        }
        $nowUtc = Clock::nowUtc()->format('Y-m-d H:i:s.v');
        $rows = $this->repo->update($stay->id, [
            'status' => Stay::STATUS_CANCELED,
            'closed_at' => $nowUtc,
        ], Stay::STATUS_RESERVED);
        if ($rows === 0) {
            throw new ConflictException('stay_wrong_state', 'Stay state changed concurrently', ['stay_id' => $stay->id]);
        }
        $stay->status = Stay::STATUS_CANCELED;
        $stay->closedAt = $nowUtc;
        return $stay;
    }

    private function wrongState(Stay $stay, string $target): ConflictException
    {
        return new ConflictException(
            'stay_wrong_state',
            'Transition not allowed from current stay status',
            ['from' => $stay->status, 'to' => $target, 'stay_id' => $stay->id]
        );
    }
}
