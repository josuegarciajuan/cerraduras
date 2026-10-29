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

// ── F95 door_full ──────────────────────────────────────────────────────
$doorCtx = ctx(
    [ev('PROXIMITY', 'OPEN', 1), ev('PROXIMITY', 'CLOSED', 3),
     ev('PROXIMITY', 'OPEN', 5), ev('PROXIMITY', 'CLOSED', 7),
     ev('PROXIMITY', 'OPEN', 9), ev('PROXIMITY', 'CLOSED', 11)],
    ['door_state' => 'CLOSED']
);
$doorCtx['initial_live'] = ['door_state' => 'CLOSED'];
same('door_full PASS con 3 ciclos', v('door_full', [], $doorCtx)['status'], 'PASS');
$doorOpen = $doorCtx;
$doorOpen['initial_live'] = ['door_state' => 'OPEN'];
same('door_full FAIL si empieza abierta', v('door_full', [], $doorOpen)['status'], 'FAIL');
same('door_full motivo start', v('door_full', [], $doorOpen)['reason'], 'door_must_start_closed');
$doorShort = ctx([ev('PROXIMITY', 'OPEN', 1), ev('PROXIMITY', 'CLOSED', 3)], ['door_state' => 'CLOSED']);
$doorShort['initial_live'] = ['door_state' => 'CLOSED'];
same('door_full FAIL con pocos ciclos', v('door_full', [], $doorShort)['reason'], 'door_too_few_cycles');
$doorBad = ctx([ev('PROXIMITY', 'CLOSED', 1), ev('PROXIMITY', 'OPEN', 3)], ['door_state' => 'OPEN']);
$doorBad['initial_live'] = ['door_state' => 'CLOSED'];
same('door_full FAIL con secuencia invertida', v('door_full', [], $doorBad)['reason'], 'door_sequence_mismatch');
$doorEnd = ctx([ev('PROXIMITY', 'OPEN', 1), ev('PROXIMITY', 'CLOSED', 3),
    ev('PROXIMITY', 'OPEN', 5), ev('PROXIMITY', 'CLOSED', 7),
    ev('PROXIMITY', 'OPEN', 9)], ['door_state' => 'OPEN']);
$doorEnd['initial_live'] = ['door_state' => 'CLOSED'];
same('door_full FAIL si no termina cerrada', v('door_full', [], $doorEnd)['status'], 'FAIL');

// ── F95 radar_inside ───────────────────────────────────────────────────
$inside = ctx([ev('PRESENCE', 'PRESENT', 0), ev('PRESENCE', 'PRESENT', 15), ev('PRESENCE', 'PRESENT', 31)],
    ['presence_state' => 'PRESENT'], ['maximum_range' => 6], [], 40);
$inside['attempt_started_at'] = '2026-10-28T10:00:00.000Z';
same('radar_inside PASS con 30 s', v('radar_inside', ['hold_seconds' => 30], $inside)['status'], 'PASS');
$insideShort = ctx([ev('PRESENCE', 'PRESENT', 0), ev('PRESENCE', 'PRESENT', 10)],
    ['presence_state' => 'PRESENT'], [], [], 12);
$insideShort['attempt_started_at'] = '2026-10-28T10:00:00.000Z';
same('radar_inside SKIP si es corto', v('radar_inside', ['hold_seconds' => 30], $insideShort)['status'], 'SKIP');
$insideStale = ctx([ev('PRESENCE', 'PRESENT', 0)], ['presence_state' => 'PRESENT', 'presence_stale' => true], [], [], 40);
$insideStale['attempt_started_at'] = '2026-10-28T10:00:00.000Z';
same('radar_inside UNVERIFIED si stale', v('radar_inside', [], $insideStale)['status'], 'UNVERIFIED');
$insideAbsent = ctx([ev('PRESENCE', 'PRESENT', 0), ev('PRESENCE', 'ABSENT', 10), ev('PRESENCE', 'PRESENT', 20), ev('PRESENCE', 'PRESENT', 31)],
    ['presence_state' => 'PRESENT'], [], [], 40);
$insideAbsent['attempt_started_at'] = '2026-10-28T10:00:00.000Z';
same('radar_inside FAIL si pierde presencia', v('radar_inside', ['hold_seconds' => 30], $insideAbsent)['reason'], 'presence_not_continuous');
$insideNone = ctx([], ['presence_state' => 'ABSENT'], [], [], 40);
$insideNone['attempt_started_at'] = '2026-10-28T10:00:00.000Z';
same('radar_inside FAIL sin detección', v('radar_inside', [], $insideNone)['reason'], 'no_presence_detected');

// ── F95 radar_outside ──────────────────────────────────────────────────
$outside = ctx([ev('PRESENCE', 'ABSENT', 5), ev('PRESENCE', 'ABSENT', 20)],
    ['presence_state' => 'ABSENT'], [], [], 60);
$outside['attempt_started_at'] = '2026-10-28T10:00:00.000Z';
same('radar_outside PASS sin detección', v('radar_outside', ['min_elapsed_seconds' => 50], $outside)['status'], 'PASS');
$outsideGrace = ctx([ev('PRESENCE', 'PRESENT', 5), ev('PRESENCE', 'ABSENT', 20)],
    ['presence_state' => 'ABSENT'], [], [], 60);
$outsideGrace['attempt_started_at'] = '2026-10-28T10:00:00.000Z';
same('radar_outside ignora el fading inicial', v('radar_outside',
    ['min_elapsed_seconds' => 50, 'grace_seconds' => 20], $outsideGrace)['status'], 'PASS');
$outsideFail = ctx([ev('PRESENCE', 'PRESENT', 30)], ['presence_state' => 'PRESENT'], [], [], 60);
$outsideFail['attempt_started_at'] = '2026-10-28T10:00:00.000Z';
same('radar_outside FAIL si detecta fuera', v('radar_outside',
    ['min_elapsed_seconds' => 50, 'grace_seconds' => 20], $outsideFail)['reason'], 'presence_detected_outside');
$outsideShort = ctx([], ['presence_state' => 'ABSENT'], [], [], 10);
$outsideShort['attempt_started_at'] = '2026-10-28T10:00:00.000Z';
same('radar_outside SKIP si es corto', v('radar_outside', ['min_elapsed_seconds' => 50], $outsideShort)['status'], 'SKIP');
$outsideStale = ctx([], ['presence_state' => 'ABSENT', 'presence_stale' => true], [], [], 60);
$outsideStale['attempt_started_at'] = '2026-10-28T10:00:00.000Z';
same('radar_outside UNVERIFIED si stale', v('radar_outside', ['min_elapsed_seconds' => 50], $outsideStale)['status'], 'UNVERIFIED');

// ── F96 calibración guiada ─────────────────────────────────────────────
$calInsideBad = ctx([ev('PRESENCE', 'PRESENT', 0), ev('PRESENCE', 'PRESENT', 31)],
    ['presence_state' => 'PRESENT'], [], [], 40);
$calInsideBad['attempt_started_at'] = '2026-10-28T10:00:00.000Z';
$calInsideBad['calibration_session'] = ['inside' => ['presence' => false]];
same('radar_inside FAIL si la medición interior no detectó', v('radar_inside', [], $calInsideBad)['reason'], 'inside_not_detected_calibration');

$calOutsideBad = ctx([], ['presence_state' => 'ABSENT'], [], [], 60);
$calOutsideBad['attempt_started_at'] = '2026-10-28T10:00:00.000Z';
$calOutsideBad['calibration_session'] = ['outside' => ['presence' => true]];
same('radar_outside FAIL si la medición exterior detectó', v('radar_outside', ['min_elapsed_seconds' => 50], $calOutsideBad)['reason'], 'outside_detected_calibration');

$calReentry = ctx([], ['presence_state' => 'ABSENT'], [], [], 60);
$calReentry['attempt_started_at'] = '2026-10-28T10:00:00.000Z';
$calReentry['calibration_session'] = [
    'outside' => ['presence' => false],
    'candidate' => ['applied' => true, 'applied_at' => '2026-10-28T10:00:20Z'],
    'reentry' => ['detected' => false],
];
same('radar_outside FAIL sin reentrada verificada', v('radar_outside', ['min_elapsed_seconds' => 50], $calReentry)['reason'], 'reentry_not_verified');

$calOk = $calReentry;
$calOk['calibration_session']['reentry'] = ['detected' => true, 'at' => '2026-10-28T10:00:40Z'];
same('radar_outside PASS con reentrada', v('radar_outside', ['min_elapsed_seconds' => 50], $calOk)['status'], 'PASS');

$calSince = ctx([ev('PRESENCE', 'PRESENT', 5)], ['presence_state' => 'ABSENT'], [], [], 60);
$calSince['attempt_started_at'] = '2026-10-28T10:00:00.000Z';
$calSince['calibration_since'] = '2026-10-28T10:00:20.000Z';
$calSince['calibration_session'] = ['outside' => ['presence' => false]];
same('radar_outside ignora PRESENT anterior al ajuste', v('radar_outside', ['min_elapsed_seconds' => 50], $calSince)['status'], 'PASS');

// ── F95 light_test ─────────────────────────────────────────────────────
$light = ctx();
$light['human'] = ['on' => true, 'off' => true, 'telemetry_on' => true, 'telemetry_off' => true];
$light['switch'] = ['present' => true, 'state' => 'OFF'];
same('light_test PASS', v('light_test', [], $light)['status'], 'PASS');
$lightBad = $light; $lightBad['human'] = ['on' => false, 'off' => true];
same('light_test FAIL si no enciende', v('light_test', [], $lightBad)['status'], 'FAIL');
$lightMismatch = $light; $lightMismatch['human'] = ['on' => true, 'off' => true, 'telemetry_on' => false];
same('light_test FAIL si telemetría contradice', v('light_test', [], $lightMismatch)['status'], 'FAIL');
$lightNone = $light; $lightNone['switch'] = ['present' => false];
same('light_test SKIP sin switch', v('light_test', [], $lightNone)['status'], 'SKIP');
$lightPrepOk = $light; $lightPrepOk['human'] = ['prep_off' => true, 'on' => true, 'off' => true];
same('light_test exige preparación apagada PASS', v('light_test', ['require_prepare_off' => true], $lightPrepOk)['status'], 'PASS');
$lightPrepBad = $light; $lightPrepBad['human'] = ['prep_off' => false, 'on' => true, 'off' => true];
same('light_test FAIL si no estaba apagada', v('light_test', ['require_prepare_off' => true], $lightPrepBad)['reason'], 'lamp_not_prepared_off');
$lightNoTelemetry = $light; $lightNoTelemetry['human'] = ['prep_off' => true, 'on' => true, 'off' => true, 'telemetry_on' => null, 'telemetry_off' => null];
same('light_test acepta telemetría desconocida', v('light_test', ['require_prepare_off' => true], $lightNoTelemetry)['status'], 'PASS');

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

// Catálogo universal v2 (F95)
$rawV2 = file_get_contents(__DIR__ . '/../../public/assets/sensor-tests-v2.json');
$v2 = json_decode((string) $rawV2, true);
check('catálogo v2 válido', is_array($v2));
$v2bad = [];
$v2ids = [];
foreach ((array) ($v2['steps'] ?? []) as $t) {
    $id = (string) ($t['id'] ?? '');
    if ($id === '' || isset($v2ids[$id])) $v2bad[] = 'id:' . $id;
    $v2ids[$id] = true;
    if (!in_array((string) ($t['rule'] ?? ''), SensorCalibrationValidator::rules(), true)) {
        $v2bad[] = $id . ':rule=' . (string) ($t['rule'] ?? '');
    }
    if (trim((string) ($t['action'] ?? '')) === '') $v2bad[] = $id . ':action';
}
check('catálogo v2 sin reglas o ids inválidos', $v2bad === [], implode(',', $v2bad));
check('catálogo v2 tiene 5 pasos', count($v2ids) === 5, implode(',', array_keys($v2ids)));
foreach (['door', 'radar_inside', 'radar_outside', 'light', 'verdict'] as $need) {
    check('catálogo v2 contiene ' . $need, isset($v2ids[$need]));
}

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
