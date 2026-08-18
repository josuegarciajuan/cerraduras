<?php
declare(strict_types=1);

namespace Ws\Support;

/**
 * Clock: injectable UTC clock for the WS-VB6 service.
 * Identical to the API version but in the Ws\ namespace.
 */
final class Clock
{
    private static ?\DateTimeImmutable $frozen = null;

    public static function nowUtc(): \DateTimeImmutable
    {
        if (self::$frozen !== null) {
            return self::$frozen;
        }
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function freeze(\DateTimeImmutable $dt): void
    {
        self::$frozen = $dt->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function unfreeze(): void
    {
        self::$frozen = null;
    }
}
