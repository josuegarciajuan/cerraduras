<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * RadarMeasurement — F102/F103.
 *
 * Evidencia de una ventana de calibración, ligada a una configuración concreta.
 * El radar ZY-M100 es event-driven: solo publica cuando cambia algo. Por eso la
 * evidencia principal es el estado crudo al inicio de la ventana más la ausencia
 * de transiciones contradictorias. El mínimo histórico de 8 reportes/10 s se
 * conserva como modo de respaldo cuando no hay un estado inicial fresco.
 */
final class RadarMeasurement
{
    public const EVIDENCE_REPORTS = 'reports';
    public const EVIDENCE_STATE_HOLD = 'state_hold';
    public const EVIDENCE_TRANSITION = 'transition';

    /**
     * @param list<array<string,mixed>> $events
     * @param array<string,mixed> $expectedSettings
     * @param array{min_reports?:int,min_span_seconds?:int,require_new_present?:bool} $requirements
     * @param array{state?:string,occurred_at?:?string,age_seconds?:?int,fresh?:bool} $seed
     * @return array<string,mixed>
     */
    public static function build(
        array $events,
        string $windowStart,
        string $windowEnd,
        int $configVersion,
        array $expectedSettings,
        array $requirements = [],
        array $seed = []
    ): array {
        $startMs = self::ms($windowStart);
        $endMs = self::ms($windowEnd);
        $minReports = max(1, (int) ($requirements['min_reports'] ?? 8));
        $minSpan = max(0, (int) ($requirements['min_span_seconds'] ?? 10));
        $requireNewPresent = (bool) ($requirements['require_new_present'] ?? false);

        $seedState = strtoupper((string) ($seed['state'] ?? ''));
        $seedFresh = (bool) ($seed['fresh'] ?? false);
        $seedAge = isset($seed['age_seconds']) && is_numeric($seed['age_seconds'])
            ? (int) $seed['age_seconds'] : null;
        $seedAt = isset($seed['occurred_at']) && $seed['occurred_at'] !== null
            ? (string) $seed['occurred_at'] : null;
        $seedUsed = $seedFresh && in_array($seedState, ['PRESENT', 'ABSENT'], true);

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
            if ($settings !== [] && self::settingsKey($settings) !== $expected) {
                $configChanged = true;
            }
            $reports[] = ['ts' => $ts, 'value' => $value, 'settings' => $settings];
        }

        usort($reports, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
        $count = count($reports);
        $first = $count > 0 ? $reports[0]['ts'] : null;
        $last = $count > 0 ? $reports[$count - 1]['ts'] : null;
        $span = ($first !== null && $last !== null) ? max(0, ($last - $first) / 1000) : 0.0;
        $windowSeconds = ($startMs !== null && $endMs !== null) ? max(0, ($endMs - $startMs) / 1000) : 0.0;
        $lastValue = $count > 0 ? (string) $reports[$count - 1]['value'] : null;
        $lastState = $lastValue ?? ($seedUsed ? $seedState : null);

        $firstPresentAt = null;
        $lastPresentAt = null;
        $firstPresentTs = null;
        foreach ($reports as $r) {
            if ($r['value'] !== 'PRESENT') {
                continue;
            }
            $firstPresentAt ??= self::isoFromMs($r['ts']);
            $lastPresentAt = self::isoFromMs($r['ts']);
            $firstPresentTs ??= $r['ts'];
        }

        $presents = 0;
        $absents = 0;
        $absentsAfterFirstPresent = 0;
        $samples = [];
        foreach ($reports as $r) {
            if ($r['value'] === 'PRESENT') {
                $presents++;
            } else {
                $absents++;
                if ($firstPresentTs !== null && $r['ts'] > $firstPresentTs) {
                    $absentsAfterFirstPresent++;
                }
            }
            if (array_key_exists('target_distance', $r['settings'])) {
                $samples[] = (float) $r['settings']['target_distance'];
            }
        }
        $distance = RadarDistanceQuality::evaluate($samples);

        $seedPresent = $seedUsed && $seedState === 'PRESENT';
        if ($seedPresent) {
            // El estado PRESENT ya estaba en vigor al abrir la ventana: cualquier
            // ABSENT posterior es una pérdida de presencia.
            $absentsAfterFirstPresent = $absents;
            $firstPresentAt ??= self::isoFromTimestamp($seedAt);
            $lastPresentAt ??= self::isoFromTimestamp($seedAt);
        }
        $continuous = $lastState === 'PRESENT' && $absentsAfterFirstPresent === 0;
        $anyPresent = $presents > 0 || ($seedPresent && $lastState === 'PRESENT');
        $detected = $lastState === 'PRESENT';

        $evidenceMode = self::EVIDENCE_REPORTS;
        $sufficient = false;
        $reason = 'ok';
        if ($configChanged) {
            $reason = 'config_changed';
        } elseif ($requireNewPresent) {
            // Una reentrada exige una transición REAL dentro de la ventana: el
            // estado previo no demuestra que el radar vuelva a detectar.
            $sufficient = $presents > 0;
            $reason = $sufficient ? 'ok' : 'new_present_required';
            $evidenceMode = self::EVIDENCE_TRANSITION;
        } elseif ($count >= $minReports && $span >= $minSpan) {
            $sufficient = true;
        } elseif ($seedUsed && $windowSeconds >= $minSpan) {
            $sufficient = true;
            $evidenceMode = self::EVIDENCE_STATE_HOLD;
        } elseif ($seedState !== '' && !$seedFresh && in_array($seedState, ['PRESENT', 'ABSENT'], true)) {
            $reason = 'stale_state';
        } elseif ($seedUsed) {
            // Hay estado fresco, pero la ventana no llegó al mínimo de 10 s.
            $reason = 'window_too_short';
        } elseif ($count < $minReports) {
            $reason = 'not_enough_reports';
        } elseif ($span < $minSpan) {
            $reason = 'window_too_short';
        } else {
            $reason = 'insufficient';
        }

        return [
            'sufficient' => $sufficient,
            'reason' => $reason,
            'evidence_mode' => $evidenceMode,
            'config_version' => $configVersion,
            'config_changed' => $configChanged,
            'window' => ['started_at' => $windowStart, 'ended_at' => $windowEnd],
            'window_seconds' => round($windowSeconds, 3),
            'reports' => $count,
            'valid_reports' => $distance['valid'],
            'zeros' => $distance['zeros'],
            'presence_reports' => $presents,
            'absent_reports' => $absents,
            'detected' => $detected,
            'continuous' => $continuous,
            'any_present' => $anyPresent,
            'last_value' => $lastValue,
            'last_state' => $lastState,
            'seed_used' => $seedUsed,
            'seed_state' => $seedState !== '' ? $seedState : null,
            'seed_age_seconds' => $seedAge,
            'seed_at' => $seedUsed ? self::isoFromTimestamp($seedAt) : null,
            'first_present_at' => $firstPresentAt,
            'last_present_at' => $lastPresentAt,
            'span_seconds' => round($span, 3),
            'held_seconds' => $evidenceMode === self::EVIDENCE_STATE_HOLD ? round($windowSeconds, 3) : 0.0,
            'distance' => $distance,
        ];
    }

    /**
     * Resultado específico de la fase a partir de la medición cruda.
     *
     * - dentro: presencia continua (sin ABSENT tras el primer PRESENT)
     * - fuera fijo/pasillo: cualquier PRESENT es una detección exterior
     * - reentrada: PRESENT real en la ventana y último estado PRESENT
     *
     * @param array<string,mixed> $measurement
     */
    public static function outcomeForPhase(array $measurement, string $phase): bool
    {
        return match (strtoupper($phase)) {
            'INSIDE' => (bool) ($measurement['continuous'] ?? false),
            'OUTSIDE_FIXED', 'OUTSIDE_PASS' => (bool) ($measurement['any_present'] ?? false),
            'REENTRY' => ((int) ($measurement['presence_reports'] ?? 0) > 0)
                && (($measurement['last_value'] ?? '') === 'PRESENT'),
            default => (bool) ($measurement['detected'] ?? false),
        };
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

    private static function isoFromTimestamp(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ms = self::ms($value);
        return $ms === null ? $value : self::isoFromMs($ms);
    }
}
