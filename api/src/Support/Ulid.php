<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Ulid: Crockford's Base32 ULID generator.
 *
 * Produces a 26-character lexicographically sortable identifier:
 *   - 10 chars encode 48 bits of millisecond timestamp
 *   - 16 chars encode 80 bits of randomness
 *
 * Used for correlation ids, access_events.correlation_id, audit_log.correlation_id.
 * Pure PHP, no extensions required.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(): string
    {
        $timestampMs = (int) floor(microtime(true) * 1000);
        $time = self::encodeTime($timestampMs);
        $rand = self::encodeRandom();
        return $time . $rand;
    }

    /**
     * Validate the shape of a ULID (26 chars from Crockford Base32).
     * Does NOT verify monotonic or timing semantics.
     */
    public static function isValid(string $value): bool
    {
        if (strlen($value) !== 26) {
            return false;
        }
        $upper = strtoupper($value);
        for ($i = 0, $n = strlen($upper); $i < $n; $i++) {
            if (strpos(self::ALPHABET, $upper[$i]) === false) {
                return false;
            }
        }
        return true;
    }

    private static function encodeTime(int $timestampMs): string
    {
        $out = '';
        for ($i = 9; $i >= 0; $i--) {
            $mod = $timestampMs % 32;
            $out = self::ALPHABET[$mod] . $out;
            $timestampMs = intdiv($timestampMs, 32);
        }
        return $out;
    }

    private static function encodeRandom(): string
    {
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }
        return $out;
    }
}
