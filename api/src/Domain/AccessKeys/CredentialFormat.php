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
    public const PRINTED_DECIMAL_LENGTH = 10;
    public const EM_UID_HEX_LENGTH = 8;
    public const MAX_UINT32 = 4294967295;

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
     *
     * Kept for legacy identifiers. Ficha validation and creation use
     * canonicalFichaCode(), which turns an EM UID into its printed decimal.
     */
    public static function normaliseCode(string $code): string
    {
        $code = trim($code);

        if (preg_match('/^(?:[0-9A-Fa-f]{8}|[0-9A-Fa-f]{10})\z/', $code) === 1) {
            return strtoupper($code);
        }

        return $code;
    }

    /** True for the 10-digit decimal number printed on an EM ficha. */
    public static function isPrintedDecimal(string $code): bool
    {
        return preg_match('/^\d{' . self::PRINTED_DECIMAL_LENGTH . '}\z/', trim($code)) === 1;
    }

    /** True for the 8-hex UID emitted by the R35D-B. */
    public static function isEmHexUid(string $code): bool
    {
        return preg_match('/^[0-9A-Fa-f]{' . self::EM_UID_HEX_LENGTH . '}\z/', trim($code)) === 1;
    }

    /** True when a 10-digit decimal fits the 32-bit EM UID space. */
    public static function isDecimalUidInRange(string $code): bool
    {
        $code = trim($code);
        if (!self::isPrintedDecimal($code)) {
            return false;
        }

        $value = ltrim($code, '0');
        $max   = (string) self::MAX_UINT32;
        if ($value === '') {
            return true;
        }

        return strlen($value) < strlen($max)
            || (strlen($value) === strlen($max) && strcmp($value, $max) <= 0);
    }

    /**
     * Convert an 8-hex EM UID to the 10-digit decimal printed on the ficha.
     * `00EFA5BB` -> `0015705531`.
     */
    public static function decimalFromEmHex(string $code): ?string
    {
        $code = trim($code);
        if (!self::isEmHexUid($code)) {
            return null;
        }

        $value = hexdec($code);
        if (!is_int($value) || $value < 0 || $value > self::MAX_UINT32) {
            return null;
        }

        return str_pad((string) $value, self::PRINTED_DECIMAL_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Canonical ficha code: 10-digit decimal for EM / printed decimal input,
     * legacy normalisation for any other text.
     */
    public static function canonicalFichaCode(string $code): string
    {
        $code = trim($code);
        if (self::isPrintedDecimal($code)) {
            return $code;
        }

        $decimal = self::decimalFromEmHex($code);
        if ($decimal !== null) {
            return $decimal;
        }

        return self::normaliseCode($code);
    }

    /**
     * All representations that may hash to the same ficha. The canonical
     * decimal is always first; the legacy 8-hex form follows when derivable.
     *
     * @return list<string>
     */
    public static function fichaCandidates(string $code): array
    {
        $code = trim($code);
        $out  = [];

        $canonical = self::canonicalFichaCode($code);
        if ($canonical !== '') {
            $out[] = $canonical;
        }

        if (self::isEmHexUid($code)) {
            $out[] = strtoupper($code);
        } elseif (self::isPrintedDecimal($code) && self::isDecimalUidInRange($code)) {
            $out[] = sprintf('%08X', (int) $code);
        }

        $legacy = self::normaliseCode($code);
        if ($legacy !== '') {
            $out[] = $legacy;
        }

        return array_values(array_unique($out));
    }
}
