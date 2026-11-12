<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use App\Domain\Sensors\RadarDistanceReport;

/**
 * F130 — admisibilidad operativa de un PRESENT del radar por alcance.
 *
 * El radar puede seguir detectando más allá de `maximum_range`: el firmware
 * informa ecos por reflexión o multitrayecto. `maximum_range` es el límite
 * operativo configurado, así que:
 *
 *   - DP9 nueva, correlacionada y <= máximo  → el PRESENT puede aplicarse.
 *   - DP9 nueva, correlacionada y >  máximo  → se convierte en ABSENT efectivo.
 *   - PRESENT sin DP9 nueva y correlacionada → no acredita presencia.
 *
 * Solo cuenta una lectura DP9 marcada en la MISMA trama (`meta.distance_report`)
 * y con la MISMA `config_key` que los ajustes reportados. `target_distance`
 * cacheado en el estado combinado de Zigbee2MQTT nunca es autoridad.
 *
 * La comparación se hace en centímetros enteros, sin margen oculto: el valor
 * igual al máximo se admite y un centímetro por encima se descarta.
 *
 * Clase pura: sin I/O, sin reloj global, sin BD ni dispositivo.
 */
final class RadarOperationalRangeDecision
{
    public const STATUS_IN_RANGE = 'in_range';
    public const STATUS_OUT_OF_RANGE = 'out_of_range';
    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const REASON_WITHIN_RANGE = 'within_configured_range';
    public const REASON_OUT_OF_RANGE = 'distance_above_maximum_range';
    public const REASON_NO_DP9 = 'no_dp9';
    public const REASON_ZERO_DP9 = 'zero_dp9';
    public const REASON_CONFIG_MISMATCH = 'config_mismatch';
    public const REASON_INVALID_MAXIMUM = 'invalid_maximum_range';
    public const REASON_NOT_RANGE_CAPABLE = 'not_range_capable';
    public const REASON_ABSENT = 'absent';

    public const DISCARD_UNVERIFIED = 'range_unverified';
    public const DISCARD_OUT_OF_RANGE = 'range_exceeded';

    public const MIN_MAXIMUM_RANGE_M = 0.15;
    public const MAX_MAXIMUM_RANGE_M = 9.5;
    public const PRECISION_M = 0.01;

    /**
     * @param array<string,mixed>|null $meta Metadatos canónicos de la trama.
     * @return array{
     *   status:string, reason:string, allow_present:bool, convert_to_absent:bool,
     *   discard_reason:?string, distance_m:?float, maximum_range_m:?float,
     *   distance_marker:?string, config_key:?string, precision_m:float
     * }
     */
    public static function evaluate(?string $presenceValue, ?array $meta): array
    {
        $value = strtoupper(trim((string) $presenceValue));
        if ($value !== PresenceEvent::VALUE_PRESENT) {
            return self::result(self::STATUS_NOT_APPLICABLE, self::REASON_ABSENT, true, false, null);
        }

        $meta = is_array($meta) ? $meta : [];
        $settings = is_array($meta['settings'] ?? null) ? $meta['settings'] : [];
        $report = is_array($meta['distance_report'] ?? null) ? $meta['distance_report'] : null;

        // Un dispositivo Zigbee de presencia sin configuración de radar no
        // tiene alcance que aplicar: se conserva su comportamiento anterior.
        if ($settings === [] && $report === null) {
            return self::result(
                self::STATUS_NOT_APPLICABLE,
                self::REASON_NOT_RANGE_CAPABLE,
                true,
                false,
                null
            );
        }

        $maximum = isset($settings['maximum_range']) && is_numeric($settings['maximum_range'])
            ? (float) $settings['maximum_range'] : null;
        if ($maximum === null
            || !is_finite($maximum)
            || $maximum < self::MIN_MAXIMUM_RANGE_M
            || $maximum > self::MAX_MAXIMUM_RANGE_M
        ) {
            return self::result(
                self::STATUS_UNVERIFIED,
                self::REASON_INVALID_MAXIMUM,
                false,
                false,
                self::DISCARD_UNVERIFIED
            );
        }

        if ($report === null) {
            return self::result(
                self::STATUS_UNVERIFIED,
                self::REASON_NO_DP9,
                false,
                false,
                self::DISCARD_UNVERIFIED,
                null,
                $maximum
            );
        }

        $marker = isset($report['marker']) && is_string($report['marker']) ? $report['marker'] : '';
        $distance = isset($report['distance_m']) && is_numeric($report['distance_m'])
            ? (float) $report['distance_m'] : null;
        if ($marker === '' || $distance === null || !is_finite($distance) || $distance < 0.0) {
            return self::result(
                self::STATUS_UNVERIFIED,
                self::REASON_NO_DP9,
                false,
                false,
                self::DISCARD_UNVERIFIED,
                null,
                $maximum
            );
        }
        if ($distance <= 0.0) {
            return self::result(
                self::STATUS_UNVERIFIED,
                self::REASON_ZERO_DP9,
                false,
                false,
                self::DISCARD_UNVERIFIED,
                $distance,
                $maximum,
                $marker
            );
        }

        // Correlación estricta: la huella debe corresponder a los ajustes que
        // viajan en la misma trama. Sin `config_key` no se puede atribuir.
        $configKey = RadarDistanceReport::configKey($settings);
        $reportKey = isset($report['config_key']) && is_string($report['config_key'])
            ? $report['config_key'] : '';
        if ($configKey === null || $reportKey === '' || !hash_equals($configKey, $reportKey)) {
            return self::result(
                self::STATUS_UNVERIFIED,
                self::REASON_CONFIG_MISMATCH,
                false,
                false,
                self::DISCARD_UNVERIFIED,
                $distance,
                $maximum,
                $marker
            );
        }

        // Si el frame trae además DP1 marcado, su configuración debe coincidir.
        $presenceReport = is_array($meta['presence_report'] ?? null) ? $meta['presence_report'] : null;
        if ($presenceReport !== null) {
            $presenceKey = isset($presenceReport['config_key']) && is_string($presenceReport['config_key'])
                ? $presenceReport['config_key'] : '';
            if ($presenceKey !== '' && !hash_equals($configKey, $presenceKey)) {
                return self::result(
                    self::STATUS_UNVERIFIED,
                    self::REASON_CONFIG_MISMATCH,
                    false,
                    false,
                    self::DISCARD_UNVERIFIED,
                    $distance,
                    $maximum,
                    $marker
                );
            }
        }

        if (self::toCm($distance) > self::toCm($maximum)) {
            return self::result(
                self::STATUS_OUT_OF_RANGE,
                self::REASON_OUT_OF_RANGE,
                false,
                true,
                self::DISCARD_OUT_OF_RANGE,
                $distance,
                $maximum,
                $marker
            );
        }

        return self::result(
            self::STATUS_IN_RANGE,
            self::REASON_WITHIN_RANGE,
            true,
            false,
            null,
            $distance,
            $maximum,
            $marker
        );
    }

    /** @return array<string,mixed> */
    private static function result(
        string $status,
        string $reason,
        bool $allowPresent,
        bool $convertToAbsent,
        ?string $discardReason,
        ?float $distance = null,
        ?float $maximum = null,
        ?string $marker = null
    ): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'allow_present' => $allowPresent,
            'convert_to_absent' => $convertToAbsent,
            'discard_reason' => $discardReason,
            'distance_m' => $distance !== null ? round($distance, 3) : null,
            'maximum_range_m' => $maximum !== null ? round($maximum, 3) : null,
            'distance_marker' => $marker,
            'config_key' => null,
            'precision_m' => self::PRECISION_M,
        ];
    }

    private static function toCm(float $meters): int
    {
        return (int) round($meters * 100.0);
    }
}
