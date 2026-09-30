<?php
declare(strict_types=1);

namespace App\Domain\Devices;

/**
 * Device: a physical device attached to a pack, and through the pack to a room.
 *
 * Canonical model (F30): device → pack → room. A device has NO direct room_id;
 * its room is always resolved through the pack (rooms.pack_id = devices.pack_id).
 *
 * Kinds (design.md §4.3):
 *   RPI       ESP32 with QR reader + relay
 *   LOCK      Electronic lock (Tuya)
 *   PROXIMITY Door open/closed sensor
 *   PRESENCE  Body-presence sensor inside the room
 *   SWITCH    Smart electricity protector (EAWCBT-J via Tuya) — RF-16
 *   CAMERA    IP camera (RTSP). `subtype` = EXTERIOR|INTERIOR — F60/RF-68
 */
final class Device
{
    public const KIND_RPI       = 'RPI';
    public const KIND_LOCK      = 'LOCK';
    public const KIND_PROXIMITY = 'PROXIMITY';
    public const KIND_PRESENCE  = 'PRESENCE';
    public const KIND_SWITCH    = 'SWITCH';
    public const KIND_SCANNER   = 'SCANNER';
    public const KIND_CAMERA    = 'CAMERA';

    /** Camera positions (device subtype). */
    public const SUBTYPE_CAMERA_EXTERIOR = 'EXTERIOR';
    public const SUBTYPE_CAMERA_INTERIOR = 'INTERIOR';

    public int $id;
    public ?int $packId;
    public string $kind;
    /** Only meaningful for CAMERA: EXTERIOR|INTERIOR (null otherwise). */
    public ?string $subtype;
    public string $externalId;
    public ?string $label;
    public ?int $apiClientId;
    /** @var array<string,mixed>|null */
    public ?array $meta;
    public ?int $batteryPct = null;
    public bool $isIdentified;
    public ?string $identifiedAt;

    /**
     * @param array<string,mixed>|null $meta
     */
    public function __construct(
        int $id,
        ?int $packId,
        string $kind,
        string $externalId,
        ?string $label,
        ?int $apiClientId,
        ?array $meta,
        ?int $batteryPct = null,
        bool $isIdentified = false,
        ?string $identifiedAt = null,
        ?string $subtype = null
    ) {
        $this->id = $id;
        $this->packId = $packId;
        $this->kind = $kind;
        $this->subtype = $subtype;
        $this->externalId = $externalId;
        $this->label = $label;
        $this->apiClientId = $apiClientId;
        $this->meta = $meta;
        $this->batteryPct = $batteryPct;
        $this->isIdentified = $isIdentified;
        $this->identifiedAt = $identifiedAt;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'pack_id' => $this->packId,
            'kind' => $this->kind,
            'subtype' => $this->subtype,
            'external_id' => $this->externalId,
            'label' => $this->label,
            'api_client_id' => $this->apiClientId,
            'meta' => $this->meta,
            'battery_pct' => $this->batteryPct,
        ];
    }

    /** @return list<string> */
    public static function allKinds(): array
    {
        return [
            self::KIND_RPI, self::KIND_LOCK, self::KIND_PROXIMITY, self::KIND_PRESENCE,
            self::KIND_SWITCH, self::KIND_SCANNER, self::KIND_CAMERA,
        ];
    }

    /** @return list<string> */
    public static function cameraPositions(): array
    {
        return [self::SUBTYPE_CAMERA_EXTERIOR, self::SUBTYPE_CAMERA_INTERIOR];
    }

    /**
     * Pure subtype validity matrix (F60/RF-68.2):
     *  - CAMERA requires EXTERIOR|INTERIOR.
     *  - Any other kind must carry no subtype (null or '').
     */
    public static function isValidSubtype(string $kind, ?string $subtype): bool
    {
        if ($kind === self::KIND_CAMERA) {
            return $subtype !== null && $subtype !== ''
                && in_array($subtype, self::cameraPositions(), true);
        }
        return $subtype === null || $subtype === '';
    }
}
