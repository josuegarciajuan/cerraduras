<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Sensors\RadarAutoTune;

$P = 0; $F = 0;
function ok(string $m): void { global $P; $P++; echo "  ✅ {$m}\n"; }
function ko(string $m): void { global $F; $F++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void { $a === $e ? ok($m) : ko($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function check(string $m, bool $c): void { $c ? ok($m) : ko($m); }
function near(string $m, float $a, float $e, float $tol = 0.001): void { abs($a - $e) <= $tol ? ok($m) : ko($m . ' — expected ' . $e . ', got ' . $a); }

echo "Radar auto-tune\n";

// ── snap ────────────────────────────────────────────────────────────────
near('snap 1.20 en rejilla 0.15', RadarAutoTune::snap(1.20, 0.75, 9.5), 1.20);
near('snap 1.24 → 1.20', RadarAutoTune::snap(1.24, 0.75, 9.5), 1.20);
near('snap 1.28 → 1.35', RadarAutoTune::snap(1.28, 0.75, 9.5), 1.35);
near('snap respeta mínimo', RadarAutoTune::snap(0.1, 0.75, 9.5), 0.75);
near('snap respeta máximo y rejilla 0.15', RadarAutoTune::snap(20.0, 0.75, 9.5), 9.45);

// ── boundary ────────────────────────────────────────────────────────────
$distances = [
    ['received_at' => '2026-11-11T10:00:00Z', 'distance_m' => 1.0],
    ['received_at' => '2026-11-11T10:00:03Z', 'distance_m' => 1.2],
    ['received_at' => '2026-11-11T10:00:06Z', 'distance_m' => 1.5],
    ['received_at' => '2026-11-11T10:00:09Z', 'distance_m' => 1.8],
    ['received_at' => '2026-11-11T10:00:12Z', 'distance_m' => 2.4], // después del ABSENT: no cuenta
];
$presence = [
    ['received_at' => '2026-11-11T10:00:00Z', 'value' => 'PRESENT'],
    ['received_at' => '2026-11-11T10:00:10Z', 'value' => 'ABSENT'],
];
$boundary = RadarAutoTune::boundary($distances, $presence, '2026-11-11T10:00:00Z', '2026-11-11T10:00:13Z', 0.30, 0.6, 9.5);
same('boundary encontrado', $boundary['found'], true);
same('boundary motivo', $boundary['reason'], 'presence_lost');
near('boundary = mediana de los últimos ecos', $boundary['distance_m'], 1.5);
near('boundary sugerido = borde - margen', $boundary['suggested_max'], 1.20);
same('boundary muestras', $boundary['samples'], 3);

$noTransition = RadarAutoTune::boundary($distances, [
    ['received_at' => '2026-11-11T10:00:00Z', 'value' => 'PRESENT'],
], '2026-11-11T10:00:00Z', '2026-11-11T10:00:13Z');
same('sin transición → no encontrado', $noTransition['found'], false);
same('sin transición motivo', $noTransition['reason'], 'no_presence_transition');
same('sin transición sugerencia null', $noTransition['suggested_max'], null);

$noDistance = RadarAutoTune::boundary([], $presence, '2026-11-11T10:00:00Z', '2026-11-11T10:00:13Z');
same('sin distancia → no encontrado', $noDistance['found'], false);
same('sin distancia motivo', $noDistance['reason'], 'no_distance_before_absent');

// ── sensitivity ─────────────────────────────────────────────────────────
$now = '2026-11-11T10:01:00Z';
$motion = [
    ['received_at' => '2026-11-11T10:00:50Z', 'distance_m' => 1.2],
    ['received_at' => '2026-11-11T10:00:53Z', 'distance_m' => 1.4],
    ['received_at' => '2026-11-11T10:00:56Z', 'distance_m' => 1.6],
];
$present = [
    ['received_at' => '2026-11-11T10:00:45Z', 'value' => 'ABSENT', 'marker' => 'a'],
    ['received_at' => '2026-11-11T10:00:52Z', 'value' => 'PRESENT', 'marker' => 'b'],
];
$detects = RadarAutoTune::sensitivity($motion, $present, 7, 20, $now);
same('detecta → mantiene', $detects['value'], 7);
same('detecta motivo', $detects['reason'], 'detects_reliably');

$motionNoPresence = RadarAutoTune::sensitivity($motion, [
    ['received_at' => '2026-11-11T10:00:45Z', 'value' => 'ABSENT', 'marker' => 'a'],
], 7, 20, $now);
same('movimiento sin presencia → sube 2', $motionNoPresence['value'], 9);
same('movimiento sin presencia motivo', $motionNoPresence['reason'], 'motion_without_presence');

$presenceNoMotion = RadarAutoTune::sensitivity([], [
    ['received_at' => '2026-11-11T10:00:45Z', 'value' => 'ABSENT', 'marker' => 'a'],
    ['received_at' => '2026-11-11T10:00:52Z', 'value' => 'PRESENT', 'marker' => 'b'],
], 7, 20, $now);
same('presencia sin ecos → sube 1', $presenceNoMotion['value'], 8);
same('presencia sin ecos motivo', $presenceNoMotion['reason'], 'presence_without_motion');

$nothing = RadarAutoTune::sensitivity([], [], 7, 20, $now);
same('sin nada → sin sugerencia', $nothing['value'], null);
same('sin nada motivo', $nothing['reason'], 'no_motion_no_presence');
same('sin nada confianza', $nothing['confidence'], 'low');

// F125.1: si el DP1 no está disponible, el estado crudo PRESENTE evita subir
// la sensibilidad a ciegas cuando además hay movimiento.
$rawPresent = RadarAutoTune::sensitivity($motion, [], 7, 20, $now, 'PRESENT');
same('raw PRESENT + ecos → mantiene', $rawPresent['value'], 7);
same('raw PRESENT + ecos motivo', $rawPresent['reason'], 'detects_reliably');
$rawOnly = RadarAutoTune::sensitivity([], [], 7, 20, $now, 'PRESENT');
same('raw PRESENT sin ecos → +1', $rawOnly['value'], 8);
same('raw PRESENT sin ecos motivo', $rawOnly['reason'], 'presence_without_motion');

// ── soak ────────────────────────────────────────────────────────────────
$soakClear = RadarAutoTune::soak([], '2026-11-11T10:00:00Z', '2026-11-11T10:01:01Z', 60, 'ABSENT');
same('soak silencio + base ABSENT → clear', $soakClear['result'], 'clear');
same('soak clear motivo', $soakClear['reason'], 'absence_confirmed');

$soakDetected = RadarAutoTune::soak([
    ['received_at' => '2026-11-11T10:00:30Z', 'value' => 'PRESENT', 'marker' => 'b'],
], '2026-11-11T10:00:00Z', '2026-11-11T10:01:01Z', 60, 'ABSENT');
same('soak con PRESENT → detected', $soakDetected['result'], 'detected');
same('soak presents', $soakDetected['presents'], 1);

$soakEarly = RadarAutoTune::soak([], '2026-11-11T10:00:00Z', '2026-11-11T10:00:30Z', 60, 'ABSENT');
same('soak antes de tiempo → inconclusive', $soakEarly['result'], 'inconclusive');
same('soak early motivo', $soakEarly['reason'], 'too_early');
same('soak remaining', $soakEarly['remaining_seconds'], 30);

$soakStalePresent = RadarAutoTune::soak([], '2026-11-11T10:00:00Z', '2026-11-11T10:01:01Z', 60, 'PRESENT');
same('soak base PRESENT sin cambios → detected', $soakStalePresent['result'], 'detected');
same('soak base PRESENT motivo', $soakStalePresent['reason'], 'presence_still_reported');

// ── reentry ─────────────────────────────────────────────────────────────
$entered = RadarAutoTune::reentry([
    ['received_at' => '2026-11-11T10:00:10Z', 'value' => 'PRESENT', 'marker' => 'v1|s|2|PRESENT|push'],
], '2026-11-11T10:00:00Z', '2026-11-11T10:00:20Z', 'v1|s|1|ABSENT|push', 90);
same('reentrada detectada', $entered['result'], 'entered');
same('reentrada motivo', $entered['reason'], 'new_present');

$sameMarker = RadarAutoTune::reentry([
    ['received_at' => '2026-11-11T10:00:10Z', 'value' => 'PRESENT', 'marker' => 'v1|s|1|PRESENT|push'],
], '2026-11-11T10:00:00Z', '2026-11-11T10:00:20Z', 'v1|s|1|PRESENT|push', 90);
same('mismo marcador → waiting', $sameMarker['result'], 'waiting');

$timeout = RadarAutoTune::reentry([], '2026-11-11T10:00:00Z', '2026-11-11T10:01:40Z', 'a', 90);
same('sin presencia nueva → timeout', $timeout['result'], 'timeout');

// ── media ───────────────────────────────────────────────────────────────
near('media par', RadarAutoTune::median([1.0, 2.0, 3.0, 4.0]), 2.5);
near('media impar', RadarAutoTune::median([1.0, 2.0, 3.0]), 2.0);
near('media vacía', RadarAutoTune::median([]), 0.0);

echo "\nTotal: {$P} passed, {$F} failed\n";
exit($F === 0 ? 0 : 1);
