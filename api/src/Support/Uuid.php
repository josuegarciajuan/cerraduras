<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Uuid: v4 generator using random_bytes.
 *
 * Used for jti values stored in qr_credentials.jti CHAR(36) UNIQUE. We
 * intentionally use UUID v4 instead of ULID for jti so the column type
 * matches existing conventions and is compatible with future external tools
 * that expect UUID semantics for opaque identifiers.
 */
final class Uuid
{
    public static function v4(): string
    {
        $b = random_bytes(16);
        // Set version (4) and variant (10xx) bits per RFC 4122.
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
        $hex = bin2hex($b);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
