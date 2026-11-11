<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

use App\Support\Config;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * RadarFreshness — F125.1.4.
 *
 * Frescura específica del radar Zigbee. El ZY-M100 publica periódicamente
 * (~1,5 s); 300 s es demasiado margen para decir "online". Este helper
 * centraliza el umbral (30 s por defecto) y la edad de la última decisión.
 *
 * No lee MQTT ni escribe nada: solo interpreta timestamps persistidos.
 */
final class RadarFreshness
{
    public const DEFAULT_STALE_SECONDS = 30;

    public static function staleSeconds(): int
    {
        $raw = Config::get('RADAR_PRESENCE_STALE_SECONDS', (string) self::DEFAULT_STALE_SECONDS);
        $value = is_numeric($raw) ? (int) $raw : self::DEFAULT_STALE_SECONDS;
        return max(5, min(300, $value > 0 ? $value : self::DEFAULT_STALE_SECONDS));
    }

    public static function ageSeconds(?string $timestamp, ?int $now = null): ?int
    {
        if ($timestamp === null || trim($timestamp) === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
        $now ??= time();
        return max(0, $now - $dt->getTimestamp());
    }

    public static function stale(?string $timestamp, ?int $now = null): bool
    {
        $age = self::ageSeconds($timestamp, $now);
        return $age === null || $age > self::staleSeconds();
    }

    /** @param array<string,mixed> $decision */
    public static function decisionStale(array $decision, ?int $now = null): bool
    {
        if (($decision['stale'] ?? false) === true) {
            return true;
        }
        $age = self::ageSeconds(
            isset($decision['decided_at']) && is_string($decision['decided_at']) ? $decision['decided_at'] : null,
            $now
        );
        return $age === null || $age > self::staleSeconds();
    }
}
