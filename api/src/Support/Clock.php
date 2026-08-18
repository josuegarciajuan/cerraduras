<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Clock: thin wrapper so tests can freeze time.
 *
 * All timestamps returned are UTC (see RNF-10 / design.md §1.2).
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

    /**
     * ISO-8601 in UTC with millisecond precision and trailing "Z".
     * Example: 2026-04-24T11:00:00.123Z
     */
    public static function nowIsoUtcMillis(): string
    {
        $now = self::nowUtc();
        // DateTimeImmutable lacks millis in format() unless we get them from microseconds.
        $micros = (int) $now->format('u');
        $millis = intdiv($micros, 1000);
        return $now->format('Y-m-d\TH:i:s') . sprintf('.%03dZ', $millis);
    }

    public static function freeze(\DateTimeImmutable $at): void
    {
        self::$frozen = $at;
    }

    public static function unfreeze(): void
    {
        self::$frozen = null;
    }
}
