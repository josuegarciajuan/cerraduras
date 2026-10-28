<?php
declare(strict_types=1);

/**
 * F94/RF-159 — validador puro del asistente de sensores.
 *
 * Run:
 *   php tests/Unit/SensorCalibrationValidatorTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\SensorCalibrationValidator;

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }
function check(string $m, bool $c, string $d = ''): void { $c ? pass($m) : fail($m . ($d ? " — {$d}" : '')); }
function same(string $m, mixed $a, mixed $e): void
{
    if ($a === $e) { pass($m); return; }
    fail($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}

function ev(string $sensor, string $value, int $offset, bool $applied = true): array
{
    $t = (new DateTimeImmutable('2026-10-28T10:00:00Z'))->modify('+' . $offset . ' seconds');
    return [
        'id' => $offset + 1,
        'sensor' => $sensor,
        'value' => $value,
        'provider' => 'ZIGBEE2MQTT',
        'occurred_at' => $t->format('Y-m-d\TH:i:s.v\Z'),
        'received_at' => $t->format('Y-m-d\TH:i:s.v\Z'),
        'applied' => $applied,
        'discard_reason' => $applied ? null : 'duplicate',
    ];
}

function ctx(array $events = [], array $live = [], array $settings = [], array $delta = [], float $elapsed = 60.0): array
{
    return [
        'room_id' => 14,
        'transport' => 'ZIGBEE2MQTT',
        'events' => $events,
        'live' => array_merge([
            'door_state' => 'CLOSED',
            'presence_state' => 'ABSENT',
            'presence_stale' => false,
            'presence_decision' => null,
        ], $live),
        'settings' => $settings,
        'settings_age_seconds' => 5,
        'initial_settings' => ['maximum_range' => 6, 'radar_sensitivity' => 7],
        'effects' => [],
        'effects_delta' => array_merge(['visits' => 0, 'recordings' => 0, 'stays' => 0,
            'access_events' => 0, 'anomalies' => 0, 'switch_on' => 0], $delta),
        'elapsed_seconds' => $elapsed,
    ];
}
function v(string $rule, array $params, array $ctx): array
{
    return SensorCalibrationValidator::validate($rule, $params, $ctx);
}

echo "Sensor calibration validator (F94/RF-159)\n";

// ── door_cycle ─────────────────────────────────────────────────────────
$ok = v('door_cycle', [], ctx([ev('PROXIMITY', 'OPEN', 1), ev('PROXIMITY', 'CLOSED', 4)]));
same('door_cycle PASS', $ok['status'], 'PASS');
$missing = v('door_cycle', [], ctx([ev('PROXIMITY', 'OPEN', 1)]));
same('door_cycle FAIL si falta el cierre', $missing['status'], 'FAIL');
same('door_cycle motivo', $missing['reason'], 'door_transitions_mismatch');
$openAtEnd = v('door_cycle', [], ctx([ev('PROXIMITY', 'OPEN', 1), ev('PROXIMITY', 'CLOSED', 4), ev('PROXIMITY', 'OPEN', 8)]));
same('door_cycle FAIL si termina abierta', $openAtEnd['status'], 'FAIL');
$discarded = v('door_cycle', [], ctx([ev('PROXIMITY', 'OPEN', 1, false), ev('PROXIMITY', 'CLOSED', 4)]));
same('door_cycle ignora eventos descartados', $discarded['status'], 'FAIL');

// ── door_state_is ──────────────────────────────────────────────────────
same('door_state_is OPEN PASS', v('door_state_is', ['state' => 'OPEN'],
    ctx([ev('PROXIMITY', 'OPEN', 1)], ['door_state' => 'OPEN']))['status'], 'PASS');
same('door_state_is FAIL por estado', v('door_state_is', ['state' => 'OPEN'],
    ctx([], ['door_state' => 'CLOSED']))['status'], 'FAIL');

// ── no_applied ─────────────────────────────────────────────────────────
same('no_applied PASS sin eventos', v('no_applied',
    ['sensors' => ['PROXIMITY'], 'values' => ['OPEN', 'CLOSED']], ctx([]))['status'], 'PASS');
same('no_applied FAIL con OPEN aplicado', v('no_applied',
    ['sensors' => ['PROXIMITY'], 'values' => ['OPEN', 'CLOSED']],
    ctx([ev('PROXIMITY', 'OPEN', 2)]))['status'], 'FAIL');
same('no_applied ignora descartados', v('no_applied',
    ['sensors' => ['PROXIMITY'], 'values' => ['OPEN']],
    ctx([ev('PROXIMITY', 'OPEN', 2, false)]))['status'], 'PASS');

// ── presence_present ───────────────────────────────────────────────────
same('presence_present PASS', v('presence_present', ['min_applied_present' => 1],
    ctx([ev('PRESENCE', 'PRESENT', 1)], ['presence_state' => 'PRESENT']))['status'], 'PASS');
same('presence_present FAIL sin señal', v('presence_present', ['min_applied_present' => 1],
    ctx([]))['status'], 'FAIL');
same('presence_present FAIL si stale', v('presence_present', ['min_applied_present' => 1],
    ctx([ev('PRESENCE', 'PRESENT', 1)], ['presence_state' => 'PRESENT', 'presence_stale' => true]))['status'], 'FAIL');
same('presence_present acepta decisión confirmada', v('presence_present', ['min_applied_present' => 1],
    ctx([ev('PRESENCE', 'PRESENT', 1)], ['presence_state' => 'PRESENT',
        'presence_decision' => ['state' => 'PRESENT_CONFIRMED']]))['status'], 'PASS');

// ── presence_absent ────────────────────────────────────────────────────
same('presence_absent PASS sin PRESENT', v('presence_absent', ['max_applied_present' => 0],
    ctx([]))['status'], 'PASS');
same('presence_absent FAIL con PRESENT', v('presence_absent', ['max_applied_present' => 0],
    ctx([ev('PRESENCE', 'PRESENT', 3)]))['status'], 'FAIL');
same('presence_absent SKIP si el paso es corto', v('presence_absent',
    ['max_applied_present' => 0, 'min_elapsed_seconds' => 540], ctx([], [], [], [], 60))['status'], 'SKIP');
same('presence_absent FAIL si sigue PRESENT al final', v('presence_absent',
    ['max_applied_present' => 0, 'require_final_absent' => true],
    ctx([], ['presence_state' => 'PRESENT']))['status'], 'FAIL');
same('presence_absent PASS si termina ABSENT', v('presence_absent',
    ['max_applied_present' => 0, 'require_final_absent' => true],
    ctx([], ['presence_state' => 'ABSENT']))['status'], 'PASS');
same('presence_absent FAIL por efecto falso', v('presence_absent',
    ['max_applied_present' => 0, 'forbid_effect_keys' => ['visits']],
    ctx([], [], [], ['visits' => 1]))['status'], 'FAIL');
same('presence_absent exige ABSENT aplicado', v('presence_absent',
    ['max_applied_present' => 0, 'require_applied_absent' => true],
    ctx([ev('PRESENCE', 'ABSENT', 2)], ['presence_state' => 'ABSENT']))['status'], 'PASS');

// ── presence_hold ──────────────────────────────────────────────────────
same('presence_hold PASS', v('presence_hold', ['min_seconds' => 240],
    ctx([], ['presence_state' => 'PRESENT'], [], [], 300))['status'], 'PASS');
same('presence_hold FAIL si se pierde', v('presence_hold', ['min_seconds' => 240],
    ctx([], ['presence_state' => 'ABSENT'], [], [], 300))['status'], 'FAIL');
same('presence_hold FAIL si se aplica ABSENT', v('presence_hold', ['min_seconds' => 240],
    ctx([ev('PRESENCE', 'ABSENT', 10)], ['presence_state' => 'PRESENT'], [], [], 300))['status'], 'FAIL');
same('presence_hold SKIP si es corto', v('presence_hold', ['min_seconds' => 240],
    ctx([], ['presence_state' => 'PRESENT'], [], [], 30))['status'], 'SKIP');

// ── presence_exit ──────────────────────────────────────────────────────
$exit = v('presence_exit', ['max_confirm_seconds' => 60],
    ctx([ev('PRESENCE', 'PRESENT', 1), ev('PRESENCE', 'ABSENT', 21)], ['presence_state' => 'ABSENT']));
same('presence_exit PASS', $exit['status'], 'PASS');
same('presence_exit mide 20 s', $exit['details']['seconds_from_present_to_absent'], 20.0);
same('presence_exit FAIL si tarda', v('presence_exit', ['max_confirm_seconds' => 10],
    ctx([ev('PRESENCE', 'PRESENT', 1), ev('PRESENCE', 'ABSENT', 21)], ['presence_state' => 'ABSENT']))['status'], 'FAIL');
same('presence_exit FAIL sin ABSENT', v('presence_exit', [],
    ctx([ev('PRESENCE', 'PRESENT', 1)], ['presence_state' => 'PRESENT']))['status'], 'FAIL');

// ── effects_unchanged ──────────────────────────────────────────────────
same('effects_unchanged PASS', v('effects_unchanged', ['keys' => ['visits', 'recordings']],
    ctx())['status'], 'PASS');
same('effects_unchanged FAIL', v('effects_unchanged', ['keys' => ['recordings']],
    ctx([], [], [], ['recordings' => 2]))['status'], 'FAIL');

// ── settings ───────────────────────────────────────────────────────────
same('settings_match PASS', v('settings_match', ['expected' => ['maximum_range' => 6]],
    ctx([], [], ['maximum_range' => 6]))['status'], 'PASS');
same('settings_match FAIL', v('settings_match', ['expected' => ['maximum_range' => 4]],
    ctx([], [], ['maximum_range' => 6]))['status'], 'FAIL');
same('settings_match usa la configuración inicial', v('settings_match', ['expected_from' => 'initial_settings'],
    ctx([], [], ['maximum_range' => 6, 'radar_sensitivity' => 7]))['status'], 'PASS');
same('settings_present PASS', v('settings_present', ['keys' => ['maximum_range']],
    ctx([], [], ['maximum_range' => 6]))['status'], 'PASS');
same('settings_present FAIL si falta', v('settings_present', ['keys' => ['maximum_range']],
    ctx([], [], []))['status'], 'FAIL');

// ── panel_consistent ───────────────────────────────────────────────────
same('panel_consistent PASS', v('panel_consistent',
    ['door_in' => ['OPEN', 'CLOSED'], 'presence_in' => ['PRESENT', 'ABSENT'], 'require_decision' => true],
    ctx([], ['door_state' => 'CLOSED', 'presence_state' => 'ABSENT',
        'presence_decision' => ['state' => 'ABSENT_CONFIRMED']]))['status'], 'PASS');
same('panel_consistent FAIL si afirma con señal stale', v('panel_consistent',
    ['door_in' => ['OPEN', 'CLOSED'], 'presence_in' => ['PRESENT']],
    ctx([], ['door_state' => 'CLOSED', 'presence_state' => 'PRESENT', 'presence_stale' => true]))['status'], 'FAIL');
same('panel_consistent FAIL si falta decisión exigida', v('panel_consistent',
    ['door_in' => ['CLOSED'], 'presence_in' => ['ABSENT'], 'require_decision' => true],
    ctx())['status'], 'FAIL');

// ── manual / unknown ───────────────────────────────────────────────────
same('manual PASS', v('manual', ['reason' => 'ok'], ctx())['status'], 'PASS');
same('regla desconocida FAIL', v('nope', [], ctx())['status'], 'FAIL');

// ── catálogo ───────────────────────────────────────────────────────────
$raw = file_get_contents(__DIR__ . '/../../public/assets/sensor-tests.json');
$catalog = json_decode((string) $raw, true);
check('catálogo JSON válido', is_array($catalog));
$ids = [];
$badRules = [];
$missingBlocks = [];
$blockIds = array_map(static fn ($b) => (string) ($b['id'] ?? ''), (array) ($catalog['blocks'] ?? []));
foreach ((array) ($catalog['tests'] ?? []) as $t) {
    $id = (string) ($t['id'] ?? '');
    if ($id === '' || isset($ids[$id])) {
        $badRules[] = 'id:' . $id;
    }
    $ids[$id] = true;
    if (!in_array((string) ($t['rule'] ?? ''), SensorCalibrationValidator::rules(), true)) {
        $badRules[] = $id . ':rule=' . (string) ($t['rule'] ?? '');
    }
    if (!in_array((string) ($t['block'] ?? ''), $blockIds, true)) {
        $missingBlocks[] = $id;
    }
    if (trim((string) ($t['action'] ?? '')) === '' || trim((string) ($t['expected'] ?? '')) === '') {
        $badRules[] = $id . ':texto';
    }
}
check('catálogo sin reglas o ids inválidos', $badRules === [], implode(',', $badRules));
check('catálogo sin bloques huérfanos', $missingBlocks === [], implode(',', $missingBlocks));
check('catálogo contiene los 5 bloques', count($blockIds) === 5, implode(',', $blockIds));

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
