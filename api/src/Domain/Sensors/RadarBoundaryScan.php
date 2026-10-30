<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * RadarBoundaryScan — F104/RF-213.
 *
 * Evidencia del barrido de salida del radar. El técnico empieza dentro (donde
 * el radar debe detectarlo) y camina hacia fuera hasta que el sensor lo pierde.
 * La última distancia válida con PRESENT antes del primer ABSENT es la frontera
 * real de detección: el punto a partir del cual el radar ya no debe alcanzar.
 *
 * No escribe en el dispositivo, no sondea y no usa temporizadores. Solo resume
 * eventos ya persistidos por push.
 */
final class RadarBoundaryScan
{
    public const REASON_OK = 'boundary_found';
    public const REASON_NO_PRESENCE = 'no_presence';
    public const REASON_NO_TRANSITION = 'no_transition';
    public const REASON_NO_DISTANCE = 'no_distance';
    public const REASON_WINDOW_TOO_SHORT = 'window_too_short';
    public const REASON_CONFIG_CHANGED = 'config_changed';
    public const REASON_STALE_SEED = 'stale_state';

    /**
     * @param list<array<string,mixed>> $events
     * @param array<string,mixed> $expectedSettings
     * @param array{min_clear_seconds?:int,min_absent_reports?:int} $requirements
     * @param array{state?:?string,occurred_at?:?string,age_seconds?:?int,fresh?:bool} $seed
     * @return array<string,mixed>
     */
    public static function summarize(
        array $events,
        string $windowStart,
        string $windowEnd,
        int $configVersion,
        array $expectedSettings,
        array $seed = [],
        array $requirements = []
    ): array {
        $startMs = self::ms($windowStart);
        $endMs = self::ms($windowEnd);
        $minClear = max(0, (int) ($requirements['min_clear_seconds'] ?? 6));
        $minAbsentReports = max(1, (int) ($requirements['min_absent_reports'] ?? 2));

        $seedState = strtoupper((string) ($seed['state'] ?? ''));
        $seedFresh = (bool) ($seed['fresh'] ?? false);
        $seedAt = isset($seed['occurred_at']) && $seed['occurred_at'] !== null
            ? (string) $seed['occurred_at'] : null;
        $seedUsed = $seedFresh && $seedState === 'PRESENT';

        $reports = [];
        $configChanged = false;
        $expected = self::settingsKey($expectedSettings);
        foreach ($events as $e) {
            if (!is_array($e)) {
                continue;
            }
            $ts = self::ms((string) ($e['occurred_at'] ?? ''));
            if ($ts === null || ($startMs !== null && $ts < $startMs) || ($endMs !== null && $ts > $endMs)) {
                continue;
            }
            if (strtoupper((string) ($e['sensor'] ?? '')) !== 'PRESENCE') {
                continue;
            }
            $provider = strtoupper((string) ($e['provider'] ?? ''));
            if (!in_array($provider, ['ZIGBEE2MQTT', 'TUYA'], true)) {
                continue;
            }
            $source = strtolower((string) (($e['meta'] ?? [])['source'] ?? ''));
            if ($source === 'resync') {
                continue;
            }
            $value = (string) ($e['value'] ?? '');
            if (!in_array($value, ['PRESENT', 'ABSENT'], true)) {
                continue;
            }
            $settings = is_array($e['meta']['settings'] ?? null) ? $e['meta']['settings'] : [];
            if ($settings !== []) {
                $comparable = array_intersect_key($settings, $expectedSettings);
                if ($comparable !== []
                    && self::settingsKey($comparable) !== self::settingsKey(array_intersect_key($expectedSettings, $comparable))) {
                    $configChanged = true;
                }
            }
            $reports[] = [
                'ts' => $ts,
                'value' => $value,
                'settings' => $settings,
            ];
        }

        usort($reports, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
        $count = count($reports);
        $first = $count > 0 ? $reports[0]['ts'] : null;
        $last = $count > 0 ? $reports[$count - 1]['ts'] : null;
        $span = ($first !== null && $last !== null) ? max(0, ($last - $first) / 1000) : 0.0;
        $windowSeconds = ($startMs !== null && $endMs !== null) ? max(0, ($endMs - $startMs) / 1000) : 0.0;

        $firstPresentTs = $seedUsed ? self::ms($seedAt) : null;
        $lastPresentTs = $firstPresentTs;
        $firstAbsentTs = null;
        $presents = 0;
        $absents = 0;
        $absentAfter = 0;
        $presentSamples = [];
        foreach ($reports as $r) {
            if ($r['value'] === 'PRESENT') {
                $presents++;
                $firstPresentTs ??= $r['ts'];
                $lastPresentTs = $r['ts'];
                $distance = $r['settings']['target_distance'] ?? null;
                if (is_numeric($distance) && (float) $distance > 0) {
                    $presentSamples[] = ['ts' => $r['ts'], 'value' => (float) $distance];
                }
                continue;
            }
            $absents++;
            if ($firstPresentTs !== null) {
                $absentAfter++;
                $firstAbsentTs ??= $r['ts'];
            }
        }

        $boundarySamples = [];
        $firstAbsentAt = $firstAbsentTs !== null ? self::isoFromMs($firstAbsentTs) : null;
        if ($firstAbsentTs !== null) {
            foreach ($presentSamples as $sample) {
                if ($sample['ts'] <= $firstAbsentTs) {
                    $boundarySamples[] = $sample['value'];
                }
            }
        }

        // Las últimas muestras antes de perder presencia describen la frontera.
        // Se usa el máximo de las últimas 3 para no quedarnos cortos por una
        // lectura ruidosa; la verificación exterior es la que decide al final.
        $tail = array_slice($boundarySamples, -3);
        $boundary = $tail === [] ? null : max($tail);
        $distance = RadarDistanceQuality::evaluate($boundarySamples);

        $clearSeconds = 0.0;
        if ($firstAbsentTs !== null && $endMs !== null) {
            $clearSeconds = max(0.0, ($endMs - $firstAbsentTs) / 1000);
        }

        $reason = self::REASON_OK;
        $sufficient = false;
        if ($configChanged) {
            $reason = self::REASON_CONFIG_CHANGED;
        } elseif ($presents === 0 && !$seedUsed) {
            $reason = self::REASON_NO_PRESENCE;
        } elseif ($firstAbsentTs === null) {
            $reason = self::REASON_NO_TRANSITION;
        } elseif ($boundary === null) {
            $reason = self::REASON_NO_DISTANCE;
        } elseif ($clearSeconds < $minClear && $absentAfter < $minAbsentReports) {
            $reason = self::REASON_WINDOW_TOO_SHORT;
        } else {
            $sufficient = true;
        }

        if ($seedState !== '' && !$seedFresh && in_array($seedState, ['PRESENT', 'ABSENT'], true)
            && $presents === 0) {
            $reason = self::REASON_STALE_SEED;
        }

        return [
            'sufficient' => $sufficient,
            'reason' => $reason,
            'config_version' => $configVersion,
            'config_changed' => $configChanged,
            'window' => ['started_at' => $windowStart, 'ended_at' => $windowEnd],
            'window_seconds' => round($windowSeconds, 3),
            'reports' => $count,
            'presents' => $presents,
            'absents' => $absents,
            'absent_after_present' => $absentAfter,
            'span_seconds' => round($span, 3),
            'first_present_at' => $firstPresentTs !== null ? self::isoFromMs($firstPresentTs) : null,
            'last_present_at' => $lastPresentTs !== null ? self::isoFromMs($lastPresentTs) : null,
            'first_absent_at' => $firstAbsentAt,
            'clear_seconds' => round($clearSeconds, 3),
            'boundary_distance' => $boundary,
            'boundary_samples' => array_map(static fn (float $v): float => round($v, 3), $boundarySamples),
            'distance' => $distance,
            'seed_used' => $seedUsed,
            'seed_state' => $seedState !== '' ? $seedState : null,
        ];
    }

    /** @param array<string,mixed> $settings */
    private static function settingsKey(array $settings): string
    {
        $keys = [
            'maximum_range', 'minimum_range', 'radar_sensitivity',
            'fading_time', 'detection_delay',
        ];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = isset($settings[$k]) && is_numeric($settings[$k])
                ? round((float) $settings[$k], 3) : null;
        }
        return json_encode($out, JSON_THROW_ON_ERROR);
    }

    private static function ms(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return $dt->getTimestamp() * 1000 + intdiv((int) $dt->format('u'), 1000);
        } catch (Throwable) {
            return null;
        }
    }

    private static function isoFromMs(int $ms): string
    {
        $dt = (new DateTimeImmutable('@' . intdiv($ms, 1000)))
            ->setTimezone(new DateTimeZone('UTC'));
        $fraction = str_pad((string) ($ms % 1000), 3, '0', STR_PAD_LEFT);
        return $dt->format('Y-m-d\TH:i:s') . '.' . $fraction . 'Z';
    }
}
