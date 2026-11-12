<?php
declare(strict_types=1);

/**
 * F125.2 — convergencia de la calibración del radar.
 *
 * Solo lógica pura: resolución del objetivo, deriva y estado para el panel.
 * No abre BD, no publica MQTT y no toca hardware.
 *
 * Run:
 *   php tests/Unit/RadarCalibrationConvergenceTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarCalibrationDrift;
use App\Infrastructure\Zigbee\RadarCalibrationConvergence;
use App\Infrastructure\Zigbee\ZigbeeRadarCalibrationService;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }

echo "Radar calibration convergence (F125.2)\n";

$saved = [
    'maximum_range' => 0.9,
    'minimum_range' => 0.0,
    'radar_sensitivity' => 7,
    'detection_delay' => 0.1,
    'fading_time' => 5.0,
    'source' => 'radar-studio-v1',
];
$reported = $saved;

// ── objetivo: calibration ─────────────────────────────────────────────
$desired = RadarCalibrationConvergence::desired(['calibration' => $saved]);
same('calibración guardada → source calibration', $desired['source'] ?? null, 'calibration');
same('calibración guardada → máximo', (float) ($desired['settings']['maximum_range'] ?? 0), 0.9);

// ── objetivo: pending vigente ─────────────────────────────────────────
$pending = ['maximum_range' => 3.0] + $saved;
$due = gmdate('Y-m-d\TH:i:s\Z', time() + 60);
$desiredPending = RadarCalibrationConvergence::desired([
    'calibration' => $saved,
    'calibration_pending' => $pending,
    'calibration_pending_until' => $due,
]);
same('pending vigente manda', $desiredPending['source'] ?? null, 'pending');
same('pending vigente → máximo', (float) ($desiredPending['settings']['maximum_range'] ?? 0), 3.0);

// ── pending expirado se ignora ────────────────────────────────────────
$expired = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
$desiredExpired = RadarCalibrationConvergence::desired([
    'calibration' => $saved,
    'calibration_pending' => $pending,
    'calibration_pending_until' => $expired,
]);
same('pending expirado → vuelve a calibration', $desiredExpired['source'] ?? null, 'calibration');

// ── respaldo calibration_previous ─────────────────────────────────────
$previous = ['maximum_range' => 1.05] + $saved;
$desiredPrev = RadarCalibrationConvergence::desired(['calibration_previous' => $previous]);
same('sin calibration → usa previous', $desiredPrev['source'] ?? null, 'calibration_previous');

// ── sin objetivo válido ───────────────────────────────────────────────
same('sin calibración → null', RadarCalibrationConvergence::desired([]), null);
same(
    'calibración inválida → null',
    RadarCalibrationConvergence::desired(['calibration' => ['maximum_range' => 0.9]]),
    null
);

// ── vista de estado para el panel ─────────────────────────────────────
$applied = RadarCalibrationConvergence::statusFor(['calibration' => $saved], $reported);
same('reportado == guardado → applied', $applied['status'] ?? null, RadarCalibrationDrift::STATUS_APPLIED);
same('applied sin diferencias', count($applied['differences'] ?? []), 0);

$mismatch = RadarCalibrationConvergence::statusFor(
    ['calibration' => $saved],
    ['maximum_range' => 6.0, 'minimum_range' => 0.6] + $saved
);
same('reportado distinto → mismatch', $mismatch['status'] ?? null, RadarCalibrationDrift::STATUS_MISMATCH);
same('mismatch: dos diferencias', count($mismatch['differences'] ?? []), 2);
same('mismatch: máximo guardado', (float) ($mismatch['desired']['maximum_range'] ?? 0), 0.9);
same('mismatch: máximo reportado', (float) ($mismatch['reported']['maximum_range'] ?? 0), 6.0);
check('mismatch incluye estado del reconciliador', array_key_exists('state', $mismatch));

$missing = RadarCalibrationConvergence::statusFor([], $reported);
same('sin calibración → missing', $missing['status'] ?? null, RadarCalibrationDrift::STATUS_MISSING);

// ── constantes compartidas con el servicio ────────────────────────────
same('PENDING_KEY compartida', RadarCalibrationConvergence::PENDING_KEY, ZigbeeRadarCalibrationService::PENDING_KEY);
same('PENDING_UNTIL_KEY compartida', RadarCalibrationConvergence::PENDING_UNTIL_KEY, ZigbeeRadarCalibrationService::PENDING_UNTIL_KEY);

// ── límites de configuración ──────────────────────────────────────────
check('cooldown >= 1', RadarCalibrationConvergence::cooldownSeconds() >= 1);
check('max attempts >= 1', RadarCalibrationConvergence::maxAttempts() >= 1);

// ── el worker del spool no clasifica la conexión como veneno ──────────
$worker = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/zigbee-spool-worker.php');
check('spool: ping de conexión en el bucle', str_contains($worker, 'PdoFactory::ping()'));
check('spool: reconoce errores de conexión', str_contains($worker, 'isConnectionError'));
check('spool: difiere sin consumir intentos', str_contains($worker, '->defer('));
$queue = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Zigbee/ZigbeeSpoolQueue.php');
check('cola: método defer', str_contains($queue, 'public function defer('));

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
