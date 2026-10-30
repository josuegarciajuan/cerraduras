<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

/**
 * RadarEvidencePolicy — F103/RF-207.
 *
 * Decide la evidencia de arranque de una medición del radar a partir de la
 * señal CRUDA del propio sensor (`presence_events`), no del estado fusionado
 * de la sala. En modo `camera_fusion` un PRESENT del radar puede descartarse
 * como candidato/`uncorroborated` sin actualizar `iot_sessions.presence_state`
 * ni `last_presence_event_at`; la calibración debe seguir viendo el sensor.
 */
final class RadarEvidencePolicy
{
    /**
     * @param array{value?:?string,settings_at?:?string,settings_age_seconds?:?int} $raw
     * @return array{state:?string,occurred_at:?string,age_seconds:?int,fresh:bool}
     */
    public static function seed(array $raw, int $staleSeconds): array
    {
        $state = strtoupper(trim((string) ($raw['value'] ?? '')));
        if (!in_array($state, ['PRESENT', 'ABSENT'], true)) {
            $state = '';
        }
        $age = isset($raw['settings_age_seconds']) && is_numeric($raw['settings_age_seconds'])
            ? max(0, (int) $raw['settings_age_seconds']) : null;
        $fresh = $state !== '' && $age !== null && $age <= max(1, $staleSeconds);
        return [
            'state' => $state !== '' ? $state : null,
            'occurred_at' => isset($raw['settings_at']) && $raw['settings_at'] !== null
                ? (string) $raw['settings_at'] : null,
            'age_seconds' => $age,
            'fresh' => $fresh,
        ];
    }

    /**
     * Impide abrir una ventana cuando el radar crudo lleva demasiado tiempo sin
     * reportar. Un estado desconocido se permite: la medición exigirá reportes.
     *
     * @param array{value?:?string,settings_age_seconds?:?int} $raw
     */
    public static function startBlockReason(array $raw, int $staleSeconds): ?string
    {
        $state = strtoupper(trim((string) ($raw['value'] ?? '')));
        if (!in_array($state, ['PRESENT', 'ABSENT'], true)) {
            return null;
        }
        $age = isset($raw['settings_age_seconds']) && is_numeric($raw['settings_age_seconds'])
            ? (int) $raw['settings_age_seconds'] : null;
        if ($age !== null && $age > max(1, $staleSeconds)) {
            return 'presence_stale';
        }
        return null;
    }

    /**
     * La reentrada solo es válida si el radar ya no detectaba antes de entrar.
     *
     * @param array{value?:?string} $raw
     */
    public static function reentryBlockReason(array $raw): ?string
    {
        $state = strtoupper(trim((string) ($raw['value'] ?? '')));
        return $state === 'PRESENT' ? 'reentry_not_clear' : null;
    }
}
