<?php
declare(strict_types=1);

namespace App\Domain\AccessKeys;

/**
 * CredentialFormat — pure rules shared by the reader endpoints (F113).
 *
 * The R35D-B sends both QR tokens and NFC card identifiers as plain ASCII
 * text over the same UART. A signed token always has the shape
 * `header.payload.signature` (two dots), so any text without a dot is a
 * candidate ficha. The normaliser makes hexadecimal UIDs stable regardless of
 * the case the reader uses.
 */
final class CredentialFormat
{
    public const MIN_FICHA_LENGTH = 4;
    public const MAX_FICHA_LENGTH = 64;

    /**
     * True when the text can be treated as a plain-text NFC card identifier.
     * Tokens (they contain a dot) and non-printable/out-of-range text return
     * false so the caller keeps the token flow and its error codes.
     */
    public static function isPlainTextFicha(string $text): bool
    {
        if (str_contains($text, '.')) {
            return false;
        }

        return preg_match('/^[\x20-\x7E]{4,64}\z/', $text) === 1;
    }

    /**
     * Trim the code and upper-case 8/10 hex UIDs so `00efa5bb` and `00EFA5BB`
     * hash to the same card. Other codes are only trimmed.
     */
    public static function normaliseCode(string $code): string
    {
        $code = trim($code);

        if (preg_match('/^(?:[0-9A-Fa-f]{8}|[0-9A-Fa-f]{10})\z/', $code) === 1) {
            return strtoupper($code);
        }

        return $code;
    }
}
