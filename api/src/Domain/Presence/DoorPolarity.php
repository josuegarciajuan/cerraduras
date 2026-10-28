<?php
declare(strict_types=1);

namespace App\Domain\Presence;

/**
 * DoorPolarity — F95/RF-162.
 *
 * La polaridad del contacto puede quedar invertida según cómo se monte el
 * imán/sensor. Se corrige POR DISPOSITIVO en `devices.meta_json.door_polarity`
 * antes de llegar al dominio, para que paneles, ventanas y reglas de salida
 * vean siempre la semántica canónica (CLOSED = puerta cerrada).
 */
final class DoorPolarity
{
    public const NORMAL = 'normal';
    public const INVERTED = 'inverted';

    /** @param array<string,mixed>|null $meta */
    public static function value(?array $meta): string
    {
        $value = is_array($meta) ? strtolower((string) ($meta['door_polarity'] ?? '')) : '';
        return $value === self::INVERTED ? self::INVERTED : self::NORMAL;
    }

    /** @param array<string,mixed>|null $meta */
    public static function isInverted(?array $meta): bool
    {
        return self::value($meta) === self::INVERTED;
    }

    /**
     * @param array<string,mixed>|null $meta
     */
    public static function canonical(?array $meta, string $value): string
    {
        if (!self::isInverted($meta)) {
            return $value;
        }
        return match ($value) {
            PresenceEvent::VALUE_OPEN => PresenceEvent::VALUE_CLOSED,
            PresenceEvent::VALUE_CLOSED => PresenceEvent::VALUE_OPEN,
            default => $value,
        };
    }

    /**
     * @param array<string,mixed>|null $meta
     * @return array<string,mixed>
     */
    public static function withPolarity(?array $meta, string $polarity): array
    {
        $meta = is_array($meta) ? $meta : [];
        $meta['door_polarity'] = $polarity === self::INVERTED ? self::INVERTED : self::NORMAL;
        return $meta;
    }
}
