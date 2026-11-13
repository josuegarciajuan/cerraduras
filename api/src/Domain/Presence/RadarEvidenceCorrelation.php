<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use App\Domain\Sensors\RadarDistanceReport;

/**
 * RadarEvidenceCorrelation — F133.
 *
 * Correlación acotada entre un episodio DP1 (`PRESENT` vigente) y una DP9 del
 * MISMO dispositivo/configuración, aunque lleguen en tramas distintas.
 *
 * Reglas:
 *   - ABSENT nunca se bloquea por falta de distancia.
 *   - Solo se acepta una DP9 positiva, marcada y con la configuración ORIGINAL
 *     del reporte (nunca la del estado combinado actual).
 *   - La DP9 debe pertenecer al episodio: misma sesión, no anterior a su
 *     inicio y dentro de la ventana/frescura configuradas.
 *   - Un marcador ya usado no se presenta como evidencia nueva.
 *   - La comparación con el máximo se hace en centímetros enteros.
 *
 * No hace I/O ni consulta el dispositivo. Clase pura y testeable.
 */
final class RadarEvidenceCorrelation
{
    public const STATUS_NOT_APPLICABLE = 'not_applicable';
    public const STATUS_USABLE        = 'usable';
    public const STATUS_NO_DISTANCE   = 'no_distance';
    public const STATUS_ZERO_DISTANCE = 'zero_distance';
    public const STATUS_CONFIG_MISMATCH = 'config_mismatch';
    public const STATUS_SESSION_MISMATCH = 'session_mismatch';
    public const STATUS_OUT_OF_RANGE  = 'out_of_range';
    public const STATUS_STALE         = 'stale_evidence';
    public const STATUS_CACHED        = 'cached_evidence';

    public const REASON_PRESENT_IN_RANGE = 'episode_evidence_in_range';
    public const REASON_NO_DP9          = 'no_dp9';
    public const REASON_ZERO_DP9        = 'zero_dp9';
    public const REASON_CONFIG_MISMATCH = 'config_mismatch';
    public const REASON_SESSION_MISMATCH = 'session_mismatch';
    public const REASON_OUT_OF_RANGE    = 'distance_above_maximum_range';
    public const REASON_STALE           = 'evidence_outside_episode';
    public const REASON_CACHED          = 'marker_already_used';
    public const REASON_ABSENT          = 'absent';
    public const REASON_DP1_WITHOUT_DISTANCE = 'dp1_without_distance';
    public const REASON_DOOR_DP1_WITHOUT_DISTANCE = 'dp1_without_dp9_door_corroborated';

    public const MIN_MAXIMUM_RANGE_M = 0.15;
    public const MAX_MAXIMUM_RANGE_M = 9.5;

    /**
     * @param array<string,mixed>|null $frameReport     DP9 de la trama actual.
     * @param array<string,mixed>|null $persistedReport DP9 ya persistido (config original).
     * @param array<string,mixed> $settings Configuración actual del radar.
     * @return array{
     *   status:string, reason:string, distance_m:?float, maximum_range_m:?float,
     *   marker:?string, config_key:?string, session_id:?string,
     *   age_seconds:?float, source:string, allow_present:bool, convert_to_absent:bool
     * }
     */
    public static function evaluate(
        string $presenceValue,
        ?string $episodeMarker,
        ?string $episodeSession,
        ?int $episodeStartedAtMs,
        ?string $usedDistanceMarker,
        ?array $frameReport,
        ?array $persistedReport,
        array $settings,
        int $nowMs,
        int $windowMs = 120000,
        int $maxAgeMs = 120000
    ): array {
        $value = strtoupper(trim($presenceValue));
        if ($value !== PresenceEvent::VALUE_PRESENT) {
            return self::result(self::STATUS_NOT_APPLICABLE, self::REASON_ABSENT, null, null, true, false);
        }

        $maximum = isset($settings['maximum_range']) && is_numeric($settings['maximum_range'])
            ? (float) $settings['maximum_range'] : null;
        if ($maximum === null || !is_finite($maximum)
            || $maximum < self::MIN_MAXIMUM_RANGE_M || $maximum > self::MAX_MAXIMUM_RANGE_M
        ) {
            return self::result(self::STATUS_CONFIG_MISMATCH, self::REASON_CONFIG_MISMATCH, null, $maximum, false, false);
        }

        // La fila persistida manda: conserva la configuración original del
        // marcador aunque la trama combinada traiga otra posterior.
        $report = $persistedReport ?? $frameReport;
        if ($report === null) {
            return self::result(self::STATUS_NO_DISTANCE, self::REASON_NO_DP9, null, $maximum, false, false);
        }
        $marker = isset($report['marker']) && is_string($report['marker']) ? $report['marker'] : '';
        $distance = isset($report['distance_m']) && is_numeric($report['distance_m'])
            ? (float) $report['distance_m'] : null;
        if ($marker === '' || $distance === null || !is_finite($distance) || $distance < 0.0) {
            return self::result(self::STATUS_NO_DISTANCE, self::REASON_NO_DP9, null, $maximum, false, false);
        }
        if ($distance <= 0.0) {
            return self::result(self::STATUS_ZERO_DISTANCE, self::REASON_ZERO_DP9, $distance, $maximum, false, false, $marker);
        }

        $configKey = RadarDistanceReport::configKey($settings);
        $reportKey = isset($report['config_key']) && is_string($report['config_key']) ? $report['config_key'] : '';
        if ($configKey === null || $reportKey === '' || !hash_equals($configKey, $reportKey)) {
            return self::result(self::STATUS_CONFIG_MISMATCH, self::REASON_CONFIG_MISMATCH, $distance, $maximum, false, false, $marker, $reportKey);
        }

        $reportSession = isset($report['session_id']) && is_string($report['session_id']) ? $report['session_id'] : '';
        if ($episodeSession !== null && $episodeSession !== '' && $reportSession !== ''
            && !hash_equals($episodeSession, $reportSession)
        ) {
            return self::result(self::STATUS_SESSION_MISMATCH, self::REASON_SESSION_MISMATCH, $distance, $maximum, false, false, $marker, $reportKey, $reportSession);
        }

        $reportMs = self::parseMs($report['received_at'] ?? null);
        if ($reportMs === null) {
            return self::result(self::STATUS_STALE, self::REASON_STALE, $distance, $maximum, false, false, $marker, $reportKey, $reportSession);
        }
        if ($episodeStartedAtMs !== null) {
            // Tolerancia corta para el orden de llegada entre DP1 y DP9.
            if ($reportMs < $episodeStartedAtMs - 2000) {
                return self::result(self::STATUS_STALE, self::REASON_STALE, $distance, $maximum, false, false, $marker, $reportKey, $reportSession);
            }
        } elseif ($windowMs > 0 && $reportMs < $nowMs - $windowMs) {
            return self::result(self::STATUS_STALE, self::REASON_STALE, $distance, $maximum, false, false, $marker, $reportKey, $reportSession);
        }
        if ($reportMs > $nowMs + 5000) {
            return self::result(self::STATUS_STALE, self::REASON_STALE, $distance, $maximum, false, false, $marker, $reportKey, $reportSession);
        }
        $ageMs = max(0, $nowMs - $reportMs);
        if ($maxAgeMs > 0 && $ageMs > $maxAgeMs) {
            return self::result(self::STATUS_STALE, self::REASON_STALE, $distance, $maximum, false, false, $marker, $reportKey, $reportSession);
        }
        if ($usedDistanceMarker !== null && $usedDistanceMarker !== ''
            && $usedDistanceMarker === $marker
        ) {
            return self::result(self::STATUS_CACHED, self::REASON_CACHED, $distance, $maximum, false, false, $marker, $reportKey, $reportSession, $ageMs);
        }
        if (self::toCm($distance) > self::toCm($maximum)) {
            return self::result(self::STATUS_OUT_OF_RANGE, self::REASON_OUT_OF_RANGE, $distance, $maximum, false, true, $marker, $reportKey, $reportSession, $ageMs);
        }

        return self::result(
            self::STATUS_USABLE,
            self::REASON_PRESENT_IN_RANGE,
            $distance,
            $maximum,
            true,
            false,
            $marker,
            $reportKey,
            $reportSession,
            $ageMs
        );
    }

    /**
     * F133.1 — respaldo acotado por DP1 cuando el radar detecta presencia pero
     * no publica una distancia positiva (DP9 = 0 o ausente).
     *
     * Solo se permite:
     *   - en una sala normal con política radar explícita, nunca almacén/TIMBRADO;
     *   - con un alcance máximo corto (por defecto <= 3,0 m);
     *   - tras mantener el mismo episodio DP1 durante la gracia indicada.
     *
     * No sustituye al límite de alcance: una DP9 positiva fuera del máximo
     * sigue convirtiendo la presencia en ausencia efectiva. Es un respaldo para
     * no quedar ciego cuando el firmware informa `PRESENT` sin distancia.
     */
    public static function dp1OnlyEligible(
        bool $explicitRadarRoom,
        bool $specialRoom,
        ?int $episodeStartedAtMs,
        int $nowMs,
        array $settings,
        bool $enabled = true,
        float $maxRangeM = 3.0,
        int $graceMs = 2000
    ): bool {
        if (!$enabled || !$explicitRadarRoom || $specialRoom || $episodeStartedAtMs === null) {
            return false;
        }
        $maximum = isset($settings['maximum_range']) && is_numeric($settings['maximum_range'])
            ? (float) $settings['maximum_range'] : null;
        if ($maximum === null || !is_finite($maximum) || $maximum <= 0.0 || $maximum > $maxRangeM) {
            return false;
        }
        return ($nowMs - $episodeStartedAtMs) >= max(0, $graceMs);
    }

    /**
     * F135: respaldo DP1 para `radar_door` cuando el radar no publica DP9
     * utilizable. La puerta reciente es la corroboración física; la decisión
     * de modo seguirá exigiendo continuidad. Una DP9 positiva fuera de
     * alcance se evalúa antes y sigue vetando.
     */
    public static function doorFallbackEligible(
        ?string $radarMode,
        bool $doorCorroborated,
        ?float $maximumRangeM,
        ?int $episodeStartedAtMs,
        int $nowMs,
        bool $enabled = true,
        int $graceMs = 1500
    ): bool {
        if (!$enabled || $radarMode !== 'radar_door' || !$doorCorroborated) {
            return false;
        }
        if ($maximumRangeM === null || !is_finite($maximumRangeM)
            || $maximumRangeM <= 0.0 || $maximumRangeM > self::MAX_MAXIMUM_RANGE_M
        ) {
            return false;
        }
        if ($episodeStartedAtMs === null) {
            return false;
        }
        return ($nowMs - $episodeStartedAtMs) >= max(0, $graceMs);
    }

    /** @return array<string,mixed> */
    private static function result(
        string $status,
        string $reason,
        ?float $distance,
        ?float $maximum,
        bool $allowPresent,
        bool $convertToAbsent,
        ?string $marker = null,
        ?string $configKey = null,
        ?string $sessionId = null,
        ?float $ageSeconds = null
    ): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'distance_m' => $distance !== null ? round($distance, 3) : null,
            'maximum_range_m' => $maximum !== null ? round($maximum, 3) : null,
            'marker' => $marker,
            'config_key' => $configKey,
            'session_id' => $sessionId,
            'age_seconds' => $ageSeconds !== null ? round($ageSeconds / 1000, 3) : null,
            'source' => $marker !== null ? 'episode_evidence' : 'none',
            'allow_present' => $allowPresent,
            'convert_to_absent' => $convertToAbsent,
        ];
    }

    private static function toCm(float $meters): int
    {
        return (int) round($meters * 100.0);
    }

    private static function parseMs(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        // Acepta MySQL (espacio) e ISO-8601 (T, con o sin Z). SIEMPRE en UTC:
        // el servidor puede estar en CET y strtotime() usaría su zona.
        $normalized = rtrim(str_replace('T', ' ', trim($value)), 'Z');
        $utc = new \DateTimeZone('UTC');
        foreach (['Y-m-d H:i:s.u', 'Y-m-d H:i:s'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $normalized, $utc);
            if ($dt !== false) {
                return $dt->getTimestamp() * 1000 + intdiv((int) $dt->format('u'), 1000);
            }
        }
        $ts = strtotime($normalized . ' UTC');
        return $ts === false ? null : $ts * 1000;
    }
}
