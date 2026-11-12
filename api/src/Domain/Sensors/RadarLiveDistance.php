<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

/**
 * RadarLiveDistance — F125.1.6.
 *
 * Selecciona la distancia positiva que puede mostrarse como ACTUAL en el
 * banner de Radar Studio. Una distancia vieja no se reutiliza: si ni el
 * `target_distance` del último push ni la última lectura DP9 positiva están
 * dentro del umbral de frescura, devuelve null.
 *
 * Es PURA: no lee la BD ni el reloj global si se le pasa `nowUtc`.
 */
final class RadarLiveDistance
{
    public const DEFAULT_MAX_AGE_SECONDS = 30;
    public const MIN_MAX_AGE_SECONDS = 5;
    public const MAX_MAX_AGE_SECONDS = 300;

    /**
     * @param array{distance_m?:int|float,received_at?:string}|null $latestReport
     * @return array{distance_m:?float,age_seconds:?int,at:?string,source:?string}
     */
    public static function choose(
        ?float $targetDistance,
        ?int $targetAgeSeconds,
        ?string $targetAt,
        ?array $latestReport,
        int $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS,
        ?string $nowUtc = null
    ): array {
        $maxAge = max(self::MIN_MAX_AGE_SECONDS, min(self::MAX_MAX_AGE_SECONDS, $maxAgeSeconds));
        $nowMs = RadarAutoTune::toMs($nowUtc) ?? (int) round(microtime(true) * 1000);

        $best = null;
        if ($targetDistance !== null && is_finite($targetDistance) && $targetDistance > 0.0
            && $targetAgeSeconds !== null && $targetAgeSeconds >= 0 && $targetAgeSeconds <= $maxAge) {
            $best = [
                'distance_m' => round($targetDistance, 3),
                'age_seconds' => $targetAgeSeconds,
                'at' => $targetAt,
                'source' => 'target',
            ];
        }

        if (is_array($latestReport)) {
            $reportDistance = isset($latestReport['distance_m']) && is_numeric($latestReport['distance_m'])
                ? (float) $latestReport['distance_m'] : null;
            $receivedAt = isset($latestReport['received_at']) && is_string($latestReport['received_at'])
                ? $latestReport['received_at'] : null;
            $receivedMs = $receivedAt !== null ? RadarAutoTune::toMs($receivedAt) : null;
            if ($reportDistance !== null && is_finite($reportDistance) && $reportDistance > 0.0
                && $receivedMs !== null) {
                $reportAge = max(0, (int) round(($nowMs - $receivedMs) / 1000));
                if ($reportAge <= $maxAge
                    && ($best === null || $reportAge < (int) $best['age_seconds'])) {
                    $best = [
                        'distance_m' => round($reportDistance, 3),
                        'age_seconds' => $reportAge,
                        'at' => $receivedAt,
                        'source' => 'report',
                    ];
                }
            }
        }

        return $best ?? [
            'distance_m' => null,
            'age_seconds' => null,
            'at' => null,
            'source' => null,
        ];
    }
}
