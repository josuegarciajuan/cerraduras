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
        $reentryFailed = (bool) ($observed['reentry_failed'] ?? false);

        // Si el ajuste anterior dejó de detectar al reentrar, el candidato no
        // vale: priorizar recuperar detección antes que seguir bajando alcance.
        if ($reentryFailed) {
            if ($currentSens < $caps['sens_max']) {
                return [
                    'action' => 'apply',
                    'params' => ['radar_sensitivity' => $currentSens + 1],
                    'reason' => 'reentry_failed_sensitivity',
                    'confidence' => self::confidence($inside, $outside),
                    'candidate' => null,
                ];
            }
            if ($currentMax < $caps['range_max'] - 0.001) {
                $next = self::snap($currentMax + $caps['range_step'], $caps);
                return [
                    'action' => 'apply',
                    'params' => ['maximum_range' => $next],
                    'reason' => 'reentry_failed_range',
                    'confidence' => self::confidence($inside, $outside),
                    'candidate' => $next,
                ];
            }
            return [
                'action' => 'reposition',
                'params' => [],
                'reason' => 'reentry_failed_limits',
                'confidence' => self::confidence($inside, $outside),
                'candidate' => null,
            ];
        }

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
        $insideLower = null;
        if ($insideUsable && isset($inside['max']) && $inside['max'] !== null) {
            $insideLower = (float) $inside['max'] + 0.30;
        }
        if ($outsidePresent) {
            if ($currentMax > $caps['range_min'] + 0.001) {
                $next = self::snap($currentMax - $caps['range_step'], $caps);
                if ($insideLower !== null && $next < $insideLower) {
                    return [
                        'action' => 'reposition',
                        'params' => [],
                        'reason' => 'would_break_inside',
                        'confidence' => self::confidence($inside, $outside),
                        'candidate' => null,
                    ];
                }
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

    /**
     * F104/RF-214: calibración automática por punto medio.
     *
     * `$inside` trae la referencia interior (distancia máxima donde SÍ debe
     * detectar) y `$scan` la frontera del barrido (última distancia detectada
     * antes de perder presencia). Si existe una separación útil, propone el
     * punto medio con márgenes; si no existe, se detiene sin bajar la
     * sensibilidad a ciegas.
     *
     * @param array{detected?:bool,sufficient?:bool,reference?:?float,distance?:array<string,mixed>,quality?:string} $inside
     * @param array{sufficient?:bool,boundary_distance?:?float,reason?:string,distance?:array<string,mixed>} $scan
     * @param array{detected?:bool,reference?:?float,distance?:array<string,mixed>}|null $outside
     * @param array<string,mixed> $settings
     * @param array{range_min:float,range_max:float,range_step:float,sens_min:int,sens_max:int,min_separation?:float,margin?:float,coarse_step?:float} $caps
     * @param array{reentry_failed?:bool,inside_present?:bool,attempts?:int,tried?:list<array<string,mixed>>} $observed
     * @return array{action:string,params:array<string,int|float>,reason:string,confidence:string,candidate:?float,inside_reference:?float,boundary:?float,outside_point:?float}
     */
    public static function planMidpoint(
        array $inside,
        array $scan,
        ?array $outside,
        array $settings,
        array $caps,
        array $observed = []
    ): array {
        $currentMax = (float) ($settings['maximum_range'] ?? 0);
        $currentSens = (int) round((float) ($settings['radar_sensitivity'] ?? 0));
        $fineStep = max(0.01, (float) ($caps['range_step'] ?? 0.15));
        $coarseStep = max($fineStep, (float) ($caps['coarse_step'] ?? 0.75));
        $minSep = max($fineStep * 2, (float) ($caps['min_separation'] ?? 0.45));
        $margin = max($fineStep, min(0.3, (float) ($caps['margin'] ?? 0.15)));
        $tried = (array) ($observed['tried'] ?? []);
        $insidePresent = (bool) ($observed['inside_present'] ?? ($inside['detected'] ?? false));

        $I = self::ref($inside, 'inside');
        $B = isset($scan['boundary_distance']) && is_numeric($scan['boundary_distance'])
            ? (float) $scan['boundary_distance'] : null;
        $outsidePresent = (bool) ($observed['outside_present'] ?? ($outside['detected'] ?? false));
        $P = ($outsidePresent && is_array($outside)) ? self::ref($outside, 'outside') : null;
        $confidence = self::confidenceFromQuality(
            (string) ($inside['quality'] ?? ($inside['distance']['quality'] ?? RadarDistanceQuality::POOR)),
            (string) ($scan['distance']['quality'] ?? RadarDistanceQuality::POOR)
        );

        if ((bool) ($observed['reentry_failed'] ?? false)) {
            if ($currentSens < (int) $caps['sens_max']) {
                return self::result('apply', ['radar_sensitivity' => $currentSens + 1],
                    'reentry_failed_sensitivity', $confidence, null, $I, $B, $P);
            }
            if ($currentMax < (float) $caps['range_max'] - 0.001) {
                $next = self::snap(min((float) $caps['range_max'], $currentMax + $coarseStep), $caps);
                return self::result('apply', ['maximum_range' => $next],
                    'reentry_failed_range', $confidence, $next, $I, $B, $P);
            }
            return self::result('stop', [], 'reentry_failed_limits', $confidence, null, $I, $B, $P);
        }

        if (!$insidePresent) {
            if ($currentSens < (int) $caps['sens_max']) {
                return self::result('apply', ['radar_sensitivity' => $currentSens + 1],
                    'inside_not_detected_sensitivity', $confidence, null, $I, $B, $P);
            }
            if ($currentMax < (float) $caps['range_max'] - 0.001) {
                $next = self::snap(min((float) $caps['range_max'], $currentMax + $coarseStep), $caps);
                return self::result('apply', ['maximum_range' => $next],
                    'inside_not_detected_range', $confidence, $next, $I, $B, $P);
            }
            return self::result('stop', [], 'inside_not_detected_limits', $confidence, null, $I, $B, $P);
        }

        if (($scan['sufficient'] ?? false) !== true) {
            return self::result('stop', [], 'scan_insufficient:' . (string) ($scan['reason'] ?? 'unknown'),
                $confidence, null, $I, $B, $P);
        }
        if ($I === null || $I <= 0) {
            return self::result('stop', [], 'inside_reference_missing', $confidence, null, $I, $B, $P);
        }
        if ($B === null || $B <= 0) {
            return self::result('stop', [], 'boundary_missing', $confidence, null, $I, $B, $P);
        }
        if ($B - $I < $minSep) {
            return self::result('stop', [], 'no_distance_separation', $confidence, null, $I, $B, $P);
        }
        if ($P !== null && $P - $I < $minSep) {
            return self::result('stop', [], 'no_distance_separation', $confidence, null, $I, $B, $P);
        }

        $floor = $I + $margin;
        $ceiling = $B - $margin;
        if ($P !== null) {
            $ceiling = min($ceiling, $P - $margin);
        }
        if ($floor > $ceiling + 0.001) {
            return self::result('stop', [], 'no_distance_separation', $confidence, null, $I, $B, $P);
        }
        $candidate = self::snap(max($floor, min($ceiling, ($I + $B) / 2)), $caps);
        $candidate = max($floor, min($ceiling, $candidate));

        if (abs($candidate - $currentMax) <= 0.001) {
            if ($outsidePresent) {
                return self::sensitivityFallback($currentSens, $caps, 'outside_still_detected',
                    $confidence, $candidate, $I, $B, $P);
            }
            return self::result('none', [], 'already_calibrated', $confidence, $candidate, $I, $B, $P);
        }

        foreach ($tried as $attempt) {
            if (!is_array($attempt)) {
                continue;
            }
            $params = (array) ($attempt['params'] ?? []);
            if (isset($params['maximum_range'])
                && abs((float) $params['maximum_range'] - $candidate) <= 0.001) {
                if ($outsidePresent) {
                    return self::sensitivityFallback($currentSens, $caps, 'outside_still_detected',
                        $confidence, $candidate, $I, $B, $P);
                }
                return self::result('stop', [], 'candidate_already_tried', $confidence, $candidate, $I, $B, $P);
            }
        }

        return self::result('apply', ['maximum_range' => $candidate],
            'midpoint', $confidence, $candidate, $I, $B, $P);
    }

    /**
     * F104.2: calibración usando una medición estática del punto exterior.
     *
     * El técnico se coloca donde NO debe detectarse. Con el alcance actual el
     * radar puede seguir detectándolo y reportar su distancia `P`. El candidato
     * es el punto medio entre la referencia interior `I` y `P`; no se camina
     * hasta que el radar pierda la presencia.
     *
     * Si el radar ya no detecta en el punto exterior, no hay distancia que
     * promediar: se mantiene el alcance actual (`outside_already_clear`).
     *
     * @param array{detected?:bool,sufficient?:bool,reference?:?float,distance?:array<string,mixed>,quality?:string} $inside
     * @param array{detected?:bool,reference?:?float,distance?:array<string,mixed>,quality?:string} $outside
     * @param array<string,mixed> $settings
     * @param array{range_min:float,range_max:float,range_step:float,sens_min:int,sens_max:int,min_separation?:float,margin?:float,coarse_step?:float} $caps
     * @param array{reentry_failed?:bool,inside_present?:bool,attempts?:int,tried?:list<array<string,mixed>>} $observed
     * @return array{action:string,params:array<string,int|float>,reason:string,confidence:string,candidate:?float,inside_reference:?float,boundary:?float,outside_point:?float}
     */
    public static function planFromOutsidePoint(
        array $inside,
        array $outside,
        array $settings,
        array $caps,
        array $observed = []
    ): array {
        $I = self::ref($inside, 'inside');
        $detected = (bool) ($outside['detected'] ?? false);
        $P = $detected ? self::ref($outside, 'outside') : null;
        $confidence = self::confidenceFromQuality(
            (string) ($inside['quality'] ?? ($inside['distance']['quality'] ?? RadarDistanceQuality::POOR)),
            (string) ($outside['quality'] ?? ($outside['distance']['quality'] ?? RadarDistanceQuality::POOR))
        );
        if (!$detected) {
            return self::result('none', [], 'outside_already_clear', $confidence, null, $I, null, null);
        }
        if ($P === null || $P <= 0) {
            return self::result('stop', [], 'outside_distance_missing', $confidence, null, $I, null, null);
        }
        $scan = [
            'sufficient' => true,
            'boundary_distance' => $P,
            'reason' => 'outside_point',
            'distance' => (array) ($outside['distance'] ?? []),
        ];
        $observed['outside_present'] = true;
        return self::planMidpoint($inside, $scan, $outside, $settings, $caps, $observed);
    }

    /**
     * Referencia de distancia: interior usa el máximo (debe detectar hasta ahí)
     * y exterior el mínimo (no debe detectar desde ahí).
     *
     * @param array<string,mixed> $measurement
     */
    public static function ref(array $measurement, string $kind): ?float
    {
        if (isset($measurement['reference']) && is_numeric($measurement['reference'])) {
            return (float) $measurement['reference'];
        }
        $distance = is_array($measurement['distance'] ?? null) ? $measurement['distance'] : [];
        if ($kind === 'inside') {
            if (isset($distance['max']) && is_numeric($distance['max'])) {
                return (float) $distance['max'];
            }
            return isset($distance['median']) && is_numeric($distance['median'])
                ? (float) $distance['median'] : null;
        }
        if (isset($distance['min']) && is_numeric($distance['min'])) {
            return (float) $distance['min'];
        }
        return isset($distance['median']) && is_numeric($distance['median'])
            ? (float) $distance['median'] : null;
    }

    /** @param array{range_min:float,range_max:float,range_step:float,sens_min:int,sens_max:int} $caps */
    private static function sensitivityFallback(
        int $currentSens,
        array $caps,
        string $reason,
        string $confidence,
        ?float $candidate,
        ?float $inside,
        ?float $boundary,
        ?float $outside
    ): array {
        if ($currentSens > (int) $caps['sens_min']) {
            return self::result('apply', ['radar_sensitivity' => $currentSens - 1],
                $reason . '_sensitivity', $confidence, $candidate, $inside, $boundary, $outside);
        }
        return self::result('stop', [], $reason . '_limits', $confidence, $candidate, $inside, $boundary, $outside);
    }

    /** @return array{action:string,params:array<string,int|float>,reason:string,confidence:string,candidate:?float,inside_reference:?float,boundary:?float,outside_point:?float} */
    private static function result(
        string $action,
        array $params,
        string $reason,
        string $confidence,
        ?float $candidate,
        ?float $inside,
        ?float $boundary,
        ?float $outside
    ): array {
        return [
            'action' => $action,
            'params' => $params,
            'reason' => $reason,
            'confidence' => $confidence,
            'candidate' => $candidate,
            'inside_reference' => $inside,
            'boundary' => $boundary,
            'outside_point' => $outside,
        ];
    }

    private static function confidenceFromQuality(string $inside, string $outside): string
    {
        $rank = [RadarDistanceQuality::POOR => 0, RadarDistanceQuality::FAIR => 1, RadarDistanceQuality::GOOD => 2];
        $a = $rank[$inside] ?? 0;
        $b = $rank[$outside] ?? 0;
        $min = min($a, $b);
        return $min >= 2 ? 'high' : ($min >= 1 ? 'medium' : 'low');
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
