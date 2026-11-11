<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

/**
 * RadarScope — F125/RF-350…RF-355.
 *
 * Construye la vista de "Radar Studio" a partir de datos ya persistidos:
 * configuración vigente, reportes DP 9 (distancia/movimiento), reportes DP 1
 * (presencia auténtica) y el estado crudo del último push.
 *
 * Es PURA: no lee la BD, no publica MQTT y no inventa ángulos. El radar solo
 * informa distancia; el dibujo muestra el alcance configurado y la huella
 * radial observada, nunca una dirección real.
 */
final class RadarScope
{
    public const GRID_M = 0.15;
    public const DEFAULT_MARGIN_M = 0.30;
    public const MOTION_WINDOW_SECONDS = 10;
    public const STATUS_OK = 'ok';
    public const STATUS_ATTENTION = 'attention';
    public const STATUS_BLOCKED = 'blocked';

    /**
     * @param array<string,mixed> $settings
     * @param list<array{received_at?:string,distance_m?:int|float}> $distances
     * @param list<array{received_at?:string,value?:string,marker?:string}> $presenceReports
     * @param array{state?:string,age_seconds?:int|null,stale?:bool,presence_report?:array<string,mixed>|null} $raw
     * @param array{now?:string,window_seconds?:int,margin_m?:float,settings_valid?:bool,settings_invalid_reason?:?string,
     *              presence_mode?:?string,door_state?:?string,discarded_uncorroborated?:int,active_operation?:?array} $options
     * @return array<string,mixed>
     */
    public static function build(
        array $settings,
        array $distances,
        array $presenceReports,
        array $raw,
        array $options = []
    ): array {
        $nowMs = RadarAutoTune::toMs($options['now'] ?? null) ?? (int) round(microtime(true) * 1000);
        $window = max(10, min(300, (int) ($options['window_seconds'] ?? 60)));
        $sinceMs = $nowMs - $window * 1000;
        $margin = isset($options['margin_m']) && is_numeric($options['margin_m'])
            ? max(0.0, min(2.0, (float) $options['margin_m']))
            : self::DEFAULT_MARGIN_M;

        $points = [];
        foreach ($distances as $row) {
            $t = RadarAutoTune::toMs($row['received_at'] ?? null);
            $d = $row['distance_m'] ?? null;
            if ($t === null || $t < $sinceMs || !is_numeric($d) || (float) $d <= 0.0) {
                continue;
            }
            $points[] = [
                't_ms' => $t,
                'age_s' => round(($nowMs - $t) / 1000, 1),
                'distance_m' => round((float) $d, 3),
            ];
        }
        usort($points, static fn (array $a, array $b): int => $a['t_ms'] <=> $b['t_ms']);

        $presence = [];
        foreach ($presenceReports as $row) {
            $t = RadarAutoTune::toMs($row['received_at'] ?? null);
            $value = strtoupper(trim((string) ($row['value'] ?? '')));
            if ($t === null || $t < $sinceMs || !in_array($value, ['PRESENT', 'ABSENT'], true)) {
                continue;
            }
            $presence[] = [
                't_ms' => $t,
                'age_s' => round(($nowMs - $t) / 1000, 1),
                'value' => $value,
                'marker' => (string) ($row['marker'] ?? ''),
            ];
        }
        usort($presence, static fn (array $a, array $b): int => $a['t_ms'] <=> $b['t_ms']);

        $values = array_map(static fn (array $p): float => (float) $p['distance_m'], $points);
        $firstMs = $points === [] ? null : $points[0]['t_ms'];
        $observed = $firstMs === null
            ? 0.0
            : max(5.0, min((float) $window, ($nowMs - $firstMs) / 1000));
        $rate = $observed > 0.0 ? round(count($points) / $observed * 60, 1) : 0.0;
        $last = $points === [] ? null : $points[count($points) - 1];
        $motionRecent = $last !== null && ((float) $last['age_s'] <= self::MOTION_WINDOW_SECONDS);

        $transitions = 0;
        $presentReports = 0;
        $presentSeconds = 0.0;
        for ($i = 0; $i < count($presence); $i++) {
            if ($presence[$i]['value'] === 'PRESENT') {
                $presentReports++;
            }
            if ($i > 0 && $presence[$i]['value'] !== $presence[$i - 1]['value']) {
                $transitions++;
            }
            $next = $presence[$i + 1]['t_ms'] ?? $nowMs;
            if ($presence[$i]['value'] === 'PRESENT') {
                $presentSeconds += max(0, ($next - $presence[$i]['t_ms']) / 1000);
            }
        }
        $latestPresence = $presence === [] ? null : $presence[count($presence) - 1];

        $boundary = RadarAutoTune::boundary(
            $distances,
            $presenceReports,
            self::iso($sinceMs),
            self::iso($nowMs),
            $margin,
            self::num($settings['minimum_range'] ?? 0.0) ?? 0.0,
            self::num($settings['maximum_range'] ?? 9.5) ?? 9.5
        );
        $sensitivity = RadarAutoTune::sensitivity(
            $distances,
            $presenceReports,
            $settings['radar_sensitivity'] ?? null,
            $window,
            self::iso($nowMs)
        );

        $rawState = strtoupper(trim((string) ($raw['state'] ?? 'UNKNOWN')));
        if (!in_array($rawState, ['PRESENT', 'ABSENT'], true)) {
            $rawState = 'UNKNOWN';
        }
        $rawAge = isset($raw['age_seconds']) && is_numeric($raw['age_seconds'])
            ? max(0, (int) $raw['age_seconds']) : null;
        $stale = (bool) ($raw['stale'] ?? false) || ($rawAge !== null && $rawAge > 180);

        $minimum = self::num($settings['minimum_range'] ?? null);
        $maximum = self::num($settings['maximum_range'] ?? null);
        $fading = self::num($settings['fading_time'] ?? null);
        $median = $values === [] ? null : RadarAutoTune::median($values);
        $p90 = $values === [] ? null : self::percentile($values, 90);
        $p10 = $values === [] ? null : self::percentile($values, 10);
        $stats = [
            'reports' => count($points),
            'rate_per_min' => $rate,
            'observed_seconds' => round($observed, 1),
            'last_age_s' => $last === null ? null : (float) $last['age_s'],
            'min' => $values === [] ? null : round(min($values), 3),
            'max' => $values === [] ? null : round(max($values), 3),
            'median' => $median === null ? null : round($median, 3),
            'p10' => $p10 === null ? null : round($p10, 3),
            'p90' => $p90 === null ? null : round($p90, 3),
            'spread' => ($p90 === null || $p10 === null) ? null : round($p90 - $p10, 3),
            'motion_recent' => $motionRecent,
            'present_reports' => $presentReports,
            'presence_transitions' => $transitions,
            'present_seconds' => round($presentSeconds, 1),
            'latest_presence' => $latestPresence['value'] ?? null,
            'latest_presence_age_s' => $latestPresence['age_s'] ?? null,
        ];

        $suggestions = [
            'maximum_range' => self::suggestMaximumRange($boundary, $p90, $minimum, $maximum, $margin),
            'radar_sensitivity' => $sensitivity,
        ];
        $diagnosis = self::diagnose(
            $settings,
            $stats,
            $rawState,
            $stale,
            $boundary,
            $options
        );
        $status = self::STATUS_OK;
        foreach ($diagnosis as $item) {
            if (($item['severity'] ?? '') === 'error') {
                $status = self::STATUS_BLOCKED;
                break;
            }
            if (($item['severity'] ?? '') === 'warn') {
                $status = self::STATUS_ATTENTION;
            }
        }
        if ($stale || ($stats['reports'] === 0 && $rawState === 'UNKNOWN')) {
            $status = self::STATUS_BLOCKED;
        }

        $scaleSource = max(
            $maximum ?? 0.0,
            ($p90 ?? 0.0) + 0.5,
            ($boundary['distance_m'] ?? 0.0) + 0.5,
            1.5
        );
        $maxScale = ceil($scaleSource * 2) / 2;

        return [
            'window_seconds' => $window,
            'settings' => self::numericSettings($settings),
            'settings_valid' => (bool) ($options['settings_valid'] ?? true),
            'settings_invalid_reason' => $options['settings_invalid_reason'] ?? null,
            'raw' => [
                'state' => $rawState,
                'age_seconds' => $rawAge,
                'stale' => $stale,
                'presence_report' => $raw['presence_report'] ?? null,
            ],
            'series' => array_slice($points, -300),
            'presence_series' => array_slice($presence, -100),
            'stats' => $stats,
            'boundary' => $boundary,
            'suggestions' => $suggestions,
            'diagnosis' => $diagnosis,
            'status' => $status,
            'axes' => ['max_m' => $maxScale, 'grid_m' => 0.5],
            'presence_mode' => $options['presence_mode'] ?? null,
            'door_state' => $options['door_state'] ?? null,
            'active_operation' => $options['active_operation'] ?? null,
            'server_ts' => self::iso($nowMs),
        ];
    }

    /**
     * @param array<string,mixed> $boundary
     * @return array{value:?float,reason:string,confidence:string}
     */
    private static function suggestMaximumRange(
        array $boundary,
        ?float $p90,
        ?float $minimum,
        ?float $maximum,
        float $margin
    ): array {
        $min = max(0.15, ($minimum ?? 0.0) + self::GRID_M);
        $max = max($min, min(9.5, $maximum ?? 9.5));
        if (($boundary['found'] ?? false) === true && isset($boundary['distance_m']) && is_numeric($boundary['distance_m'])) {
            $value = RadarAutoTune::snap((float) $boundary['distance_m'] - $margin, $min, $max);
            return [
                'value' => $value,
                'reason' => 'walk_boundary',
                'confidence' => ((int) ($boundary['samples'] ?? 0)) >= 3 ? 'high' : 'medium',
            ];
        }
        if ($p90 !== null && $p90 > 0.0) {
            $value = RadarAutoTune::snap($p90 + $margin, $min, $max);
            return ['value' => $value, 'reason' => 'observed_footprint', 'confidence' => 'low'];
        }
        return ['value' => null, 'reason' => 'no_evidence', 'confidence' => 'low'];
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $stats
     * @param array<string,mixed> $boundary
     * @param array<string,mixed> $options
     * @return list<array{code:string,severity:string,message:string}>
     */
    private static function diagnose(
        array $settings,
        array $stats,
        string $rawState,
        bool $stale,
        array $boundary,
        array $options
    ): array {
        $out = [];
        if (($options['settings_valid'] ?? true) === false) {
            $out[] = self::item('settings_invalid', 'error',
                'La configuración del radar no es válida (' . (string) ($options['settings_invalid_reason'] ?? 'desconocida') . '). Restaura la base antes de calibrar.');
        }
        if ($stale) {
            $out[] = self::item('no_reports', 'error',
                'El radar no envía datos recientes. Comprueba alimentación, red Zigbee y LQI antes de calibrar.');
        } elseif (($stats['reports'] ?? 0) === 0) {
            $out[] = self::item('no_motion', 'info',
                'Sin ecos de movimiento en la ventana (normal si la sala está vacía). Muévete delante del radar para comprobar que lo ve.');
        }
        $latest = in_array($rawState, ['PRESENT', 'ABSENT'], true)
            ? $rawState
            : ($stats['latest_presence'] ?? null);
        if (($stats['motion_recent'] ?? false) && $latest !== 'PRESENT') {
            $out[] = self::item('motion_without_presence', 'warn',
                'El radar detecta movimiento (hay ecos de distancia) pero no ha confirmado presencia. Suele ser sensibilidad, fading_time o la regla de fusión de la sala.');
        }
        if ($latest === 'PRESENT' && !($stats['motion_recent'] ?? false)) {
            $out[] = self::item('presence_without_motion', 'warn',
                'Hay presencia confirmada pero no llegan ecos de distancia recientes. La detección puede ser marginal o el radar estar reteniendo el estado.');
        }
        $maximum = self::num($settings['maximum_range'] ?? null);
        $minimum = self::num($settings['minimum_range'] ?? null);
        $p90 = isset($stats['p90']) && is_numeric($stats['p90']) ? (float) $stats['p90'] : null;
        $median = isset($stats['median']) && is_numeric($stats['median']) ? (float) $stats['median'] : null;
        if ($maximum !== null && $p90 !== null && $p90 >= $maximum - 0.05) {
            $out[] = self::item('distance_at_range_edge', 'warn',
                'La distancia observada (' . number_format($p90, 2, ',', '') . ' m) toca el alcance configurado ('
                . number_format($maximum, 2, ',', '') . ' m). Si detecta fuera, reduce el alcance; si no te detecta dentro, auméntalo.');
        }
        if ($minimum !== null && $median !== null && $median <= $minimum + self::GRID_M) {
            $out[] = self::item('below_minimum_range', 'warn',
                'La distancia típica (' . number_format($median, 2, ',', '') . ' m) está en el hueco del mínimo ('
                . number_format($minimum, 2, ',', '') . ' m). Baja el mínimo o aléjate del radar.');
        }
        $fading = self::num($settings['fading_time'] ?? null);
        if ($fading !== null && $fading < 5.0) {
            $out[] = self::item('fading_too_low', 'warn',
                'fading_time=' . number_format($fading, 1, ',', '') . ' s es muy bajo: la presencia puede parpadear. Para uso normal prueba 5–15 s.');
        }
        if (($options['door_state'] ?? '') === 'OPEN') {
            $out[] = self::item('door_open', 'warn',
                'La puerta está abierta: el radar puede verte a través del hueco y detectar el pasillo. Cierra la puerta para medir el exterior.');
        }
        $mode = (string) ($options['presence_mode'] ?? '');
        if (in_array($mode, ['camera_fusion', 'radar_door'], true)) {
            $out[] = self::item('fusion_can_discard', 'info',
                'La sala usa el modo "' . $mode . '": un PRESENT del radar puede descartarse si no hay cámara o puerta que lo corrobore. No significa que el radar no te vea.');
        }
        if ((int) ($options['discarded_uncorroborated'] ?? 0) > 0) {
            $out[] = self::item('presence_discarded', 'warn',
                'Hay ' . (int) $options['discarded_uncorroborated'] . ' reportes PRESENT descartados como "uncorroborated" en la ventana. Revisa el modo de presencia de la sala.');
        }
        if (($boundary['found'] ?? false) === true && isset($boundary['distance_m'])) {
            $out[] = self::item('boundary_measured', 'info',
                'Borde medido: ' . number_format((float) $boundary['distance_m'], 2, ',', '') . ' m. Sugerencia de alcance: '
                . ($boundary['suggested_max'] !== null ? number_format((float) $boundary['suggested_max'], 2, ',', '') . ' m' : '—') . '.');
        }
        return $out;
    }

    /** @return array{code:string,severity:string,message:string} */
    private static function item(string $code, string $severity, string $message): array
    {
        return ['code' => $code, 'severity' => $severity, 'message' => $message];
    }

    /** @param list<float|int> $values */
    private static function percentile(array $values, int $p): float
    {
        $values = array_values(array_map(static fn ($v): float => (float) $v, $values));
        sort($values, SORT_NUMERIC);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $rank = ($p / 100) * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return $values[$low];
        }
        $weight = $rank - $low;
        return $values[$low] * (1 - $weight) + $values[$high] * $weight;
    }

    /** @param array<string,mixed> $settings @return array<string,float|int> */
    private static function numericSettings(array $settings): array
    {
        $out = [];
        foreach (['maximum_range', 'minimum_range', 'radar_sensitivity', 'detection_delay', 'fading_time'] as $key) {
            if (isset($settings[$key]) && is_numeric($settings[$key])) {
                $out[$key] = $key === 'radar_sensitivity'
                    ? (int) round((float) $settings[$key])
                    : round((float) $settings[$key], 3);
            }
        }
        return $out;
    }

    private static function num(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function iso(int $ms): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', intdiv($ms, 1000));
    }
}
