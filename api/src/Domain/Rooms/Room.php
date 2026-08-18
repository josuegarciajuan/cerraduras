<?php
declare(strict_types=1);

namespace App\Domain\Rooms;

/**
 * Room: hotel room aggregate.
 *
 * Status values (design.md §6.1):
 *   FREE, RESERVED, OCCUPIED, OVERSTAY, EXITED, CLEANING, OUT_OF_SERVICE.
 *
 * cooldownUntil is set by the anti-reentry policy after an auto-lock and
 * unset by the maintenance job (or after the cooldown expires naturally).
 *
 * simulatedOverride:
 *   null  -> follow the global SIMULATED_MODE flag,
 *   false -> force real provider for this room,
 *   true  -> force simulated provider for this room.
 */
final class Room
{
    public const STATUS_FREE          = 'FREE';
    public const STATUS_RESERVED      = 'RESERVED';
    public const STATUS_OCCUPIED      = 'OCCUPIED';
    public const STATUS_OVERSTAY      = 'OVERSTAY';
    public const STATUS_EXITED        = 'EXITED';
    public const STATUS_CLEANING      = 'CLEANING';
    public const STATUS_OUT_OF_SERVICE = 'OUT_OF_SERVICE';

    public int $id;
    public string $code;
    public int $roomTypeId;
    public ?int $packId;
    public string $status;
    public ?bool $simulatedOverride;
    public ?string $notes;
    public ?string $cooldownUntil;
    public ?int $presenceCheckSeconds;

    public function __construct(
        int $id,
        string $code,
        int $roomTypeId,
        ?int $packId,
        string $status,
        ?bool $simulatedOverride,
        ?string $cooldownUntil,
        ?string $notes = null,
        ?int $presenceCheckSeconds = null
    ) {
        $this->id = $id;
        $this->code = $code;
        $this->roomTypeId = $roomTypeId;
        $this->packId = $packId;
        $this->status = $status;
        $this->simulatedOverride = $simulatedOverride;
        $this->cooldownUntil = $cooldownUntil;
        $this->notes = $notes;
        $this->presenceCheckSeconds = $presenceCheckSeconds;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'room_type_id' => $this->roomTypeId,
            'pack_id' => $this->packId,
            'status' => $this->status,
            'simulated_override' => $this->simulatedOverride,
            'cooldown_until' => $this->cooldownUntil,
            'notes' => $this->notes,
            'presence_check_seconds' => $this->presenceCheckSeconds,
        ];
    }
}
