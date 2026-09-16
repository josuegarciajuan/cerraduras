<?php
declare(strict_types=1);

namespace App\Domain\Presence;

/**
 * PresenceEvent: one raw sensor reading stored in presence_events.
 *
 * sensor:  PROXIMITY (door open/closed) | PRESENCE (body detected/absent).
 * value:   OPEN | CLOSED | PRESENT | ABSENT.
 * provider: TUYA | SIMULATED.
 *
 * source_event_id is the external identifier from Tuya (or simulator); used
 * for idempotent inserts via the UNIQUE DB constraint.
 *
 * See design.md §4.9.
 */
final class PresenceEvent
{
    public const SENSOR_PROXIMITY = 'PROXIMITY';
    public const SENSOR_PRESENCE  = 'PRESENCE';

    public const VALUE_OPEN    = 'OPEN';
    public const VALUE_CLOSED  = 'CLOSED';
    public const VALUE_PRESENT = 'PRESENT';
    public const VALUE_ABSENT  = 'ABSENT';

    public const PROVIDER_TUYA      = 'TUYA';
    public const PROVIDER_SIMULATED = 'SIMULATED';

    public int $id;
    public int $roomId;
    public string $sensor;
    public string $value;
    public string $provider;
    public string $occurredAt;
    public string $receivedAt;
    public ?string $sourceEventId;
    /** @var array<string,mixed>|null */
    public ?array $meta;

    // F41 audit/dedup columns (nullable; NULL on legacy rows).
    public ?string $fingerprint;
    public ?bool $applied;
    public ?string $discardReason;

    /**
     * @param array<string,mixed>|null $meta
     */
    public function __construct(
        int     $id,
        int     $roomId,
        string  $sensor,
        string  $value,
        string  $provider,
        string  $occurredAt,
        string  $receivedAt,
        ?string $sourceEventId,
        ?array  $meta,
        ?string $fingerprint = null,
        ?bool   $applied = null,
        ?string $discardReason = null
    ) {
        $this->id            = $id;
        $this->roomId        = $roomId;
        $this->sensor        = $sensor;
        $this->value         = $value;
        $this->provider      = $provider;
        $this->occurredAt    = $occurredAt;
        $this->receivedAt    = $receivedAt;
        $this->sourceEventId = $sourceEventId;
        $this->meta          = $meta;
        $this->fingerprint   = $fingerprint;
        $this->applied       = $applied;
        $this->discardReason = $discardReason;
    }
}
