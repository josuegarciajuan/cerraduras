<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

use Throwable;

/**
 * RadarAutoTune — F125/RF-350…RF-355.
 *
 * Lógica PURA de los asistentes de calibración del radar Zigbee. No lee la BD,
 * no publica MQTT y no depende del reloj: recibe los reportes ya persistidos
 * (DP 9 = distancia, DP 1 = presencia) y devuelve una recomendación.
 *
 * Principios:
 *  - DP 9 solo se guarda con distancia positiva; sirve como señal de
 *    movimiento y de huella, nunca como ángulo.
 *  - El borde real se mide con la pérdida de presencia (DP 1), no con una
 *    cinta métrica: la distancia del último eco antes del ABSENT es el borde.
 *  - La ausencia de evidencia no se convierte en PASS.
 */
final class RadarAutoTune
{
    public const GRID_M = 0.15;
    public const DEFAULT_MARGIN_M = 0.30;
    public const BOUNDARY_LOOKBACK_SECONDS = 10;
    public const DEFAULT_SOAK_SECONDS = 60;

    /**
     * Ajusta un valor a la rejilla real del radar. La rejilla del parámetro
     * `maximum_range` está anclada en 0,15 m (no en el mínimo de la sala), que
     * es la misma que usa `ZigbeeRadarCalibrationService::normalizeCommands()`.
     */
    public static function snap(
        float $value,
        float $min,
        float $max,
        float $step = self::GRID_M,
        float $origin = self::GRID_M
    ): float {
        if ($step <= 0.0) {
            return max($min, min($max, $value));
        }
        $value = max($min, min($max, $value));
        $steps = round(($value - $origin) / $step);
        $snapped = round($origin + $steps * $step, 3);
        return max($min, min($max, $snapped));
    }

    /**
     * Borde empírico: distancia del último eco antes de la transición
     * PRESENT → ABSENT más reciente dentro de la ventana.
     *
     * @param list<array{received_at?:string,distance_m?:int|float}> $distances
     * @param list<array{received_at?:string,value?:string,marker?:string}> $presenceReports
     * @return array{found:bool,reason:string,distance_m:?float,samples:int,
     *               suggested_max:?float,measured_at:?string,margin_m:float}
     */
    public static function boundary(
        array $distances,
        array $presenceReports,
        string $since,
        string $now,
        float $margin = self::DEFAULT_MARGIN_M,
        float $minimumRange = 0.0,
        float $maximumRange = 9.5
    ): array {
        $sinceMs = self::toMs($since);
        $nowMs = self::toMs($now);
        if ($sinceMs === null || $nowMs === null) {
            return self::boundaryEmpty('invalid_window');
        }
        $margin = max(0.0, min(2.0, $margin));

        $presence = self::normalizePresence($presenceReports, $sinceMs, $nowMs);
        if (count($presence) < 2) {
            return self::boundaryEmpty('no_presence_transition');
        }
        $absentAt = null;
        for ($i = count($presence) - 1; $i > 0; $i--) {
            if ($presence[$i - 1]['value'] === 'PRESENT' && $presence[$i]['value'] === 'ABSENT') {
                $absentAt = $presence[$i]['t_ms'];
                break;
            }
        }
        if ($absentAt === null) {
            return self::boundaryEmpty('no_presence_transition');
        }

        $fromMs = $absentAt - self::BOUNDARY_LOOKBACK_SECONDS * 1000;
        $points = self::normalizeDistances($distances, $fromMs, $absentAt);
        if ($points === []) {
            // Ventana algo más amplia: el radar puede dejar de publicar DP 9
            // unos segundos antes del ABSENT.
            $fromMs = $absentAt - (self::BOUNDARY_LOOKBACK_SECONDS * 2) * 1000;
            $points = self::normalizeDistances($distances, $fromMs, $absentAt);
        }
        if ($points === []) {
            return self::boundaryEmpty('no_distance_before_absent');
        }

        // Últimos 3 ecos: la mediana evita que un pico aislado fije el borde
        // sin arrastrar ecos demasiado antiguos del inicio del paseo.
        $tail = array_slice(array_map(static fn (array $p): float => $p['distance_m'], $points), -3);
        $distance = self::median($tail);

        $min = max(0.15, $minimumRange + self::GRID_M);
        $max = max($min, min(9.5, $maximumRange));
        $suggested = self::snap($distance - $margin, $min, $max);

        return [
            'found' => true,
            'reason' => 'presence_lost',
            'distance_m' => round($distance, 3),
            'samples' => count($tail),
            'suggested_max' => $suggested,
            'measured_at' => self::iso($absentAt),
            'margin_m' => $margin,
        ];
    }

    /**
     * Recomendación de sensibilidad a partir de la tasa de movimiento (DP 9)
     * y de las transiciones reales de presencia (DP 1).
     *
     * @param list<array{received_at?:string,distance_m?:int|float}> $distances
     * @param list<array{received_at?:string,value?:string}> $presenceReports
     * @return array{value:?int,reason:string,confidence:string,stats:array<string,mixed>}
     */
    public static function sensitivity(
        array $distances,
        array $presenceReports,
        mixed $current,
        int $windowSeconds,
        string $now = ''
    ): array {
        $windowSeconds = max(5, min(300, $windowSeconds));
        $nowMs = $now !== '' ? self::toMs($now) : (int) round(microtime(true) * 1000);
        if ($nowMs === null) {
            $nowMs = (int) round(microtime(true) * 1000);
        }
        $sinceMs = $nowMs - $windowSeconds * 1000;
        $points = self::normalizeDistances($distances, $sinceMs, $nowMs);
        $presence = self::normalizePresence($presenceReports, $sinceMs, $nowMs);

        $moves = count($points);
        $ratePerMin = $windowSeconds > 0 ? round($moves / $windowSeconds * 60, 1) : 0.0;
        $transitions = 0;
        $presents = 0;
        for ($i = 0; $i < count($presence); $i++) {
            if ($presence[$i]['value'] === 'PRESENT') {
                $presents++;
            }
            if ($i > 0 && $presence[$i]['value'] !== $presence[$i - 1]['value']) {
                $transitions++;
            }
        }
        $latest = $presence === [] ? null : $presence[count($presence) - 1]['value'];
        $currentInt = is_numeric($current) ? (int) round((float) $current) : null;
        $stats = [
            'window_seconds' => $windowSeconds,
            'moves' => $moves,
            'rate_per_min' => $ratePerMin,
            'present_reports' => $presents,
            'presence_transitions' => $transitions,
            'latest_presence' => $latest,
        ];
        if ($currentInt === null) {
            return ['value' => null, 'reason' => 'current_unknown', 'confidence' => 'low', 'stats' => $stats];
        }

        if ($moves === 0 && $presents === 0) {
            // Sin evidencia no se sube la sensibilidad: en una sala vacía eso
            // solo aumentaría los falsos positivos. La UI pide moverse.
            return ['value' => null, 'reason' => 'no_motion_no_presence', 'confidence' => 'low', 'stats' => $stats];
        }
        if ($moves > 0 && $latest !== 'PRESENT' && $transitions === 0) {
            return ['value' => min(9, $currentInt + 2), 'reason' => 'motion_without_presence', 'confidence' => 'high', 'stats' => $stats];
        }
        if ($moves > 0 && $latest === 'PRESENT') {
            // Detecta de forma fiable. Si la tasa es muy alta y la sensibilidad
            // está al máximo, un paso menos reduce falsos positivos sin perder
            // la detección observada.
            $value = ($currentInt >= 9 && $ratePerMin >= 30) ? 8 : $currentInt;
            return ['value' => $value, 'reason' => 'detects_reliably', 'confidence' => 'high', 'stats' => $stats];
        }
        if ($presents > 0 && $moves === 0) {
            return ['value' => min(9, $currentInt + 1), 'reason' => 'presence_without_motion', 'confidence' => 'medium', 'stats' => $stats];
        }
        return ['value' => min(9, $currentInt + 1), 'reason' => 'marginal', 'confidence' => 'low', 'stats' => $stats];
    }

    /**
     * Veredicto del reposo exterior. Solo `clear` con ausencia explícita.
     *
     * @param list<array{received_at?:string,value?:string,marker?:string}> $presenceReports
     * @return array{result:string,reason:string,elapsed_seconds:int,remaining_seconds:int,
     *               presents:int,absents:int,last:?string}
     */
    public static function soak(
        array $presenceReports,
        string $since,
        string $now,
        int $minSeconds = self::DEFAULT_SOAK_SECONDS,
        ?string $baselineValue = null
    ): array {
        $sinceMs = self::toMs($since);
        $nowMs = self::toMs($now);
        if ($sinceMs === null || $nowMs === null) {
            return ['result' => 'inconclusive', 'reason' => 'invalid_window',
                'elapsed_seconds' => 0, 'remaining_seconds' => max(0, $minSeconds),
                'presents' => 0, 'absents' => 0, 'last' => null];
        }
        $elapsed = max(0, (int) round(($nowMs - $sinceMs) / 1000));
        $reports = self::normalizePresence($presenceReports, $sinceMs, $nowMs);
        $presents = 0;
        $absents = 0;
        $last = null;
        foreach ($reports as $r) {
            if ($r['value'] === 'PRESENT') {
                $presents++;
            } elseif ($r['value'] === 'ABSENT') {
                $absents++;
            }
            $last = $r['value'];
        }
        $base = [
            'elapsed_seconds' => $elapsed,
            'remaining_seconds' => max(0, $minSeconds - $elapsed),
            'presents' => $presents,
            'absents' => $absents,
            'last' => $last,
        ];
        if ($presents > 0) {
            return ['result' => 'detected', 'reason' => 'presence_outside'] + $base;
        }
        if ($elapsed < $minSeconds) {
            return ['result' => 'inconclusive', 'reason' => 'too_early'] + $base;
        }
        // DP 1 solo publica cuando cambia. Si al empezar el reposo el radar ya
        // decía AUSENTE y no ha llegado ningún PRESENT nuevo, el silencio es la
        // prueba de que sigue sin detectar.
        if ($baselineValue === 'PRESENT') {
            return ['result' => 'detected', 'reason' => 'presence_still_reported'] + $base;
        }
        if ($baselineValue === 'ABSENT') {
            return ['result' => 'clear', 'reason' => 'absence_confirmed'] + $base;
        }
        if ($absents >= 1 && $last === 'ABSENT') {
            return ['result' => 'clear', 'reason' => 'absence_confirmed'] + $base;
        }
        if ($reports === []) {
            return ['result' => 'inconclusive', 'reason' => 'no_reports'] + $base;
        }
        return ['result' => 'inconclusive', 'reason' => 'absence_unconfirmed'] + $base;
    }

    /**
     * Reentrada: exige un PRESENT con identidad posterior al inicio de la acción.
     *
     * @param list<array{received_at?:string,value?:string,marker?:string}> $presenceReports
     * @return array{result:string,reason:string,elapsed_seconds:int,remaining_seconds:int,marker:?string}
     */
    public static function reentry(
        array $presenceReports,
        string $since,
        string $now,
        string $baselineMarker = '',
        int $timeoutSeconds = 90
    ): array {
        $sinceMs = self::toMs($since);
        $nowMs = self::toMs($now);
        if ($sinceMs === null || $nowMs === null) {
            return ['result' => 'waiting', 'reason' => 'invalid_window', 'elapsed_seconds' => 0,
                'remaining_seconds' => max(0, $timeoutSeconds), 'marker' => null];
        }
        $elapsed = max(0, (int) round(($nowMs - $sinceMs) / 1000));
        $reports = self::normalizePresence($presenceReports, $sinceMs, $nowMs);
        foreach ($reports as $r) {
            if ($r['value'] !== 'PRESENT') {
                continue;
            }
            if ($baselineMarker !== '' && $r['marker'] === $baselineMarker) {
                continue;
            }
            return ['result' => 'entered', 'reason' => 'new_present', 'elapsed_seconds' => $elapsed,
                'remaining_seconds' => max(0, $timeoutSeconds - $elapsed), 'marker' => $r['marker']];
        }
        $remaining = max(0, $timeoutSeconds - $elapsed);
        if ($remaining === 0) {
            return ['result' => 'timeout', 'reason' => 'no_new_present', 'elapsed_seconds' => $elapsed,
                'remaining_seconds' => 0, 'marker' => null];
        }
        return ['result' => 'waiting', 'reason' => 'no_new_present', 'elapsed_seconds' => $elapsed,
            'remaining_seconds' => $remaining, 'marker' => null];
    }

    /**
     * @param list<array{received_at?:string,distance_m?:int|float}> $rows
     * @return list<array{t_ms:int,distance_m:float}>
     */
    public static function normalizeDistances(array $rows, int $sinceMs, int $untilMs): array
    {
        $out = [];
        foreach ($rows as $row) {
            $t = self::toMs($row['received_at'] ?? null);
            $d = $row['distance_m'] ?? null;
            if ($t === null || $t < $sinceMs || $t > $untilMs || !is_numeric($d) || (float) $d <= 0.0) {
                continue;
            }
            $out[] = ['t_ms' => $t, 'distance_m' => (float) $d];
        }
        usort($out, static fn (array $a, array $b): int => $a['t_ms'] <=> $b['t_ms']);
        return $out;
    }

    /**
     * @param list<array{received_at?:string,value?:string,marker?:string}> $rows
     * @return list<array{t_ms:int,value:string,marker:string}>
     */
    public static function normalizePresence(array $rows, int $sinceMs, int $untilMs): array
    {
        $out = [];
        foreach ($rows as $row) {
            $t = self::toMs($row['received_at'] ?? null);
            $value = strtoupper(trim((string) ($row['value'] ?? '')));
            if ($t === null || $t < $sinceMs || $t > $untilMs
                || !in_array($value, ['PRESENT', 'ABSENT'], true)) {
                continue;
            }
            $out[] = ['t_ms' => $t, 'value' => $value, 'marker' => (string) ($row['marker'] ?? '')];
        }
        usort($out, static fn (array $a, array $b): int => $a['t_ms'] <=> $b['t_ms']);
        return $out;
    }

    /** @param list<float|int> $values */
    public static function median(array $values): float
    {
        $values = array_values(array_map(static fn ($v): float => (float) $v, $values));
        sort($values, SORT_NUMERIC);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);
        return $n % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
    }

    public static function toMs(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            return $dt->getTimestamp() * 1000 + intdiv((int) $dt->format('u'), 1000);
        } catch (Throwable) {
            return null;
        }
    }

    private static function iso(int $ms): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', intdiv($ms, 1000));
    }

    /** @return array{found:bool,reason:string,distance_m:?float,samples:int,suggested_max:?float,measured_at:?string,margin_m:float} */
    private static function boundaryEmpty(string $reason): array
    {
        return ['found' => false, 'reason' => $reason, 'distance_m' => null, 'samples' => 0,
            'suggested_max' => null, 'measured_at' => null, 'margin_m' => self::DEFAULT_MARGIN_M];
    }
}
