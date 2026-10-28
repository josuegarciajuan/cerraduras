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
