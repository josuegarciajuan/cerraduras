<?php
declare(strict_types=1);

namespace App\Domain\Workers;

/**
 * WorkerSession: tracks a worker's entry into and exit from a room.
 *
 * Exit is detected by sensors (not QR), so exited_at may be null for
 * sessions still in progress.
 *
 * exit_kind values:
 *   QR_SCAN     — worker exited by scanning QR (future, not implemented)
 *   EXIT_RULE   — exit rule fired (absence sustained)
 *   DOOR_EVENT  — door closed while presence still detected
 *   AUTO        — system-forced closure (e.g. deactivation)
 *
 * See RF-W5, design.md F38 §1.
 */
final class WorkerSession
{
    public const EXIT_KIND_QR_SCAN    = 'QR_SCAN';
    public const EXIT_KIND_EXIT_RULE  = 'EXIT_RULE';
    public const EXIT_KIND_DOOR_EVENT = 'DOOR_EVENT';
    public const EXIT_KIND_AUTO       = 'AUTO';

    public int $id;
    public int $workerId;
    public int $roomId;
    public string $enteredAt;
    public ?string $exitedAt;
    public ?string $exitKind;
    public ?string $correlationId;
    public string $createdAt;
    public string $updatedAt;

    public function __construct(
        int $id,
        int $workerId,
        int $roomId,
        string $enteredAt,
        ?string $exitedAt,
        ?string $exitKind,
        ?string $correlationId,
        string $createdAt,
        string $updatedAt
    ) {
        $this->id            = $id;
        $this->workerId      = $workerId;
        $this->roomId        = $roomId;
        $this->enteredAt     = $enteredAt;
        $this->exitedAt      = $exitedAt;
        $this->exitKind      = $exitKind;
        $this->correlationId = $correlationId;
        $this->createdAt     = $createdAt;
        $this->updatedAt     = $updatedAt;
    }

    public function isActive(): bool
    {
        return $this->exitedAt === null;
    }

    public function close(string $exitKind, string $exitedAt): void
    {
        $this->exitKind = $exitKind;
        $this->exitedAt = $exitedAt;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'worker_id'      => $this->workerId,
            'room_id'        => $this->roomId,
            'entered_at'     => $this->enteredAt,
            'exited_at'      => $this->exitedAt,
            'exit_kind'      => $this->exitKind,
            'correlation_id' => $this->correlationId,
            'created_at'     => $this->createdAt,
            'updated_at'     => $this->updatedAt,
        ];
    }
}
