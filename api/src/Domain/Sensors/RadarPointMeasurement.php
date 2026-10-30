<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * RadarPointMeasurement — F105/RF-221.
 *
 * Mide un punto físico (interior o exterior) con lecturas NUEVAS de distancia.
 * El radar ZY-M100-S_2 publica todo su estado en cada mensaje, incluido
 * `target_distance`; por eso un mensaje reciente no demuestra por sí solo que
 * la distancia se haya vuelto a medir. Aquí solo cuentan como lecturas nuevas:
 *
 * - la primera distancia positiva de la ventana;
 * - una distancia distinta de la positividad anterior;
 * - una distancia positiva posterior a un cero/ausencia.
 *
 * Un valor positivo repetido se conserva en la evidencia como
 * `repeated_value`, pero NO cuenta para completar el punto. Así nunca se
 * presentan dos copias de un dato cacheado como dos mediciones.
 *
 * Con las lecturas nuevas se elige el mayor conjunto cuya dispersión no supere
 * la tolerancia y se calcula su media. La media es la referencia del punto; no
 * se usa el máximo interior ni el mínimo exterior.
 */
final class RadarPointMeasurement
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_INSUFFICIENT = 'insufficient';
    public const STATUS_UNSTABLE = 'unstable';
    public const STATUS_CONFIG_CHANGED = 'config_changed';
    public const STATUS_NOT_DETECTED = 'not_detected';

    public const HELP_WAIT = 'wait';
    public const HELP_MOVE_ARMS = 'move_arms';
    public const HELP_REPEAT = 'repeat';
    public const HELP_POSITION = 'position';
    public const HELP_NONE = 'none';

    /**
     * @param list<array<string,mixed>> $events
     * @param array<string,mixed> $expectedSettings
     * @param array{min_samples?:int,tolerance?:float} $options
     * @return array<string,mixed>
     */
    public static function analyze(
        array $events,
        string $windowStart,
        string $windowEnd,
        int $configVersion,
        array $expectedSettings,
        array $options = []
    ): array {
        $startMs = self::ms($windowStart);
        $endMs = self::ms($windowEnd);
        $minSamples = max(2, (int) ($options['min_samples'] ?? 2));
        $tolerance = max(0.01, (float) ($options['tolerance'] ?? 0.30));
        $expectedKey = self::settingsKey($expectedSettings);

        $reports = [];
        $configChanged = false;
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
            if ($settings !== [] && self::settingsKey($settings) !== $expectedKey) {
                $configChanged = true;
            }
            $reports[] = [
                'ts' => $ts,
                'value' => $value,
                'distance' => array_key_exists('target_distance', $settings)
                    && is_numeric($settings['target_distance'])
                    ? (float) $settings['target_distance'] : null,
                'event_id' => (string) ($e['source_event_id'] ?? $e['event_id'] ?? ''),
            ];
        }

        usort($reports, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);

        $observations = [];
        $fresh = [];
        $positive = [];
        $seen = [];
        $previousPositive = null;
        $lastValue = null;
        $presents = 0;
        foreach ($reports as $r) {
            $lastValue = (string) $r['value'];
            if ($lastValue === 'PRESENT') {
                $presents++;
            } else {
                // Una ausencia rompe la continuidad de la distancia: una
                // positiva posterior es una observación nueva.
                $previousPositive = null;
            }
            $distance = $r['distance'];
            if ($distance === null) {
                continue;
            }
            // Deduplicación exacta: mismo instante y mismo valor no son dos
            // reportes independientes.
            $dedupe = $r['ts'] . '|' . number_format($distance, 3, '.', '');
            if (isset($seen[$dedupe])) {
                $observations[] = self::observation($r, $distance, false, 'duplicate');
                continue;
            }
            $seen[$dedupe] = true;

            if ($distance <= 0.0) {
                $observations[] = self::observation($r, $distance, false, 'zero');
                $previousPositive = null;
                continue;
            }
            $positive[] = $distance;
            $isFresh = $previousPositive === null
                || abs($distance - $previousPositive) > 0.005;
            $observations[] = self::observation($r, $distance, $isFresh,
                $isFresh ? 'new' : 'repeated_value');
            if ($isFresh) {
                $fresh[] = ['at' => self::isoFromMs($r['ts']), 'distance' => $distance];
            }
            $previousPositive = $distance;
        }

        $cluster = self::coherentCluster(array_column($fresh, 'distance'), $tolerance);
        $accepted = !$configChanged && count($cluster) >= $minSamples;
        $detected = $presents > 0 && $lastValue === 'PRESENT';
        $mean = $cluster !== [] ? round(self::mean($cluster), 3) : null;
        $min = $cluster !== [] ? min($cluster) : null;
        $max = $cluster !== [] ? max($cluster) : null;
        $spread = $min !== null && $max !== null ? round($max - $min, 3) : null;

        $status = self::STATUS_ACCEPTED;
        $reason = 'ok';
        $help = self::HELP_NONE;
        if ($configChanged) {
            $status = self::STATUS_CONFIG_CHANGED;
            $reason = 'config_changed';
            $help = self::HELP_REPEAT;
        } elseif (!$detected && $presents === 0 && $reports === []) {
            $status = self::STATUS_NOT_DETECTED;
            $reason = 'no_detection';
            $help = self::HELP_POSITION;
        } elseif (count($fresh) < $minSamples) {
            $status = self::STATUS_INSUFFICIENT;
            $reason = count($fresh) === 0
                ? ($observations === [] ? 'no_distance_readings' : 'no_new_distance_readings')
                : 'not_enough_fresh_readings';
            $help = self::HELP_MOVE_ARMS;
        } elseif (count($cluster) < $minSamples) {
            $status = self::STATUS_UNSTABLE;
            $reason = 'incoherent_readings';
            $help = self::HELP_REPEAT;
        }

        $excluded = [];
        foreach ($fresh as $f) {
            if (!in_array($f['distance'], $cluster, true)) {
                $excluded[] = $f;
            }
        }

        return [
            'status' => $status,
            'sufficient' => $accepted,
            'reason' => $reason,
            'help' => $help,
            'config_version' => $configVersion,
            'window' => ['started_at' => $windowStart, 'ended_at' => $windowEnd],
            'reports' => count($reports),
            'presence_reports' => $presents,
            'detected' => $detected,
            'last_value' => $lastValue,
            'observations' => $observations,
            'fresh_readings' => count($fresh),
            'readings' => $fresh,
            'all_positive_readings' => $positive,
            'cluster' => $cluster,
            'excluded' => $excluded,
            'mean' => $mean,
            'min' => $min,
            'max' => $max,
            'spread' => $spread,
            'median' => $cluster !== [] ? self::median($cluster) : null,
            'tolerance' => $tolerance,
            'min_samples' => $minSamples,
        ];
    }

    /**
     * Mayor subconjunto de lecturas cuya diferencia máxima no supera la
     * tolerancia. Empates: menor dispersión y después menor media. Es
     * determinista y no elige un valor para fabricar separación.
     *
     * @param list<float> $values
     * @return list<float>
     */
    public static function coherentCluster(array $values, float $tolerance): array
    {
        $values = array_values(array_filter($values, static fn ($v): bool => is_numeric($v) && (float) $v > 0));
        usort($values, static fn ($a, $b): int => (float) $a <=> (float) $b);
        $n = count($values);
        if ($n < 2) {
            return [];
        }
        $best = [];
        $bestSpread = INF;
        $bestMean = INF;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ((float) $values[$j] - (float) $values[$i] > $tolerance + 0.0001) {
                    break;
                }
                $candidate = array_slice($values, $i, $j - $i + 1);
                $spread = (float) $candidate[count($candidate) - 1] - (float) $candidate[0];
                $mean = self::mean(array_map('floatval', $candidate));
                $count = count($candidate);
                $bestCount = count($best);
                if ($count > $bestCount
                    || ($count === $bestCount && $spread < $bestSpread - 0.0001)
                    || ($count === $bestCount && abs($spread - $bestSpread) <= 0.0001 && $mean < $bestMean)) {
                    $best = array_map('floatval', $candidate);
                    $bestSpread = $spread;
                    $bestMean = $mean;
                }
            }
        }
        return $best;
    }

    /** @param list<float> $values */
    private static function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
    }

    /** @param array<string,mixed> $report */
    private static function observation(array $report, float $distance, bool $fresh, string $reason): array
    {
        return [
            'at' => self::isoFromMs((int) $report['ts']),
            'distance' => $distance,
            'fresh' => $fresh,
            'reason' => $reason,
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
