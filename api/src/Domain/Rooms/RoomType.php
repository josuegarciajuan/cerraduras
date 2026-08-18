<?php
declare(strict_types=1);

namespace App\Domain\Rooms;

/**
 * RoomType: operational parameters shared by a group of rooms.
 *
 * Semantics per field:
 *  - code                       stable identifier (e.g. STANDARD, SUITE).
 *  - name                       human-readable label.
 *  - grace_minutes              tolerance over duracion_minutos before debt.
 *  - exit_presence_gap_seconds  'N' seconds of sustained absence required to
 *                               confirm an exit (regla salida §8.2).
 *  - reentry_cooldown_seconds   how long the door stays "hot-locked" after
 *                               an auto exit to mitigate reentries.
 *  - qr_usage_window_minutes    exp - iat for QR tokens issued for this type.
 */
final class RoomType
{
    public int $id;
    public string $code;
    public string $name;
    public int $graceMinutes;
    public int $exitPresenceGapSeconds;
    public int $reentryCooldownSeconds;
    public int $qrUsageWindowMinutes;

    public function __construct(
        int $id,
        string $code,
        string $name,
        int $graceMinutes,
        int $exitPresenceGapSeconds,
        int $reentryCooldownSeconds,
        int $qrUsageWindowMinutes
    ) {
        $this->id = $id;
        $this->code = $code;
        $this->name = $name;
        $this->graceMinutes = $graceMinutes;
        $this->exitPresenceGapSeconds = $exitPresenceGapSeconds;
        $this->reentryCooldownSeconds = $reentryCooldownSeconds;
        $this->qrUsageWindowMinutes = $qrUsageWindowMinutes;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'grace_minutes' => $this->graceMinutes,
            'exit_presence_gap_seconds' => $this->exitPresenceGapSeconds,
            'reentry_cooldown_seconds' => $this->reentryCooldownSeconds,
            'qr_usage_window_minutes' => $this->qrUsageWindowMinutes,
        ];
    }
}
