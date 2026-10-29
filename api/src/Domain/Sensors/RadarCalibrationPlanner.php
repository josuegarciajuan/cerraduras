<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

/**
 * RadarCalibrationPlanner — F96/RF-171.
 *
 * Decide el siguiente ajuste del radar a partir de dos medidas: punto interior
 * (debe detectar) y punto exterior (no debe detectar). Si las distancias son
 * utilizables, propone un alcance entre ambos; si no, ajusta por comportamiento.
 * Es puro y no escribe en el dispositivo.
 */
final class RadarCalibrationPlanner
{
    /**
     * @param array{quality:string,min:?float,max:?float,median:?float} $inside
     * @param array{quality:string,min:?float,max:?float,median:?float} $outside
     * @param array{maximum_range?:float|int|null,radar_sensitivity?:float|int|null} $settings
     * @param array{range_min:float,range_max:float,range_step:float,sens_min:int,sens_max:int} $caps
     * @param array{inside_present?:bool,outside_present?:bool} $observed
     * @return array{action:string,params:array<string,int|float>,reason:string,confidence:string,candidate:?float}
     */
    public static function plan(
        array $inside,
        array $outside,
        array $settings,
        array $caps,
        array $observed = []
    ): array {
        $currentMax = (float) ($settings['maximum_range'] ?? 0);
        $currentSens = (int) round((float) ($settings['radar_sensitivity'] ?? 0));
        $insidePresent = (bool) ($observed['inside_present'] ?? false);
        $outsidePresent = (bool) ($observed['outside_present'] ?? false);

        $insideUsable = in_array($inside['quality'] ?? '', [RadarDistanceQuality::GOOD, RadarDistanceQuality::FAIR], true)
            && isset($inside['max']) && $inside['max'] !== null;
        $outsideUsable = in_array($outside['quality'] ?? '', [RadarDistanceQuality::GOOD, RadarDistanceQuality::FAIR], true)
            && isset($outside['min']) && $outside['min'] !== null;

        if ($insideUsable && $outsideUsable) {
            $insideMax = (float) $inside['max'];
            $outsideMin = (float) $outside['min'];
            if ($outsideMin - $insideMax >= 0.3) {
                $candidate = self::snap($outsideMin - 0.3, $caps);
                if ($candidate < $insideMax + 0.15) {
                    $candidate = self::snap($insideMax + 0.15, $caps);
                }
                if ($candidate > 0 && abs($candidate - $currentMax) > 0.001) {
                    return [
                        'action' => 'apply',
                        'params' => ['maximum_range' => $candidate],
                        'reason' => 'distance_separation',
                        'confidence' => self::confidence($inside, $outside),
                        'candidate' => $candidate,
                    ];
                }
                if ($insidePresent && !$outsidePresent) {
                    return self::none('distance_already_ok', self::confidence($inside, $outside), $currentMax);
                }
            }
        }

        // Comportamiento: no hay separación fiable por distancia.
        if ($outsidePresent) {
            if ($currentMax > $caps['range_min'] + 0.001) {
                $next = self::snap($currentMax - $caps['range_step'], $caps);
                return [
                    'action' => 'apply',
                    'params' => ['maximum_range' => $next],
                    'reason' => 'outside_detected',
                    'confidence' => self::confidence($inside, $outside),
                    'candidate' => $next,
                ];
            }
            if ($currentSens > $caps['sens_min']) {
                return [
                    'action' => 'apply',
                    'params' => ['radar_sensitivity' => $currentSens - 1],
                    'reason' => 'outside_detected_sensitivity',
                    'confidence' => self::confidence($inside, $outside),
                    'candidate' => null,
                ];
            }
            return [
                'action' => 'reposition',
                'params' => [],
                'reason' => 'outside_still_detected',
                'confidence' => self::confidence($inside, $outside),
                'candidate' => null,
            ];
        }

        if (!$insidePresent) {
            if ($currentSens < $caps['sens_max']) {
                return [
                    'action' => 'apply',
                    'params' => ['radar_sensitivity' => $currentSens + 1],
                    'reason' => 'inside_not_detected',
                    'confidence' => self::confidence($inside, $outside),
                    'candidate' => null,
                ];
            }
            if ($currentMax < $caps['range_max'] - 0.001) {
                $next = self::snap($currentMax + $caps['range_step'], $caps);
                return [
                    'action' => 'apply',
                    'params' => ['maximum_range' => $next],
                    'reason' => 'inside_not_detected_range',
                    'confidence' => self::confidence($inside, $outside),
                    'candidate' => $next,
                ];
            }
            return [
                'action' => 'reposition',
                'params' => [],
                'reason' => 'inside_not_detected_limits',
                'confidence' => self::confidence($inside, $outside),
                'candidate' => null,
            ];
        }

        return self::none('already_ok', self::confidence($inside, $outside), $currentMax);
    }

    /** @param array<string,mixed> $inside @param array<string,mixed> $outside */
    private static function confidence(array $inside, array $outside): string
    {
        $rank = [RadarDistanceQuality::POOR => 0, RadarDistanceQuality::FAIR => 1, RadarDistanceQuality::GOOD => 2];
        $a = $rank[$inside['quality'] ?? RadarDistanceQuality::POOR] ?? 0;
        $b = $rank[$outside['quality'] ?? RadarDistanceQuality::POOR] ?? 0;
        $min = min($a, $b);
        return $min >= 2 ? 'high' : ($min >= 1 ? 'medium' : 'low');
    }

    /** @param array{range_min:float,range_max:float,range_step:float,sens_min:int,sens_max:int} $caps */
    private static function snap(float $value, array $caps): float
    {
        $min = (float) $caps['range_min'];
        $max = (float) $caps['range_max'];
        $step = max(0.01, (float) $caps['range_step']);
        $value = max($min, min($max, $value));
        $steps = round(($value - $min) / $step);
        return round($min + $steps * $step, 3);
    }

    /** @return array{action:string,params:array<string,int|float>,reason:string,confidence:string,candidate:?float} */
    private static function none(string $reason, string $confidence, float $candidate): array
    {
        return ['action' => 'none', 'params' => [], 'reason' => $reason,
            'confidence' => $confidence, 'candidate' => $candidate];
    }
}
