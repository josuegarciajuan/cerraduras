<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * SensorCalibrationValidator — F94/RF-159.
 *
 * Reglas PURAS para validar un paso de la verificación física de sensores.
 * No hace I/O: recibe la ventana de eventos, el estado vivo, la configuración
 * del radar y los efectos observados, y devuelve PASS/FAIL/SKIP con motivo y
 * detalles. El catálogo (`sensor-tests.json`) solo declara `rule` + `params`.
 *
 * Contexto esperado:
 *   room_id, transport, elapsed_seconds,
 *   events: [{id,sensor,value,provider,occurred_at,received_at,applied,discard_reason}],
 *   live: {door_state,presence_state,presence_stale,presence_decision},
 *   settings: {radar_sensitivity,minimum_range,maximum_range,detection_delay,fading_time},
 *   effects: {visits,recordings,stays,access_events,anomalies,switch_on},
 *   effects_delta: mismas claves numéricas respecto al inicio del paso.
 */
final class SensorCalibrationValidator
{
    public const PASS = 'PASS';
    public const FAIL = 'FAIL';
    public const SKIP = 'SKIP';
    public const UNVERIFIED = 'UNVERIFIED';

    /** @return list<string> */
    public static function rules(): array
    {
        return [
            'door_cycle',
            'door_state_is',
            'no_applied',
            'presence_present',
            'presence_absent',
            'presence_hold',
            'presence_exit',
            'effects_unchanged',
            'settings_match',
            'settings_present',
            'panel_consistent',
            'door_full',
            'radar_inside',
            'radar_outside',
            'light_test',
            'manual',
        ];
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $params
     * @return array{status:string,reason:string,details:array<string,mixed>}
     */
    public static function validate(string $rule, array $params, array $ctx): array
    {
        if (!in_array($rule, self::rules(), true)) {
            return self::result(self::FAIL, 'unknown_rule:' . $rule, []);
        }

        return match ($rule) {
            'door_cycle' => self::doorCycle($params, $ctx),
            'door_state_is' => self::doorStateIs($params, $ctx),
            'no_applied' => self::noApplied($params, $ctx),
            'presence_present' => self::presencePresent($params, $ctx),
            'presence_absent' => self::presenceAbsent($params, $ctx),
            'presence_hold' => self::presenceHold($params, $ctx),
            'presence_exit' => self::presenceExit($params, $ctx),
            'effects_unchanged' => self::effectsUnchanged($params, $ctx),
            'settings_match' => self::settingsMatch($params, $ctx),
            'settings_present' => self::settingsPresent($params, $ctx),
            'panel_consistent' => self::panelConsistent($params, $ctx),
            'door_full' => self::doorFull($params, $ctx),
            'radar_inside' => self::radarInside($params, $ctx),
            'radar_outside' => self::radarOutside($params, $ctx),
            'light_test' => self::lightTest($params, $ctx),
            default => self::manual($params, $ctx),
        };
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $ctx */
    private static function doorCycle(array $params, array $ctx): array
    {
        $events = self::applied($ctx, 'PROXIMITY');
        $opens = self::countValue($events, 'OPEN');
        $closes = self::countValue($events, 'CLOSED');
        $needOpen = (int) ($params['open_required'] ?? 1);
        $needClose = (int) ($params['close_required'] ?? 1);
        $ordered = self::sorted($events);
        $last = $ordered === [] ? null : (string) $ordered[count($ordered) - 1]['value'];

        $details = ['opens' => $opens, 'closes' => $closes, 'last' => $last,
            'events' => count($events)];
        if ($opens !== $needOpen || $closes !== $needClose) {
            return self::result(self::FAIL, 'door_transitions_mismatch', $details);
        }
        if ($needClose > 0 && $last !== 'CLOSED') {
            return self::result(self::FAIL, 'door_not_closed_at_end', $details);
        }
        $details['live_door'] = $ctx['live']['door_state'] ?? null;
        return self::result(self::PASS, 'door_cycle_ok', $details);
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $ctx */
    private static function doorStateIs(array $params, array $ctx): array
    {
        $expected = strtoupper((string) ($params['state'] ?? ''));
        $live = strtoupper((string) ($ctx['live']['door_state'] ?? 'UNKNOWN'));
        $events = self::sorted(self::applied($ctx, 'PROXIMITY'));
        $last = $events === [] ? null : (string) $events[count($events) - 1]['value'];
        $details = ['expected' => $expected, 'live' => $live, 'last_applied' => $last];
        if (!in_array($expected, ['OPEN', 'CLOSED'], true)) {
            return self::result(self::FAIL, 'invalid_expectation', $details);
        }
        if ($live !== $expected) {
            return self::result(self::FAIL, 'door_state_mismatch', $details);
        }
        if ($last !== null && $last !== $expected) {
            return self::result(self::FAIL, 'door_event_contradicts_state', $details);
        }
        return self::result(self::PASS, 'door_state_ok', $details);
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $ctx */
    private static function noApplied(array $params, array $ctx): array
    {
        $sensors = array_map('strtoupper', (array) ($params['sensors'] ?? []));
        $values = array_map('strtoupper', (array) ($params['values'] ?? []));
        $matches = [];
        foreach ((array) ($ctx['events'] ?? []) as $e) {
            if (!is_array($e) || ($e['applied'] ?? false) !== true) {
                continue;
            }
            $sensor = strtoupper((string) ($e['sensor'] ?? ''));
            $value = strtoupper((string) ($e['value'] ?? ''));
            if ($sensors !== [] && !in_array($sensor, $sensors, true)) {
                continue;
            }
            if ($values !== [] && !in_array($value, $values, true)) {
                continue;
            }
            $matches[] = ['sensor' => $sensor, 'value' => $value,
                'occurred_at' => (string) ($e['occurred_at'] ?? '')];
        }
        $details = ['matches' => $matches, 'count' => count($matches)];
        if ($matches !== []) {
            return self::result(self::FAIL, 'unexpected_applied_events', $details);
        }
        return self::result(self::PASS, 'no_unexpected_events', $details);
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $ctx */
    private static function presencePresent(array $params, array $ctx): array
    {
        $events = self::applied($ctx, 'PRESENCE');
        $presents = self::countValue($events, 'PRESENT');
        $min = (int) ($params['min_applied_present'] ?? 1);
        $live = strtoupper((string) ($ctx['live']['presence_state'] ?? 'UNKNOWN'));
        $decision = is_array($ctx['live']['presence_decision'] ?? null)
            ? (string) ($ctx['live']['presence_decision']['state'] ?? '')
            : '';
        $stale = (bool) ($ctx['live']['presence_stale'] ?? false);
        $details = ['applied_present' => $presents, 'presence_state' => $live,
            'decision' => $decision, 'stale' => $stale];
        if ($presents < $min) {
            return self::result(self::FAIL, 'no_present_applied', $details);
        }
        if ($stale) {
            return self::result(self::FAIL, 'presence_stale', $details);
        }
        if ($live !== 'PRESENT' && $decision !== 'PRESENT_CONFIRMED') {
            return self::result(self::FAIL, 'presence_not_confirmed', $details);
        }
        return self::result(self::PASS, 'presence_ok', $details);
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $ctx */
    private static function presenceAbsent(array $params, array $ctx): array
    {
        $events = self::applied($ctx, 'PRESENCE');
        $presents = self::countValue($events, 'PRESENT');
        $absents = self::countValue($events, 'ABSENT');
        $maxPresent = (int) ($params['max_applied_present'] ?? 0);
        $requireAbsent = (bool) ($params['require_applied_absent'] ?? false);
        $live = strtoupper((string) ($ctx['live']['presence_state'] ?? 'UNKNOWN'));
        $details = ['applied_present' => $presents, 'applied_absent' => $absents,
            'presence_state' => $live];
        $minElapsed = (int) ($params['min_elapsed_seconds'] ?? 0);
        if ($minElapsed > 0 && (float) ($ctx['elapsed_seconds'] ?? 0) < $minElapsed) {
            $details['elapsed_seconds'] = (float) ($ctx['elapsed_seconds'] ?? 0);
            $details['min_elapsed_seconds'] = $minElapsed;
            return self::result(self::SKIP, 'step_too_short', $details);
        }
        if ($presents > $maxPresent) {
            return self::result(self::FAIL, 'present_applied_in_empty_scenario', $details);
        }
        // F94: en un escenario de sala vacía, quedarse en PRESENT también es un
        // falso positivo, aunque no haya una transición nueva en la ventana
        // (el radar repite el mismo valor y el evento se audita como noop).
        if (($params['require_final_absent'] ?? false) === true && $live !== 'ABSENT') {
            return self::result(self::FAIL, 'presence_not_absent_at_end', $details);
        }
        if ($requireAbsent && $absents < 1) {
            return self::result(self::FAIL, 'absent_not_applied', $details);
        }
        if ($requireAbsent && $live !== 'ABSENT') {
            return self::result(self::FAIL, 'presence_not_absent', $details);
        }
        $forbid = (array) ($params['forbid_effect_keys'] ?? []);
        if ($forbid !== []) {
            $delta = (array) ($ctx['effects_delta'] ?? []);
            foreach ($forbid as $key) {
                if ((int) ($delta[$key] ?? 0) !== 0) {
                    $details['effect_delta'] = $delta;
                    return self::result(self::FAIL, 'false_effect:' . $key, $details);
                }
            }
        }
        return self::result(self::PASS, 'absent_ok', $details);
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $ctx */
    private static function presenceHold(array $params, array $ctx): array
    {
        $events = self::applied($ctx, 'PRESENCE');
        $absents = self::countValue($events, 'ABSENT');
        $live = strtoupper((string) ($ctx['live']['presence_state'] ?? 'UNKNOWN'));
        $minSeconds = (int) ($params['min_seconds'] ?? 30);
        $elapsed = (float) ($ctx['elapsed_seconds'] ?? 0);
        $details = ['applied_absent' => $absents, 'presence_state' => $live,
            'elapsed_seconds' => $elapsed, 'min_seconds' => $minSeconds];
        if ($elapsed < $minSeconds) {
            return self::result(self::SKIP, 'step_too_short', $details);
        }
        if ($live !== 'PRESENT') {
            return self::result(self::FAIL, 'presence_lost_while_still', $details);
        }
        if ($absents > 0) {
            return self::result(self::FAIL, 'absent_applied_while_still', $details);
        }
        return self::result(self::PASS, 'presence_held', $details);
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $ctx */
    private static function presenceExit(array $params, array $ctx): array
    {
        $events = self::sorted(self::applied($ctx, 'PRESENCE'));
        $lastPresent = null;
        $firstAbsentAfter = null;
        foreach ($events as $e) {
            $ts = self::ms($e['occurred_at'] ?? null);
            if ($ts === null) {
                continue;
            }
            if (($e['value'] ?? '') === 'PRESENT') {
                $lastPresent = $ts;
                $firstAbsentAfter = null;
            } elseif (($e['value'] ?? '') === 'ABSENT' && $lastPresent !== null) {
                $firstAbsentAfter ??= $ts;
            }
        }
        $live = strtoupper((string) ($ctx['live']['presence_state'] ?? 'UNKNOWN'));
        $maxSeconds = (int) ($params['max_confirm_seconds'] ?? 60);
        $details = ['presence_state' => $live, 'max_confirm_seconds' => $maxSeconds];
        if ($lastPresent === null || $firstAbsentAfter === null) {
            return self::result(self::FAIL, 'exit_not_confirmed', $details);
        }
        $seconds = ($firstAbsentAfter - $lastPresent) / 1000;
        $details['seconds_from_present_to_absent'] = round($seconds, 3);
        if ($seconds > $maxSeconds) {
            return self::result(self::FAIL, 'exit_too_slow', $details);
        }
        if ($live !== 'ABSENT') {
            return self::result(self::FAIL, 'presence_not_absent', $details);
        }
        return self::result(self::PASS, 'exit_ok', $details);
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $ctx */
    private static function effectsUnchanged(array $params, array $ctx): array
    {
        $keys = (array) ($params['keys'] ?? []);
        $delta = (array) ($ctx['effects_delta'] ?? []);
        foreach ($keys as $key) {
            if ((int) ($delta[$key] ?? 0) !== 0) {
                return self::result(self::FAIL, 'effect_changed:' . $key, ['delta' => $delta]);
            }
        }
        return self::result(self::PASS, 'effects_unchanged', ['delta' => $delta]);
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $ctx */
    private static function settingsMatch(array $params, array $ctx): array
    {
        $expected = (array) ($params['expected'] ?? []);
        if (($params['expected_from'] ?? '') === 'initial_settings') {
            $expected = (array) ($ctx['initial_settings'] ?? []);
        }
        $settings = (array) ($ctx['settings'] ?? []);
        $mismatch = [];
        foreach ($expected as $key => $value) {
            $actual = $settings[$key] ?? null;
            if ($actual === null || abs((float) $actual - (float) $value) > 0.001) {
                $mismatch[$key] = ['expected' => $value, 'actual' => $actual];
            }
        }
        if ($mismatch !== []) {
            return self::result(self::FAIL, 'settings_mismatch', ['mismatch' => $mismatch]);
        }
        return self::result(self::PASS, 'settings_ok', ['settings' => $settings]);
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $ctx */
    private static function settingsPresent(array $params, array $ctx): array
    {
        $settings = (array) ($ctx['settings'] ?? []);
        $needed = (array) ($params['keys'] ?? ['maximum_range', 'radar_sensitivity']);
        $missing = [];
        foreach ($needed as $key) {
            if (!array_key_exists($key, $settings) || $settings[$key] === null) {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            return self::result(self::FAIL, 'settings_missing', ['missing' => $missing, 'settings' => $settings]);
        }
        $maxAge = (int) ($params['max_age_seconds'] ?? 300);
        $age = $ctx['settings_age_seconds'] ?? null;
        if ($age !== null && (int) $age > $maxAge) {
            return self::result(self::FAIL, 'settings_stale', ['age_seconds' => $age, 'settings' => $settings]);
        }
        return self::result(self::PASS, 'settings_present', ['settings' => $settings, 'age_seconds' => $age]);
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $ctx */
    private static function panelConsistent(array $params, array $ctx): array
    {
        $door = strtoupper((string) ($ctx['live']['door_state'] ?? 'UNKNOWN'));
        $presence = strtoupper((string) ($ctx['live']['presence_state'] ?? 'UNKNOWN'));
        $allowedDoor = array_map('strtoupper', (array) ($params['door_in'] ?? ['OPEN', 'CLOSED']));
        $allowedPresence = array_map('strtoupper', (array) ($params['presence_in'] ?? ['PRESENT', 'ABSENT']));
        $decision = $ctx['live']['presence_decision'] ?? null;
        $requireDecision = (bool) ($params['require_decision'] ?? false);
        $details = ['door' => $door, 'presence' => $presence, 'decision' => $decision];
        if (!in_array($door, $allowedDoor, true)) {
            return self::result(self::FAIL, 'panel_door_inconsistent', $details);
        }
        if (!in_array($presence, $allowedPresence, true)) {
            return self::result(self::FAIL, 'panel_presence_inconsistent', $details);
        }
        if ($requireDecision && !is_array($decision)) {
            return self::result(self::FAIL, 'panel_missing_decision', $details);
        }
        $stale = (bool) ($ctx['live']['presence_stale'] ?? false);
        if ($stale && in_array($presence, ['PRESENT', 'ABSENT'], true)) {
            return self::result(self::FAIL, 'panel_claims_state_with_stale_signal', $details);
        }
        return self::result(self::PASS, 'panel_ok', $details);
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $ctx */
    private static function manual(array $params, array $ctx): array
    {
        return self::result(self::PASS, (string) ($params['reason'] ?? 'manual_ok'), [
            'elapsed_seconds' => $ctx['elapsed_seconds'] ?? null,
            'events' => count((array) ($ctx['events'] ?? [])),
        ]);
    }

    /**
     * F95/RF-163: ciclo completo de la puerta.
     * Requiere estado inicial CLOSED, 3–4 pares OPEN→CLOSED alternos y final
     * CLOSED. Tolera una acción extra, pero no el flapping.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $ctx
     */
    private static function doorFull(array $params, array $ctx): array
    {
        $initial = strtoupper((string) ($ctx['initial_live']['door_state'] ?? 'UNKNOWN'));
        $end = strtoupper((string) ($ctx['live']['door_state'] ?? 'UNKNOWN'));
        $events = self::sorted(self::applied($ctx, 'PROXIMITY'));
        $sequence = array_map(static fn (array $e): string => (string) ($e['value'] ?? ''), $events);
        $pairs = 0;
        $alternationOk = count($sequence) > 0 && $sequence[0] === 'OPEN';
        for ($i = 0; $i < count($sequence); $i += 2) {
            if (($sequence[$i] ?? '') !== 'OPEN' || ($sequence[$i + 1] ?? '') !== 'CLOSED') {
                $alternationOk = false;
                break;
            }
            $pairs++;
        }
        if (count($sequence) % 2 !== 0) {
            $alternationOk = false;
        }
        $minPairs = (int) ($params['min_pairs'] ?? 3);
        $maxPairs = (int) ($params['max_pairs'] ?? 4);
        $details = [
            'initial_door_state' => $initial,
            'end_door_state' => $end,
            'sequence' => $sequence,
            'pairs' => $pairs,
            'door_polarity' => $ctx['door_polarity'] ?? null,
            'max_pairs' => $maxPairs,
        ];
        if ($initial !== 'CLOSED') {
            return self::result(self::FAIL, 'door_must_start_closed', $details);
        }
        if ($sequence === []) {
            return self::result(self::FAIL, 'no_door_events', $details);
        }
        if (!$alternationOk) {
            return self::result(self::FAIL, 'door_sequence_mismatch', $details);
        }
        if ($pairs < $minPairs) {
            return self::result(self::FAIL, 'door_too_few_cycles', $details);
        }
        if ($pairs > $maxPairs) {
            return self::result(self::FAIL, 'door_too_many_transitions', $details);
        }
        if ($end !== 'CLOSED') {
            return self::result(self::FAIL, 'door_not_closed_at_end', $details);
        }
        return self::result(self::PASS, 'door_ok', $details);
    }

    /**
     * F95/RF-164: presencia física dentro. Cuenta reportes REALES (aplicados o
     * descartados por no_context), no solo la decisión de dominio.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $ctx
     */
    private static function radarInside(array $params, array $ctx): array
    {
        $session = (array) ($ctx['calibration_session'] ?? []);
        if ((int) ($session['version'] ?? 0) >= 2) {
            return self::radarInsideV2($ctx, $session);
        }
        $hold = (int) ($params['hold_seconds'] ?? 30);
        $grace = (int) ($params['grace_seconds'] ?? 20);
        $real = self::realPresence($ctx);
        $since = self::ms($ctx['calibration_since'] ?? null);
        if ($since !== null) {
            $real = array_values(array_filter($real, static function (array $e) use ($since): bool {
                $ts = self::ms($e['occurred_at'] ?? null) ?? 0;
                return $ts >= $since;
            }));
        }
        $presents = array_values(array_filter($real, static fn (array $e): bool => ($e['value'] ?? '') === 'PRESENT'));
        $absents = array_values(array_filter($real, static fn (array $e): bool => ($e['value'] ?? '') === 'ABSENT'));
        $stale = (bool) ($ctx['live']['presence_stale'] ?? false);
        $details = [
            'real_presents' => count($presents),
            'real_absents' => count($absents),
            'hold_seconds' => $hold,
            'grace_seconds' => $grace,
            'stale' => $stale,
            'settings_age_seconds' => $ctx['settings_age_seconds'] ?? null,
            'calibration_inside' => $session['inside'] ?? null,
            'calibration_since' => $ctx['calibration_since'] ?? null,
        ];
        if (!empty($session['inside']) && ($session['inside']['presence'] ?? false) !== true) {
            return self::result(self::FAIL, 'inside_not_detected_calibration', $details);
        }
        if (!empty($session['inside_valid'])) {
            $iv = (array) $session['inside_valid'];
            if (($iv['sufficient'] ?? false) !== true || ($iv['detected'] ?? false) !== true) {
                return self::result(self::FAIL, 'inside_not_detected_calibration', $details);
            }
        }
        if ($stale) {
            return self::result(self::UNVERIFIED, 'presence_stale', $details);
        }
        if ($presents === []) {
            if ((float) ($ctx['elapsed_seconds'] ?? 0) < $grace) {
                return self::result(self::SKIP, 'step_too_short', $details);
            }
            return self::result(self::FAIL, 'no_presence_detected', $details);
        }
        $first = self::ms($presents[0]['occurred_at'] ?? null) ?? 0;
        $last = self::ms($presents[count($presents) - 1]['occurred_at'] ?? null) ?? 0;
        $span = max(0, ($last - $first) / 1000);
        $details['span_seconds'] = round($span, 3);
        $absentAfter = false;
        foreach ($absents as $a) {
            $ts = self::ms($a['occurred_at'] ?? null) ?? 0;
            if ($ts > $first) {
                $absentAfter = true;
                break;
            }
        }
        if ($absentAfter) {
            return self::result(self::FAIL, 'presence_not_continuous', $details);
        }
        if ($span < $hold) {
            return self::result(self::SKIP, 'hold_too_short', $details);
        }
        return self::result(self::PASS, 'presence_detected_inside', $details);
    }

    /**
     * F95/RF-165: fuera de la sala no debe haber detección física ni efectos.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $ctx
     */
    private static function radarOutside(array $params, array $ctx): array
    {
        $session = (array) ($ctx['calibration_session'] ?? []);
        if ((int) ($session['version'] ?? 0) >= 2) {
            return self::radarOutsideV2($params, $ctx, $session);
        }
        $minElapsed = (int) ($params['min_elapsed_seconds'] ?? 50);
        $grace = (int) ($params['grace_seconds'] ?? 20);
        $startedAt = self::ms($ctx['attempt_started_at'] ?? null);
        $since = self::ms($ctx['calibration_since'] ?? null);
        $real = self::realPresence($ctx);
        $reentryFirst = self::ms($session['reentry']['first_present_at'] ?? null);
        $presents = array_values(array_filter($real, static function (array $e) use ($startedAt, $grace, $since, $reentryFirst): bool {
            if (($e['value'] ?? '') !== 'PRESENT') {
                return false;
            }
            $ts = self::ms($e['occurred_at'] ?? null) ?? 0;
            if ($reentryFirst !== null && $ts >= $reentryFirst) {
                return false; // el PRESENT de reentrada no es una detección exterior
            }
            if ($since !== null) {
                return $ts >= $since;
            }
            if ($startedAt === null) {
                return true;
            }
            return $ts >= $startedAt + ($grace * 1000);
        }));
        $stale = (bool) ($ctx['live']['presence_stale'] ?? false);
        $live = strtoupper((string) ($ctx['live']['presence_state'] ?? 'UNKNOWN'));
        $decision = is_array($ctx['live']['presence_decision'] ?? null)
            ? (string) ($ctx['live']['presence_decision']['state'] ?? '') : '';
        $delta = (array) ($ctx['effects_delta'] ?? []);
        $details = [
            'real_presents' => count($presents),
            'presence_state' => $live,
            'decision' => $decision,
            'stale' => $stale,
            'elapsed_seconds' => $ctx['elapsed_seconds'] ?? null,
            'grace_seconds' => $grace,
            'effects_delta' => $delta,
            'calibration_outside' => $session['outside'] ?? null,
            'calibration_candidate' => $session['candidate'] ?? null,
            'calibration_reentry' => $session['reentry'] ?? null,
            'calibration_since' => $ctx['calibration_since'] ?? null,
        ];
        if (!empty($session['outside']) && ($session['outside']['presence'] ?? false) === true) {
            return self::result(self::FAIL, 'outside_detected_calibration', $details);
        }
        foreach (['outside_valid', 'outside_pass'] as $key) {
            if (empty($session[$key])) {
                continue;
            }
            $m = (array) $session[$key];
            if (($m['sufficient'] ?? false) !== true) {
                return self::result(self::FAIL, 'measure_insufficient:' . $key, $details);
            }
            if (($m['detected'] ?? false) === true) {
                return self::result(self::FAIL, 'outside_detected_calibration', $details);
            }
        }
        $newSession = (int) ($session['version'] ?? 0) >= 2;
        if ($newSession && empty($session['outside_valid'])) {
            return self::result(self::FAIL, 'outside_not_measured', $details);
        }
        if ($newSession && empty($session['outside_pass'])) {
            return self::result(self::FAIL, 'outside_pass_not_measured', $details);
        }
        if ($newSession && empty($session['inside_valid'])) {
            return self::result(self::FAIL, 'inside_not_measured', $details);
        }
        $hasAdjustment = !empty($session['candidates']);
        $candidateApplied = (bool) ($session['candidate']['applied'] ?? false);
        $reentryDetected = ($session['reentry']['detected'] ?? false) === true;
        if (($hasAdjustment || $candidateApplied) && !$reentryDetected) {
            return self::result(self::FAIL, 'reentry_not_verified', $details);
        }
        if (array_key_exists('settings_valid', $ctx) && $ctx['settings_valid'] !== true) {
            return self::result(self::FAIL, 'invalid_device_settings', $details);
        }
        if ((float) ($ctx['elapsed_seconds'] ?? 0) < $minElapsed) {
            return self::result(self::SKIP, 'step_too_short', $details);
        }
        if ($stale) {
            return self::result(self::UNVERIFIED, 'presence_stale', $details);
        }
        if ($presents !== []) {
            return self::result(self::FAIL, 'presence_detected_outside', $details);
        }
        foreach ((array) ($params['forbid_effect_keys'] ?? ['visits', 'recordings', 'stays']) as $key) {
            if ((int) ($delta[$key] ?? 0) !== 0) {
                return self::result(self::FAIL, 'false_effect:' . $key, $details);
            }
        }
        // Si hubo ajuste y reentrada verificada, el estado vivo es PRESENT
        // porque el técnico ya está dentro; la ausencia exterior se juzgó en la
        // ventana anterior a la reentrada.
        if ($candidateApplied && $reentryDetected) {
            return self::result(self::PASS, 'outside_and_reentry_ok', $details);
        }
        if (!in_array($live, ['ABSENT', 'UNKNOWN'], true) && $decision !== 'ABSENT_CONFIRMED') {
            return self::result(self::UNVERIFIED, 'absence_not_confirmed', $details);
        }
        if ($live === 'UNKNOWN' && $decision !== 'ABSENT_CONFIRMED') {
            return self::result(self::UNVERIFIED, 'absence_not_confirmed', $details);
        }
        return self::result(self::PASS, 'no_presence_outside', $details);
    }

    /**
     * F103/RF-210: con sesión v2 la medición interior es la autoridad. No se
     * vuelven a exigir 30 s de reportes crudos ni frescura fusionada.
     *
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $session
     */
    private static function radarInsideV2(array $ctx, array $session): array
    {
        $inside = (array) ($session['inside_valid'] ?? []);
        $details = [
            'session_version' => 2,
            'measurement' => $inside !== [] ? $inside : ($session['inside'] ?? null),
        ];
        if ($inside === []) {
            return self::result(self::FAIL, 'inside_not_measured', $details);
        }
        if (($inside['sufficient'] ?? false) !== true) {
            return self::result(self::FAIL, 'measure_insufficient:inside_valid', $details);
        }
        if (($inside['detected'] ?? false) !== true) {
            return self::result(self::FAIL, 'inside_not_detected_calibration', $details);
        }
        if (array_key_exists('settings_valid', $ctx) && $ctx['settings_valid'] !== true) {
            return self::result(self::FAIL, 'invalid_device_settings', $details);
        }
        return self::result(self::PASS, 'presence_detected_inside', $details);
    }

    /**
     * F103/RF-210: la fase fuera se valida con las tres ventanas de la sesión
     * (interior, exterior fijo y pasillo) y, si hubo ajuste, la reentrada. Los
     * efectos posteriores al clic de reentrada son esperados y se excluyen.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $session
     */
    private static function radarOutsideV2(array $params, array $ctx, array $session): array
    {
        $inside = (array) ($session['inside_valid'] ?? []);
        $outside = (array) ($session['outside_valid'] ?? []);
        $pass = (array) ($session['outside_pass'] ?? []);
        $reentry = (array) ($session['reentry'] ?? []);
        $candidates = (array) ($session['candidates'] ?? []);
        $cv = (int) ($session['config_version'] ?? 1);
        $details = [
            'session_version' => 2,
            'phase' => $session['phase'] ?? null,
            'result' => $session['result'] ?? null,
            'config_version' => $cv,
            'inside_valid' => $inside !== [] ? $inside : null,
            'outside_valid' => $outside !== [] ? $outside : null,
            'outside_pass' => $pass !== [] ? $pass : null,
            'reentry' => $reentry !== [] ? $reentry : null,
            'candidates' => count($candidates),
            'settings_valid' => $ctx['settings_valid'] ?? null,
            'effects_delta' => $ctx['effects_delta'] ?? [],
        ];
        if (($session['phase'] ?? '') !== 'DONE' || ($session['result'] ?? '') !== 'PASS') {
            return self::result(self::FAIL, 'calibration_not_done', $details);
        }
        if ($inside === []) {
            return self::result(self::FAIL, 'inside_not_measured', $details);
        }
        if (($inside['sufficient'] ?? false) !== true) {
            return self::result(self::FAIL, 'measure_insufficient:inside_valid', $details);
        }
        if (($inside['detected'] ?? false) !== true) {
            return self::result(self::FAIL, 'inside_not_detected_calibration', $details);
        }
        foreach (['outside_valid' => $outside, 'outside_pass' => $pass] as $key => $m) {
            if ($m === []) {
                return self::result(self::FAIL, $key . '_not_measured', $details);
            }
            if (($m['sufficient'] ?? false) !== true) {
                return self::result(self::FAIL, 'measure_insufficient:' . $key, $details);
            }
            if ((int) ($m['config_version'] ?? 0) !== $cv) {
                return self::result(self::FAIL, 'measure_config_mismatch:' . $key, $details);
            }
            if (($m['detected'] ?? false) === true) {
                return self::result(self::FAIL, 'outside_detected_calibration', $details);
            }
        }
        if ($candidates !== []) {
            if (($reentry['detected'] ?? false) !== true) {
                return self::result(self::FAIL, 'reentry_not_verified', $details);
            }
            if ((int) ($reentry['presence']['config_version'] ?? 0) !== $cv) {
                return self::result(self::FAIL, 'reentry_config_mismatch', $details);
            }
        }
        if (array_key_exists('settings_valid', $ctx) && $ctx['settings_valid'] !== true) {
            return self::result(self::FAIL, 'invalid_device_settings', $details);
        }

        // Efectos del tramo exterior: desde el inicio del intento hasta el clic
        // de reentrada. La visita creada al volver a entrar es esperada y no
        // debe invalidar la ausencia exterior.
        $initial = (array) ($ctx['initial_effects'] ?? []);
        $beforeReentry = (array) ($session['effects_before_reentry'] ?? []);
        $fallbackDelta = (array) ($ctx['effects_delta'] ?? []);
        $forbid = (array) ($params['forbid_effect_keys'] ?? ['visits', 'recordings', 'stays']);
        foreach ($forbid as $key) {
            $delta = ($candidates !== [] && $beforeReentry !== [] && $initial !== [])
                ? (int) ($beforeReentry[$key] ?? 0) - (int) ($initial[$key] ?? 0)
                : (int) ($fallbackDelta[$key] ?? 0);
            if ($delta > 0) {
                $details['false_effect'] = $key;
                return self::result(self::FAIL, 'false_effect:' . $key, $details);
            }
        }

        return self::result(
            self::PASS,
            $candidates !== [] ? 'outside_and_reentry_ok' : 'no_presence_outside',
            $details
        );
    }

    /**
     * F95/RF-166: luz ON/OFF con confirmación humana y telemetría opcional.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $ctx
     */
    private static function lightTest(array $params, array $ctx): array
    {
        $human = (array) ($ctx['human'] ?? []);
        $switch = (array) ($ctx['switch'] ?? []);
        $details = [
            'human_prep_off' => $human['prep_off'] ?? null,
            'human_on' => $human['on'] ?? null,
            'human_off' => $human['off'] ?? null,
            'switch_state' => $switch['state'] ?? 'UNKNOWN',
            'switch_state_at' => $switch['state_at'] ?? null,
            'telemetry_on' => $human['telemetry_on'] ?? null,
            'telemetry_off' => $human['telemetry_off'] ?? null,
        ];
        if (!($switch['present'] ?? false)) {
            return self::result(self::SKIP, 'no_switch', $details);
        }
        if (($params['require_prepare_off'] ?? false) && ($human['prep_off'] ?? null) !== true) {
            return self::result(self::FAIL, 'lamp_not_prepared_off', $details);
        }
        if (($human['on'] ?? null) !== true || ($human['off'] ?? null) !== true) {
            return self::result(self::FAIL, 'human_not_confirmed', $details);
        }
        if (($human['telemetry_on'] ?? null) === false || ($human['telemetry_off'] ?? null) === false) {
            return self::result(self::FAIL, 'telemetry_mismatch', $details);
        }
        return self::result(self::PASS, 'light_ok', $details);
    }

    /**
     * Reportes reales de presencia Zigbee (incluye descartes por contexto),
     * excluyendo resync y valores no de presencia.
     *
     * @param array<string,mixed> $ctx
     * @return list<array<string,mixed>>
     */
    private static function realPresence(array $ctx): array
    {
        $out = [];
        foreach ((array) ($ctx['events'] ?? []) as $e) {
            if (!is_array($e)) {
                continue;
            }
            if (strtoupper((string) ($e['sensor'] ?? '')) !== 'PRESENCE') {
                continue;
            }
            $provider = strtoupper((string) ($e['provider'] ?? ''));
            if (!in_array($provider, ['ZIGBEE2MQTT', 'TUYA'], true)) {
                continue;
            }
            $source = strtolower((string) (($e['meta'] ?? [])['source'] ?? ''));
            if ($source === 'resync') {
                continue;
            }
            $value = (string) ($e['value'] ?? '');
            if (!in_array($value, ['PRESENT', 'ABSENT'], true)) {
                continue;
            }
            $out[] = $e;
        }
        return self::sorted($out);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @param array<string,mixed> $ctx @return list<array<string,mixed>> */
    private static function applied(array $ctx, string $sensor): array
    {
        $out = [];
        foreach ((array) ($ctx['events'] ?? []) as $e) {
            if (!is_array($e) || ($e['applied'] ?? false) !== true) {
                continue;
            }
            if (strtoupper((string) ($e['sensor'] ?? '')) !== $sensor) {
                continue;
            }
            $out[] = $e;
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $events */
    private static function countValue(array $events, string $value): int
    {
        $n = 0;
        foreach ($events as $e) {
            if ((string) ($e['value'] ?? '') === $value) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return list<array<string,mixed>>
     */
    private static function sorted(array $events): array
    {
        usort($events, static function (array $a, array $b): int {
            $ta = self::ms($a['occurred_at'] ?? null) ?? 0;
            $tb = self::ms($b['occurred_at'] ?? null) ?? 0;
            return $ta <=> $tb;
        });
        return $events;
    }

    private static function ms(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return $dt->getTimestamp() * 1000 + intdiv((int) $dt->format('u'), 1000);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $details */
    private static function result(string $status, string $reason, array $details): array
    {
        return ['status' => $status, 'reason' => $reason, 'details' => $details];
    }
}
