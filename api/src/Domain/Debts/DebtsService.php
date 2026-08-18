<?php
declare(strict_types=1);

namespace App\Domain\Debts;

use App\Domain\Rooms\RoomType;
use App\Domain\Stays\Stay;
use App\Domain\Stays\StayRepositoryInterface;
use App\Domain\Stays\StayStateMachine;
use App\Support\Clock;

/**
 * DebtsService: creates and manages overstay debt records (RF-8).
 *
 * Responsibilities:
 *   - createIfNeeded(): idempotent debt creation per stay.
 *     • Runs OverstayCalculator.
 *     • If overstay threshold crossed AND no active debt exists → insert debt.
 *     • Transitions stay → OVERSTAY if status ∈ {OCCUPIED, EXITED} (TSK-115).
 *     • Enqueues two outbox entries (P3):
 *         - 'debt.created'  → POST /ws-vb6/v1/debts
 *         - 'stay.overstay' → POST /ws-vb6/v1/stays/events
 *   - rescheduleDebt(): marks PENDING_SYNC debts for immediate retry.
 *
 * See design.md §3.4, §13; contracts.md §2.8; RF-8; TSK-111, TSK-115.
 */
final class DebtsService
{
    private DebtRepositoryInterface $debts;
    private StayRepositoryInterface $stays;
    private StayStateMachine        $stateMachine;
    private OverstayCalculator      $calculator;
    private OutboxVb6RepositoryInterface $outbox;
    private ?\PDO $pdo;

    public function __construct(
        DebtRepositoryInterface      $debts,
        StayRepositoryInterface      $stays,
        StayStateMachine             $stateMachine,
        OverstayCalculator           $calculator,
        OutboxVb6RepositoryInterface $outbox,
        ?\PDO $pdo = null
    ) {
        $this->debts        = $debts;
        $this->stays        = $stays;
        $this->stateMachine = $stateMachine;
        $this->calculator   = $calculator;
        $this->outbox       = $outbox;
        $this->pdo          = $pdo;
    }

    /**
     * Create a debt for the given stay if overstay conditions are met.
     *
     * Idempotent: calling twice for the same stay returns the existing debt
     * on the second call without creating a duplicate or re-enqueueing.
     *
     * Returns the debt (new or existing) if overstay; null if no overstay.
     */
    public function createIfNeeded(Stay $stay, RoomType $roomType): ?Debt
    {
        $result = $this->calculator->calculate($stay, $roomType->graceMinutes);

        if (!$result['overstay']) {
            return null;
        }

        // Serialise concurrent debt creation for the same stay using a
        // row-level lock (SELECT … FOR UPDATE) inside a transaction.
        // When PDO is not available (unit tests with fake repos), the
        // lock is skipped — tests are single-threaded by design.
        $hasPdo = $this->pdo !== null;
        if ($hasPdo) {
            $this->pdo->beginTransaction();
        }

        try {
            if ($hasPdo) {
                $lock = $this->pdo->prepare("SELECT id FROM stays WHERE id = :id FOR UPDATE");
                $lock->execute([':id' => $stay->id]);
            }

            // Idempotency: do not create a second debt if one already exists.
            $existing = $this->debts->findActiveForStay($stay->id);
            if ($existing !== null) {
                if ($hasPdo) { $this->pdo->commit(); }
                return $existing;
            }

            $excesoMinutos = $result['exceso_minutos'];

            // Insert debt.
            $debtId = $this->debts->insert($stay->id, $stay->roomId, $excesoMinutos);
            $debt   = $this->debts->findById($debtId);

            // TSK-115: transition stay → OVERSTAY if not already.
            if (in_array($stay->status, [Stay::STATUS_OCCUPIED, Stay::STATUS_EXITED], true)) {
                try {
                    $this->stateMachine->overstayDetected($stay);
                } catch (\Throwable $e) {
                    // overstayDetected() is idempotent; errors here are non-fatal.
                    error_log('[DebtsService] stay→OVERSTAY transition error: ' . $e->getMessage());
                }
            }

            // Enqueue outbox entries (P3 — two separate topics).
            $now       = Clock::nowUtc()->format('Y-m-d\TH:i:s\Z');
            $vb6Refs   = $this->extractVb6Refs($stay);

            // Topic 1: debt.created → POST /ws-vb6/v1/debts
            $this->outbox->enqueue(
                'debt.created',
                [
                    'debt_id'        => $debtId,
                    'occurred_at'    => $now,
                    'exceso_minutos' => $excesoMinutos,
                    'vb6_refs'       => $vb6Refs,
                ],
                "debt-created-{$debtId}"
            );

            // Topic 2: stay.overstay → POST /ws-vb6/v1/stays/events
            $this->outbox->enqueue(
                'stay.overstay',
                [
                    'topic'       => 'stay.overstay',
                    'api_stay_id' => $stay->id,
                    'occurred_at' => $now,
                    'vb6_refs'    => $vb6Refs,
                    'payload'     => ['exceso_minutos' => $excesoMinutos],
                ],
                "stay-overstay-{$stay->id}"
            );

            if ($hasPdo) { $this->pdo->commit(); }

            return $debt;
        } catch (\Throwable $e) {
            if ($hasPdo) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    /**
     * Reschedule a PENDING_SYNC or FAILED debt for immediate retry.
     * Returns the updated debt, or null if not found or not reschedulable.
     */
    public function rescheduleDebt(int $debtId): ?Debt
    {
        $debt = $this->debts->findById($debtId);
        if ($debt === null) {
            return null;
        }
        if (!in_array($debt->status, [Debt::STATUS_PENDING_SYNC, Debt::STATUS_FAILED], true)) {
            // SYNCED/SYNCING debts don't need rescheduling.
            return $debt;
        }

        // Reset status to PENDING_SYNC and schedule outbox for immediate retry.
        $this->debts->update($debtId, ['status' => Debt::STATUS_PENDING_SYNC]);
        $this->outbox->scheduleRetry("debt-created-{$debtId}");

        return $this->debts->findById($debtId);
    }

    /**
     * Extract VB6 reference fields from a stay into an associative array
     * suitable for outbox payloads.
     *
     * @return array<string,mixed>
     */
    private function extractVb6Refs(Stay $stay): array
    {
        return [
            'codalq'       => $stay->vb6Codalq,
            'codtic'       => $stay->vb6Codtic,
            'codcli'       => $stay->vb6Codcli,
            'codart'       => $stay->vb6Codart,
            'codlot'       => $stay->vb6Codlot,
            'codhab'       => $stay->vb6CodhabRaw,
            'temporada'    => $stay->vb6Temporada,
            'empresa'      => $stay->vb6Empresa,
            'departamento' => $stay->vb6Departamento,
        ];
    }
}
