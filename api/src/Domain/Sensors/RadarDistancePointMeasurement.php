<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * RadarDistancePointMeasurement — F107/RF-235.
 *
 * Mide un punto con reportes DP 9 independientes. La identidad de cada reporte
 * (`marker`) es la prueba de frescura; el valor puede repetirse. Dos distancias
 * iguales con marcas distintas son dos muestras; la misma marca es una sola.
 *
 * No usa presencia, semillas ni el estado combinado de Zigbee2MQTT.
 */
final class RadarDistancePointMeasurement
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_INSUFFICIENT = 'insufficient';
    public const STATUS_UNSTABLE = 'unstable';
    public const STATUS_NO_REPORTS = 'no_reports';
    public const STATUS_NO_DISTANCE = 'no_distance';
    public const STATUS_SHORT_WINDOW = 'short_window';

    /**
     * @param list<array<string,mixed>> $reports filas de radar_distance_reports
     * @param array{min_samples?:int,min_span_seconds?:int,tolerance?:float,coverage?:float,device_id?:?int,config_key?:?string} $options
     * @return array<string,mixed>
     */
    public static function analyze(
        array $reports,
        string $windowStart,
        string $windowEnd,
        array $options = []
    ): array {
        $startMs = self::ms($windowStart);
        $endMs = self::ms($windowEnd);
        $minSamples = max(2, (int) ($options['min_samples'] ?? 2));
        $minSpan = max(0, (int) ($options['min_span_seconds'] ?? 5));
        $tolerance = max(0.01, (float) ($options['tolerance'] ?? 0.30));
        $coverage = max(0.5, min(1.0, (float) ($options['coverage'] ?? 0.8)));
        $deviceId = isset($options['device_id']) && is_numeric($options['device_id'])
            ? (int) $options['device_id'] : null;
        $configKey = isset($options['config_key']) && is_string($options['config_key'])
            && $options['config_key'] !== '' ? $options['config_key'] : null;

        $seen = [];
        $inside = [];
        $discardedDevice = 0;
        $discardedConfig = 0;
        foreach ($reports as $report) {
            if (!is_array($report)) {
                continue;
            }
            $marker = (string) ($report['marker'] ?? '');
            $distance = $report['distance_m'] ?? null;
            $at = (string) ($report['received_at'] ?? '');
            $ts = self::ms($at);
            if ($marker === '' || !is_numeric($distance) || $ts === null) {
                continue;
            }
            if ($deviceId !== null && (int) ($report['device_id'] ?? 0) !== $deviceId) {
                $discardedDevice++;
                continue;
            }
            if ($configKey !== null && (string) ($report['config_key'] ?? '') !== $configKey) {
                $discardedConfig++;
                continue;
            }
            if (($startMs !== null && $ts < $startMs) || ($endMs !== null && $ts > $endMs)) {
                continue;
            }
            if (isset($seen[$marker])) {
                continue;
            }
            $seen[$marker] = true;
            $inside[] = [
                'marker' => $marker,
                'at' => $at,
                'ts' => $ts,
                'distance' => (float) $distance,
                'origin' => (string) ($report['origin'] ?? 'push'),
            ];
        }

        usort($inside, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);

        $positive = [];
        $zeros = 0;
        $queryReports = 0;
        $firstAt = null;
        $lastAt = null;
        $firstPositiveTs = null;
        $lastPositiveTs = null;
        foreach ($inside as $r) {
            $firstAt ??= $r['at'];
            $lastAt = $r['at'];
            if ($r['distance'] <= 0.0) {
                $zeros++;
                continue;
            }
            $positive[] = $r['distance'];
            $firstPositiveTs ??= $r['ts'];
            $lastPositiveTs = $r['ts'];
            if ($r['origin'] === 'query') {
                $queryReports++;
            }
        }

        // F108/RF-242: la cobertura se calcula SOLO con las muestras positivas
        // utilizadas. Un cero al principio no puede alargar artificialmente la
        // ventana de una distancia medida al final.
        $span = 0.0;
        if (count($positive) > 1 && $firstPositiveTs !== null && $lastPositiveTs !== null) {
            $span = max(0.0, ($lastPositiveTs - $firstPositiveTs) / 1000);
        }
        $reportSpan = 0.0;
        if (count($inside) > 1) {
            $reportSpan = max(0.0, ($inside[count($inside) - 1]['ts'] - $inside[0]['ts']) / 1000);
        }
        $cluster = RadarPointMeasurement::coherentCluster($positive, $tolerance);
        $coverageActual = $positive !== [] ? count($cluster) / count($positive) : 0.0;

        $status = self::STATUS_ACCEPTED;
        $reason = 'ok';
        if ($inside === []) {
            $status = self::STATUS_NO_REPORTS;
            $reason = 'no_reports';
        } elseif ($positive === []) {
            $status = self::STATUS_NO_DISTANCE;
            $reason = 'no_positive_distance';
        } elseif (count($positive) < $minSamples) {
            $status = self::STATUS_INSUFFICIENT;
            $reason = 'not_enough_reports';
        } elseif ($span < $minSpan) {
            $status = self::STATUS_SHORT_WINDOW;
            $reason = 'window_too_short';
        } elseif (count($cluster) < $minSamples) {
            $status = self::STATUS_UNSTABLE;
            $reason = 'incoherent_readings';
        } elseif ($coverageActual + 0.0001 < $coverage) {
            $status = self::STATUS_UNSTABLE;
            $reason = 'inconsistent_majority';
        }

        $accepted = $status === self::STATUS_ACCEPTED;
        $mean = $cluster !== [] ? round(array_sum($cluster) / count($cluster), 3) : null;
        $min = $cluster !== [] ? min($cluster) : null;
        $max = $cluster !== [] ? max($cluster) : null;
        $spread = $min !== null && $max !== null ? round($max - $min, 3) : null;
        $excluded = [];
        foreach ($positive as $value) {
            if (!in_array($value, $cluster, true)) {
                $excluded[] = $value;
            }
        }

        return [
            'status' => $status,
            'sufficient' => $accepted,
            'reason' => $reason,
            'window' => ['started_at' => $windowStart, 'ended_at' => $windowEnd],
            'window_seconds' => $startMs !== null && $endMs !== null
                ? max(0, (int) round(($endMs - $startMs) / 1000)) : null,
            'reports' => count($inside),
            'positive_reports' => count($positive),
            'zero_reports' => $zeros,
            'query_reports' => $queryReports,
            'first_report_at' => $firstAt,
            'last_report_at' => $lastAt,
            'span_seconds' => round($span, 3),
            'positive_span_seconds' => round($span, 3),
            'report_span_seconds' => round($reportSpan, 3),
            'positive_first_at' => $firstPositiveTs !== null ? self::isoFromMs($firstPositiveTs) : null,
            'positive_last_at' => $lastPositiveTs !== null ? self::isoFromMs($lastPositiveTs) : null,
            'discarded_device' => $discardedDevice,
            'discarded_config' => $discardedConfig,
            'readings' => array_map(static fn (array $r): array => [
                'at' => $r['at'],
                'distance' => round($r['distance'], 3),
                'origin' => $r['origin'],
            ], $inside),
            'cluster' => $cluster,
            'excluded' => $excluded,
            'coverage' => round($coverageActual, 3),
            'mean' => $mean,
            'min' => $min,
            'max' => $max,
            'spread' => $spread,
            'median' => $cluster !== [] ? self::median($cluster) : null,
            'tolerance' => $tolerance,
            'min_samples' => $minSamples,
            'min_span_seconds' => $minSpan,
        ];
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
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
        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . '.'
            . str_pad((string) ($ms % 1000), 3, '0', STR_PAD_LEFT) . 'Z';
    }
}
