<?php
declare(strict_types=1);

namespace App\Domain\TimeSlots;

use App\Support\Config;
use App\Support\Errors\UnprocessableException;

/**
 * TimeSlotService: validation and queries for time_slots.
 *
 * Business rules (design.md §7):
 *  - Slots for a single room_type MUST cover exactly [00:00, 24:00).
 *  - Slots MUST NOT overlap.
 *  - Cross-midnight slots are represented as two separate rows.
 *  - kind ∈ {RENTABLE, FREE}.
 *
 * Resolution (TSK-047):
 *  - currentKind($roomTypeId, $nowUtc): given a UTC instant, converts it to
 *    the hotel local time (APP_TZ, default Europe/Madrid) and returns the
 *    kind (RENTABLE|FREE) of the slot that contains it. Returns null if no
 *    slots are defined for the type.
 */
final class TimeSlotService
{
    private TimeSlotRepositoryInterface $repo;

    public function __construct(TimeSlotRepositoryInterface $repo)
    {
        $this->repo = $repo;
    }

    /** @return list<TimeSlot> */
    public function listForRoomType(int $roomTypeId): array
    {
        return $this->repo->listForRoomType($roomTypeId);
    }

    /**
     * Replace all slots of a room type with the provided set.
     * The caller MUST pass already-parsed TimeSlot objects OR use saveRaw().
     *
     * @param list<TimeSlot> $slots
     */
    public function save(int $roomTypeId, array $slots): void
    {
        $this->validateSet($slots);
        $this->repo->replaceAll($roomTypeId, $slots);
    }

    /**
     * Parses raw slot dicts (as they arrive via the API) into TimeSlot objects
     * and delegates to save(). Input format per slot:
     *   { "starts_at": "HH:MM", "ends_at": "HH:MM", "kind": "RENTABLE"|"FREE" }
     *
     * @param list<array<string,mixed>> $raw
     */
    public function saveRaw(int $roomTypeId, array $raw): void
    {
        $slots = [];
        foreach ($raw as $idx => $item) {
            if (!is_array($item)) {
                throw new UnprocessableException('invalid_slot', "slot[{$idx}] must be an object");
            }
            foreach (['starts_at', 'ends_at', 'kind'] as $f) {
                if (!isset($item[$f]) || !is_string($item[$f])) {
                    throw new UnprocessableException(
                        'invalid_slot',
                        "slot[{$idx}].{$f} is required and must be a string"
                    );
                }
            }
            $kind = $item['kind'];
            if (!in_array($kind, [TimeSlot::KIND_RENTABLE, TimeSlot::KIND_FREE], true)) {
                throw new UnprocessableException(
                    'invalid_slot',
                    "slot[{$idx}].kind must be RENTABLE or FREE",
                    ['given' => $kind]
                );
            }
            try {
                $startsAt = TimeSlot::parseHm((string) $item['starts_at']);
                $endsAt   = TimeSlot::parseHm((string) $item['ends_at']);
            } catch (\InvalidArgumentException $e) {
                throw new UnprocessableException(
                    'invalid_slot',
                    "slot[{$idx}] has invalid time: " . $e->getMessage()
                );
            }
            if ($startsAt >= $endsAt) {
                throw new UnprocessableException(
                    'invalid_slot',
                    "slot[{$idx}] starts_at must be strictly before ends_at"
                );
            }
            $slots[] = new TimeSlot($startsAt, $endsAt, $kind);
        }
        $this->save($roomTypeId, $slots);
    }

    /**
     * Returns 'RENTABLE' | 'FREE' | null for the room_type at the given UTC instant.
     *
     * Timezone handling: the day bands (RENTABLE/FREE) are defined in hotel
     * local time. We convert $nowUtc to the configured APP_TZ and resolve
     * the slot that contains the local minute-of-day.
     */
    public function currentKind(int $roomTypeId, \DateTimeImmutable $nowUtc): ?string
    {
        $slots = $this->repo->listForRoomType($roomTypeId);
        if (empty($slots)) {
            return null;
        }
        $tzName = Config::get('APP_TZ', 'Europe/Madrid') ?? 'UTC';
        $local = $nowUtc->setTimezone(new \DateTimeZone($tzName));
        $minuteOfDay = ((int) $local->format('H')) * 60 + (int) $local->format('i');

        foreach ($slots as $slot) {
            if ($slot->contains($minuteOfDay)) {
                return $slot->kind;
            }
        }
        // Gap detected: the stored set should have been validated to cover
        // the full day, so this should only happen if the DB was modified
        // manually. Return null so callers can decide policy.
        return null;
    }

    /**
     * Validate that a set of slots covers [0, 1440) with no overlap and no gap.
     * Expects the list unordered; sorts internally.
     *
     * @param list<TimeSlot> $slots
     */
    public function validateSet(array $slots): void
    {
        if (empty($slots)) {
            throw new UnprocessableException(
                'slots_not_full_day',
                'At least one slot is required to cover the full day'
            );
        }
        $sorted = $slots;
        usort($sorted, static function (TimeSlot $a, TimeSlot $b): int {
            return $a->startsAtMinutes <=> $b->startsAtMinutes;
        });

        // First slot must start at 00:00.
        if ($sorted[0]->startsAtMinutes !== 0) {
            throw new UnprocessableException(
                'slots_not_full_day',
                'The first slot must start at 00:00',
                ['first_starts_at' => TimeSlot::formatHm($sorted[0]->startsAtMinutes)]
            );
        }
        // Each subsequent slot must start exactly where the previous ended.
        for ($i = 1, $n = count($sorted); $i < $n; $i++) {
            $prev = $sorted[$i - 1];
            $cur  = $sorted[$i];
            if ($cur->startsAtMinutes < $prev->endsAtMinutes) {
                throw new UnprocessableException(
                    'slots_overlap',
                    'Time slots overlap',
                    [
                        'prev_ends_at' => TimeSlot::formatHm($prev->endsAtMinutes),
                        'curr_starts_at' => TimeSlot::formatHm($cur->startsAtMinutes),
                    ]
                );
            }
            if ($cur->startsAtMinutes > $prev->endsAtMinutes) {
                throw new UnprocessableException(
                    'slots_not_full_day',
                    'Time slots leave a gap in the day',
                    [
                        'gap_from' => TimeSlot::formatHm($prev->endsAtMinutes),
                        'gap_to'   => TimeSlot::formatHm($cur->startsAtMinutes),
                    ]
                );
            }
        }
        // Last slot must end at 24:00 (1440).
        $last = $sorted[count($sorted) - 1];
        if ($last->endsAtMinutes !== 1440) {
            throw new UnprocessableException(
                'slots_not_full_day',
                'The last slot must end at 24:00',
                ['last_ends_at' => TimeSlot::formatHm($last->endsAtMinutes)]
            );
        }
    }
}
