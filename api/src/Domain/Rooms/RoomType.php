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
 *  - qr_usage_window_minutes    DEPRECATED for guest QR issuance (Fase 51 /
 *                               Bug 4). The guest QR lifetime is now driven by
 *                               the global QR_ARRIVAL_WINDOW_MINUTES + the stay
 *                               duration; this column is no longer used to seal
 *                               guest tokens (kept for compatibility).
 *  - presence_entry_window_seconds  F44/RF-51.2: seconds the presence poller
 *                               keeps sampling after a door OPEN until presence
 *                               is detected (>= 40 by policy).
 *  - exit_check_seconds         F44/RF-51.4: seconds the presence poller keeps
 *                               sampling after a door CLOSE to decide the exit
 *                               (>= 40 by policy).
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
    public int $presenceEntryWindowSeconds;
    public int $exitCheckSeconds;

    public function __construct(
        int $id,
        string $code,
        string $name,
        int $graceMinutes,
        int $exitPresenceGapSeconds,
        int $reentryCooldownSeconds,
        int $qrUsageWindowMinutes,
        int $presenceEntryWindowSeconds = 90,
        int $exitCheckSeconds = 40
    ) {
        $this->id = $id;
        $this->code = $code;
        $this->name = $name;
        $this->graceMinutes = $graceMinutes;
        $this->exitPresenceGapSeconds = $exitPresenceGapSeconds;
        $this->reentryCooldownSeconds = $reentryCooldownSeconds;
        $this->qrUsageWindowMinutes = $qrUsageWindowMinutes;
        $this->presenceEntryWindowSeconds = $presenceEntryWindowSeconds;
        $this->exitCheckSeconds = $exitCheckSeconds;
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
            'presence_entry_window_seconds' => $this->presenceEntryWindowSeconds,
            'exit_check_seconds' => $this->exitCheckSeconds,
        ];
    }
}
