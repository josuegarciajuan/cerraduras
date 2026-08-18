<?php
declare(strict_types=1);

namespace App\Domain\Debts;

use App\Domain\Stays\Stay;
use App\Support\Clock;

/**
 * OverstayCalculator: computes occupancy time and excess for a stay.
 *
 * Definitions (design.md §8.3, contracts.md §2.8):
 *   ocupacion_minutos = minutes from first_entry_at to
 *                       (exit_detected_at if set, else now).
 *   exceso_minutos    = max(0, ocupacion_minutos - duracion_minutos)
 *   overstay          = exceso_minutos > grace_minutes
 *
 * The grace period determines WHEN a debt is triggered; the full
 * exceso_minutos (not minus grace) is what gets stored in debts and sent to
 * WS-VB6 for pricing.
 *
 * See RF-6, TSK-110.
 */
final class OverstayCalculator
{
    /**
     * Calculate overstay data for a stay.
     *
     * @param int|null $nowTs  Unix timestamp for "now" (injectable for tests).
     *                         Defaults to Clock::nowUtc().
     *
     * @return array{
     *   stay_id: int,
     *   duracion_minutos: int,
     *   ocupacion_minutos: int,
     *   exceso_minutos: int,
     *   grace_minutes: int,
     *   overstay: bool
     * }
     */
    public function calculate(Stay $stay, int $graceMinutes, ?int $nowTs = null): array
    {
        $nowTs = $nowTs ?? Clock::nowUtc()->getTimestamp();

        $ocupacionMinutos = 0;

        if ($stay->firstEntryAt !== null) {
            // All DATETIME(3) values from DB are UTC; append ' UTC' so
            // strtotime() does not apply the local timezone offset.
            $entryTs = strtotime($stay->firstEntryAt . ' UTC');

            if ($entryTs !== false) {
                // End time: exit_detected_at if recorded, else now.
                $endTs = $nowTs;
                if ($stay->exitDetectedAt !== null) {
                    $exitTs = strtotime($stay->exitDetectedAt . ' UTC');
                    if ($exitTs !== false) {
                        $endTs = $exitTs;
                    }
                }
                $elapsedSeconds   = max(0, $endTs - $entryTs);
                $ocupacionMinutos = (int) floor($elapsedSeconds / 60);
            }
        }

        $excesoMinutos = max(0, $ocupacionMinutos - $stay->duracionMinutos);
        $overstay      = $excesoMinutos > $graceMinutes;

        return [
            'stay_id'          => $stay->id,
            'duracion_minutos' => $stay->duracionMinutos,
            'ocupacion_minutos'=> $ocupacionMinutos,
            'exceso_minutos'   => $excesoMinutos,
            'grace_minutes'    => $graceMinutes,
            'overstay'         => $overstay,
        ];
    }
}
