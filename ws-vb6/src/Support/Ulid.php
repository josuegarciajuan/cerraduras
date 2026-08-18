<?php
declare(strict_types=1);

namespace Ws\Support;

/**
 * Minimal ULID generator (Universally Unique Lexicographically Sortable ID).
 * Identical to the API version but in the Ws\ namespace.
 */
final class Ulid
{
    private const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(): string
    {
        $ms = (int) (microtime(true) * 1000);
        $ts = '';
        for ($i = 9; $i >= 0; $i--) {
            $ts = self::ENCODING[$ms & 0x1F] . $ts;
            $ms >>= 5;
        }
        $rand = '';
        $bytes = random_bytes(10);
        $val   = 0;
        $bits  = 0;
        for ($i = 0; $i < 10; $i++) {
            $val  = ($val << 8) | ord($bytes[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $rand .= self::ENCODING[($val >> $bits) & 0x1F];
            }
        }
        return $ts . $rand;
    }

    public static function isValid(string $ulid): bool
    {
        return (bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $ulid);
    }
}
