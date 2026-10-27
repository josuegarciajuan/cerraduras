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
 *  - warehouse_confirm_seconds  F60/RF-67.2: 'X' seconds to confirm presence
 *                               after a QR/door trigger before discarding.
 *  - warehouse_exterior_margin_seconds  F60/RF-67.2: 'M' extra seconds the
 *                               EXTERIOR camera keeps recording after absence.
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
    public int $warehouseConfirmSeconds;
    public int $warehouseExteriorMarginSeconds;

    /**
     * F91/RF-148: presence decision mode and evidence windows. These do not
     * change the existing F88 camera fusion unless a room explicitly opts in.
     */
    public const PRESENCE_MODE_CAMERA_FUSION     = 'camera_fusion';
    public const PRESENCE_MODE_RADAR_DOOR        = 'radar_door';
    public const PRESENCE_MODE_RADAR_ONLY_SHADOW = 'radar_only_shadow';
    public const PRESENCE_MODE_RADAR_ONLY        = 'radar_only';

    public string $presenceMode;
    public int $presenceConfirmReports;
    public int $presenceConfirmWindowSeconds;
    public int $presenceMinSpanSeconds;
    public int $presenceHoldSeconds;
    public int $presenceAbsentReports;
    public int $presenceAbsentWindowSeconds;
    public int $presenceDoorWindowSeconds;
    public int $presenceStaleSeconds;

    public function __construct(
        int $id,
        string $code,
        string $name,
        int $graceMinutes,
        int $exitPresenceGapSeconds,
        int $reentryCooldownSeconds,
        int $qrUsageWindowMinutes,
        int $presenceEntryWindowSeconds = 90,
        int $exitCheckSeconds = 40,
        int $warehouseConfirmSeconds = 40,
        int $warehouseExteriorMarginSeconds = 5,
        string $presenceMode = self::PRESENCE_MODE_CAMERA_FUSION,
        int $presenceConfirmReports = 3,
        int $presenceConfirmWindowSeconds = 12,
        int $presenceMinSpanSeconds = 6,
        int $presenceHoldSeconds = 20,
        int $presenceAbsentReports = 3,
        int $presenceAbsentWindowSeconds = 30,
        int $presenceDoorWindowSeconds = 90,
        int $presenceStaleSeconds = 180
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
        $this->warehouseConfirmSeconds = $warehouseConfirmSeconds;
        $this->warehouseExteriorMarginSeconds = $warehouseExteriorMarginSeconds;
        $this->presenceMode = $presenceMode;
        $this->presenceConfirmReports = $presenceConfirmReports;
        $this->presenceConfirmWindowSeconds = $presenceConfirmWindowSeconds;
        $this->presenceMinSpanSeconds = $presenceMinSpanSeconds;
        $this->presenceHoldSeconds = $presenceHoldSeconds;
        $this->presenceAbsentReports = $presenceAbsentReports;
        $this->presenceAbsentWindowSeconds = $presenceAbsentWindowSeconds;
        $this->presenceDoorWindowSeconds = $presenceDoorWindowSeconds;
        $this->presenceStaleSeconds = $presenceStaleSeconds;
    }

    /** @return list<string> */
    public static function presenceModes(): array
    {
        return [
            self::PRESENCE_MODE_CAMERA_FUSION,
            self::PRESENCE_MODE_RADAR_DOOR,
            self::PRESENCE_MODE_RADAR_ONLY_SHADOW,
            self::PRESENCE_MODE_RADAR_ONLY,
        ];
    }

    public static function isValidPresenceMode(string $mode): bool
    {
        return in_array($mode, self::presenceModes(), true);
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
            'warehouse_confirm_seconds' => $this->warehouseConfirmSeconds,
            'warehouse_exterior_margin_seconds' => $this->warehouseExteriorMarginSeconds,
            'presence_mode' => $this->presenceMode,
            'presence_confirm_reports' => $this->presenceConfirmReports,
            'presence_confirm_window_seconds' => $this->presenceConfirmWindowSeconds,
            'presence_min_span_seconds' => $this->presenceMinSpanSeconds,
            'presence_hold_seconds' => $this->presenceHoldSeconds,
            'presence_absent_reports' => $this->presenceAbsentReports,
            'presence_absent_window_seconds' => $this->presenceAbsentWindowSeconds,
            'presence_door_window_seconds' => $this->presenceDoorWindowSeconds,
            'presence_stale_seconds' => $this->presenceStaleSeconds,
        ];
    }
}
