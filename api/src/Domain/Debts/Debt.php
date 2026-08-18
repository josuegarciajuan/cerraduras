<?php
declare(strict_types=1);

namespace App\Domain\Debts;

/**
 * Debt: local record of an overstay charge pending sync to VB6.
 *
 * Lifecycle:
 *   PENDING_SYNC → (outbox worker sends to WS-VB6) → SYNCED
 *               → (on failure)                      → FAILED
 *   FAILED can be retried via POST /debts/{id}/resync.
 *
 * amount_eur_snapshot is NULL until WS-VB6 confirms with the computed amount.
 *
 * See design.md §4.11, contracts.md §2.8.
 */
final class Debt
{
    public const STATUS_PENDING_SYNC = 'PENDING_SYNC';
    public const STATUS_SYNCING      = 'SYNCING';
    public const STATUS_SYNCED       = 'SYNCED';
    public const STATUS_FAILED       = 'FAILED';

    public int $id;
    public int $stayId;
    public int $roomId;
    public int $excesoMinutos;
    public ?int $amountEurSnapshot;
    public string $status;
    public int $attempts;
    public ?string $lastError;
    public ?string $vb6AckRef;
    public string $createdAt;
    public string $updatedAt;

    public function __construct(
        int     $id,
        int     $stayId,
        int     $roomId,
        int     $excesoMinutos,
        ?int    $amountEurSnapshot,
        string  $status,
        int     $attempts,
        ?string $lastError,
        ?string $vb6AckRef,
        string  $createdAt,
        string  $updatedAt
    ) {
        $this->id                = $id;
        $this->stayId            = $stayId;
        $this->roomId            = $roomId;
        $this->excesoMinutos     = $excesoMinutos;
        $this->amountEurSnapshot = $amountEurSnapshot;
        $this->status            = $status;
        $this->attempts          = $attempts;
        $this->lastError         = $lastError;
        $this->vb6AckRef         = $vb6AckRef;
        $this->createdAt         = $createdAt;
        $this->updatedAt         = $updatedAt;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'stay_id'             => $this->stayId,
            'room_id'             => $this->roomId,
            'exceso_minutos'      => $this->excesoMinutos,
            'amount_eur_snapshot' => $this->amountEurSnapshot,
            'status'              => $this->status,
            'attempts'            => $this->attempts,
            'last_error'          => $this->lastError,
            'vb6_ack_ref'         => $this->vb6AckRef,
            'created_at'          => $this->createdAt,
            'updated_at'          => $this->updatedAt,
        ];
    }
}
