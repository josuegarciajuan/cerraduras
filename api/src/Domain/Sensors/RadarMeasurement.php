<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * RadarMeasurement — F102/RF-174.
 *
 * Evidencia de una ventana de calibración, ligada a una configuración concreta.
 * No decide el resultado global: resume lo observado y si la ventana es
 * suficiente. Una ventana contaminada por un cambio de configuración no puede
 * aprobar ni rechazar un candidato.
 */
final class RadarMeasurement
{
    /**
     * @param list<array<string,mixed>> $events
     * @param array<string,mixed> $expectedSettings
     * @param array{min_reports?:int,min_span_seconds?:int} $requirements
     * @return array<string,mixed>
     */
    public static function build(
        array $events,
        string $windowStart,
        string $windowEnd,
        int $configVersion,
        array $expectedSettings,
        array $requirements = []
    ): array {
        $startMs = self::ms($windowStart);
        $endMs = self::ms($windowEnd);
        $minReports = max(1, (int) ($requirements['min_reports'] ?? 8));
        $minSpan = max(0, (int) ($requirements['min_span_seconds'] ?? 10));

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
        $lastValue = $count > 0 ? (string) $reports[$count - 1]['value'] : null;
        $firstPresentAt = null;
        $lastPresentAt = null;
        foreach ($reports as $r) {
            if ($r['value'] !== 'PRESENT') {
                continue;
            }
            $dt = (new DateTimeImmutable('@' . intdiv((int) $r['ts'], 1000)))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z');
            $firstPresentAt ??= $dt;
            $lastPresentAt = $dt;
        }
        $presents = 0;
        $absents = 0;
        $samples = [];
        foreach ($reports as $r) {
            if ($r['value'] === 'PRESENT') {
                $presents++;
            } else {
                $absents++;
            }
            if (array_key_exists('target_distance', $r['settings'])) {
                $samples[] = (float) $r['settings']['target_distance'];
            }
        }
        $distance = RadarDistanceQuality::evaluate($samples);

        $sufficient = $count >= $minReports && $span >= $minSpan && !$configChanged;
        $reason = 'ok';
        if ($configChanged) {
            $reason = 'config_changed';
        } elseif ($count < $minReports) {
            $reason = 'not_enough_reports';
        } elseif ($span < $minSpan) {
            $reason = 'window_too_short';
        }

        return [
            'sufficient' => $sufficient,
            'reason' => $reason,
            'config_version' => $configVersion,
            'config_changed' => $configChanged,
            'window' => ['started_at' => $windowStart, 'ended_at' => $windowEnd],
            'reports' => $count,
            'valid_reports' => $distance['valid'],
            'zeros' => $distance['zeros'],
            'presence_reports' => $presents,
            'absent_reports' => $absents,
            'detected' => $presents > 0,
            'continuous' => $presents > 0 && $absents === 0,
            'last_value' => $lastValue,
            'first_present_at' => $firstPresentAt,
            'last_present_at' => $lastPresentAt,
            'span_seconds' => round($span, 3),
            'distance' => $distance,
        ];
    }

    /** @param array<string,mixed> $settings */
    private static function settingsKey(array $settings): string
    {
        $keys = ['maximum_range', 'minimum_range', 'radar_sensitivity'];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = isset($settings[$k]) && is_numeric($settings[$k]) ? round((float) $settings[$k], 3) : null;
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
}
