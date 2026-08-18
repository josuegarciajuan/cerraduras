<?php
declare(strict_types=1);

namespace App\Domain\TimeSlots;

/**
 * TimeSlot: a single RENTABLE or FREE band of the day for a room_type.
 *
 * Internal representation uses minutes-from-midnight (0..1440) because it
 * keeps boundary arithmetic simple and unambiguous. 24:00 is modeled as 1440.
 *
 * External representation is HH:MM (24-hour clock) in requests/responses.
 */
final class TimeSlot
{
    public const KIND_RENTABLE = 'RENTABLE';
    public const KIND_FREE     = 'FREE';

    public int $startsAtMinutes;
    public int $endsAtMinutes;
    public string $kind;

    public function __construct(int $startsAtMinutes, int $endsAtMinutes, string $kind)
    {
        $this->startsAtMinutes = $startsAtMinutes;
        $this->endsAtMinutes = $endsAtMinutes;
        $this->kind = $kind;
    }

    public static function fromDb(string $startsAt, string $endsAt, string $kind): self
    {
        return new self(
            self::parseHms($startsAt),
            self::parseHmsAsEnd($endsAt),
            $kind
        );
    }

    public function contains(int $minuteOfDay): bool
    {
        return $minuteOfDay >= $this->startsAtMinutes && $minuteOfDay < $this->endsAtMinutes;
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return [
            'starts_at' => self::formatHm($this->startsAtMinutes),
            'ends_at'   => self::formatHm($this->endsAtMinutes),
            'kind'      => $this->kind,
        ];
    }

    public static function parseHm(string $value): int
    {
        $value = trim($value);
        // Accept "HH:MM" or "HH:MM:SS"; seconds must be 00 if given.
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            throw new \InvalidArgumentException("Invalid time format: {$value}");
        }
        $h = (int) $m[1];
        $mi = (int) $m[2];
        $s = isset($m[3]) ? (int) $m[3] : 0;
        if ($h === 24 && $mi === 0 && $s === 0) {
            return 1440;
        }
        if ($h < 0 || $h > 23 || $mi < 0 || $mi > 59 || $s !== 0) {
            throw new \InvalidArgumentException("Invalid time value: {$value}");
        }
        return $h * 60 + $mi;
    }

    /**
     * End-of-day representation. DB TIME column stores 24:00 as 00:00:00 or
     * 23:59:59 depending on convention; we accept both plus literal "24:00".
     * If we see 00:00:00 we treat it as 0 (start-of-day). The caller
     * (TimeSlotService) must handle the logical meaning.
     */
    public static function parseHmsAsEnd(string $value): int
    {
        $v = trim($value);
        // Normalize seconds
        if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $v, $m)) {
            $h = (int) $m[1];
            $mi = (int) $m[2];
            $s = (int) $m[3];
            if ($h === 23 && $mi === 59 && $s === 59) {
                return 1440; // stored as 23:59:59 to mean "end of day" in TIME columns
            }
            // Otherwise fall back to standard parse (rounded to minute).
            if ($s !== 0) {
                throw new \InvalidArgumentException("Seconds must be 00: {$value}");
            }
            return self::parseHm(sprintf('%02d:%02d', $h, $mi));
        }
        return self::parseHm($v);
    }

    /**
     * Alias kept for symmetry with parseHmsAsEnd().
     */
    public static function parseHms(string $value): int
    {
        return self::parseHm($value);
    }

    public static function formatHm(int $minutes): string
    {
        if ($minutes === 1440) {
            return '24:00';
        }
        $h = intdiv($minutes, 60);
        $mi = $minutes % 60;
        return sprintf('%02d:%02d', $h, $mi);
    }

    /**
     * DB representation for INSERT. We store 24:00 as 23:59:59 so TIME column
     * accepts it, and handle the edge case on read in parseHmsAsEnd().
     */
    public static function formatHmsForDb(int $minutes): string
    {
        if ($minutes === 1440) {
            return '23:59:59';
        }
        $h = intdiv($minutes, 60);
        $mi = $minutes % 60;
        return sprintf('%02d:%02d:00', $h, $mi);
    }
}
