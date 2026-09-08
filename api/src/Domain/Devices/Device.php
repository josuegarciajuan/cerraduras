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
 */
final class Device
{
    public const KIND_RPI       = 'RPI';
    public const KIND_LOCK      = 'LOCK';
    public const KIND_PROXIMITY = 'PROXIMITY';
    public const KIND_PRESENCE  = 'PRESENCE';
    public const KIND_SWITCH    = 'SWITCH';
    public const KIND_SCANNER   = 'SCANNER';

    public int $id;
    public ?int $packId;
    public string $kind;
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
        ?string $identifiedAt = null
    ) {
        $this->id = $id;
        $this->packId = $packId;
        $this->kind = $kind;
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
        return [self::KIND_RPI, self::KIND_LOCK, self::KIND_PROXIMITY, self::KIND_PRESENCE, self::KIND_SWITCH, self::KIND_SCANNER];
    }
}
