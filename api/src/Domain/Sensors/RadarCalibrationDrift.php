<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

/**
 * RadarCalibrationDrift — compara la calibración guardada con la configuración
 * que el radar reporta por push.
 *
 * Un corte de alimentación o una reconfiguración del dispositivo puede devolver
 * el radar a su base (p. ej. `maximum_range=6.0`) sin que la ficha del
 * dispositivo deje de indicar que está calibrado. Esta clase pura detecta esa
 * deriva para que el panel no afirme una calibración que ya no está activa.
 *
 * No hace I/O ni consulta el dispositivo: recibe la foto guardada y el último
 * `meta.settings` recibido por push.
 */
final class RadarCalibrationDrift
{
    public const STATUS_APPLIED = 'applied';
    public const STATUS_MISMATCH = 'mismatch';
    public const STATUS_MISSING = 'missing';
    public const STATUS_UNKNOWN = 'unknown';

    /** @var list<string> */
    public const KEYS = [
        'maximum_range',
        'minimum_range',
        'radar_sensitivity',
        'detection_delay',
        'fading_time',
    ];

    /**
     * @param array<string,mixed>|null $saved
     * @param array<string,mixed> $reported
     * @return array{status:string,saved:?array<string,mixed>,reported:array<string,mixed>,differences:list<array{key:string,saved:float|null,reported:float|null}>}
     */
    public static function evaluate(?array $saved, array $reported): array
    {
        if ($saved === null) {
            return [
                'status' => self::STATUS_MISSING,
                'saved' => null,
                'reported' => $reported,
                'differences' => [],
            ];
        }

        if ($reported === []) {
            return [
                'status' => self::STATUS_UNKNOWN,
                'saved' => $saved,
                'reported' => [],
                'differences' => [],
            ];
        }

        $differences = [];
        $allComparable = true;
        foreach (self::KEYS as $key) {
            $savedValue = self::number($saved[$key] ?? null);
            $reportedValue = self::number($reported[$key] ?? null);
            if ($savedValue === null || $reportedValue === null) {
                $allComparable = false;
                continue;
            }
            if (abs($savedValue - $reportedValue) > 0.001) {
                $differences[] = [
                    'key' => $key,
                    'saved' => $savedValue,
                    'reported' => $reportedValue,
                ];
            }
        }

        $status = match (true) {
            $differences !== [] => self::STATUS_MISMATCH,
            $allComparable => self::STATUS_APPLIED,
            default => self::STATUS_UNKNOWN,
        };

        return [
            'status' => $status,
            'saved' => $saved,
            'reported' => $reported,
            'differences' => $differences,
        ];
    }

    private static function number(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }
}
