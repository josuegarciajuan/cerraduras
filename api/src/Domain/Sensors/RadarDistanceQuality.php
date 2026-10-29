<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

/**
 * RadarDistanceQuality — F96/RF-170.
 *
 * Evalúa si las muestras de `target_distance` de una ventana sirven para
 * proponer un alcance. No convierte la distancia en una verdad absoluta:
 * devuelve una calidad y el motivo. Los valores 0 no se usan para la mediana,
 * pero sí para medir cuántas lecturas vienen vacías.
 */
final class RadarDistanceQuality
{
    public const GOOD = 'good';
    public const FAIR = 'fair';
    public const POOR = 'poor';

    /**
     * @param list<int|float> $samples
     * @return array{quality:string,samples:int,valid:int,zeros:int,zero_ratio:float,
     *               min:?float,max:?float,median:?float,spread:?float,reason:string}
     */
    public static function evaluate(array $samples): array
    {
        $values = [];
        $zeros = 0;
        $total = 0;
        foreach ($samples as $s) {
            if (!is_int($s) && !is_float($s)) {
                continue;
            }
            $total++;
            $v = (float) $s;
            if ($v <= 0.0) {
                $zeros++;
                continue;
            }
            $values[] = $v;
        }
        $valid = count($values);
        sort($values);
        $min = $valid > 0 ? $values[0] : null;
        $max = $valid > 0 ? $values[$valid - 1] : null;
        $median = null;
        if ($valid > 0) {
            $mid = intdiv($valid, 2);
            $median = $valid % 2 === 0
                ? ($values[$mid - 1] + $values[$mid]) / 2
                : $values[$mid];
        }
        $spread = ($min !== null && $max !== null) ? $max - $min : null;
        $zeroRatio = $total > 0 ? $zeros / $total : 1.0;

        $quality = self::POOR;
        $reason = 'sin_lecturas';
        if ($valid >= 5 && $zeroRatio <= 0.2 && $spread !== null && $spread <= 0.8) {
            $quality = self::GOOD;
            $reason = 'muestras_estables';
        } elseif ($valid >= 3 && $zeroRatio <= 0.5 && $spread !== null && $spread <= 1.5) {
            $quality = self::FAIR;
            $reason = 'muestras_orientativas';
        } elseif ($valid > 0) {
            $quality = self::POOR;
            $reason = $spread !== null && $spread > 1.5 ? 'dispersion_alta' : 'pocas_muestras';
        } elseif ($total > 0) {
            $quality = self::POOR;
            $reason = 'todas_cero';
        }

        return [
            'quality' => $quality,
            'samples' => $total,
            'valid' => $valid,
            'zeros' => $zeros,
            'zero_ratio' => round($zeroRatio, 3),
            'min' => $min,
            'max' => $max,
            'median' => $median,
            'spread' => $spread,
            'reason' => $reason,
        ];
    }
}
