<?php
declare(strict_types=1);

/**
 * PanelV21Test — F120.
 *
 * Regresión estática de la iteración v2.1:
 *  - catálogo de anomalías legible y compartido;
 *  - acciones por espacio y deep links;
 *  - ocupaciones unificadas y medios de acceso;
 *  - QR/deuda con estados canónicos;
 *  - analítica de almacén con filtros, logs y visita;
 *  - migraciones, fixture demo y reapertura;
 *  - sintaxis JS de los assets del panel;
 *  - sin polling ni llamadas a Tuya.
 *
 * Run: php tests/Unit/PanelV21Test.php
 */

$passed = 0;
$failed = 0;

function p(string $msg): void { global $passed; $passed++; echo "  ✅ {$msg}\n"; }
function f(string $msg, string $detail = ''): void { global $failed; $failed++; echo "  ❌ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
function c(bool $ok, string $msg, string $detail = ''): void { $ok ? p($msg) : f($msg, $detail); }
function src(string $rel): string { $path = __DIR__ . '/../../' . $rel; return is_file($path) ? (string) file_get_contents($path) : ''; }

echo "Panel v2.1 (F120)\n";
echo str_repeat('=', 60) . "\n\n";

$v2 = src('public/panel/assets/panel-v2.js');
$info = src('public/panel/assets/anomaly-info.js');
$html = src('public/panel/index.html');
$css = src('public/panel/assets/panel-v2.css');
$analytics = src('src/Http/Controllers/AnalyticsController.php');
$routes = src('public/index.php');
$sensores = src('public/assets/sensor-wizard-v2.js');

// ── Anomalías legibles ────────────────────────────────────────────────────
c($info !== '', 'anomaly-info.js existe');
foreach (['A1' => 'Presencia sin apertura', 'A3' => 'Puerta abierta sin QR', 'A8' => 'Sensor inestable'] as $code => $title) {
    c(str_contains($info, "{$code}: {") && str_contains($info, $title), "catálogo {$code} → {$title}");
}
c(str_contains($info, 'anomaly-tip') && str_contains($info, 'tabindex="0"'), 'tooltip accesible por foco/toque');
c(str_contains($html, '/panel/assets/anomaly-info.js'), 'index carga anomaly-info.js');
c(str_contains($v2, 'AI.cell('), 'listados usan el catálogo canónico');
c(str_contains($v2, 'AI.severityLabel') && str_contains($v2, 'AI.stateLabel'), 'severidad y estado traducidos');
c(str_contains($css, '.anomaly-help:focus'), 'estilo de foco del tooltip');
c(str_contains($v2, 'reopenAnomaly') && str_contains($v2, '/reopen'), 'acción Reabrir con motivo');
c(str_contains($v2, "dismissed_by !== 'system'"), 'reapertura limitada a descartes manuales');

// ── Espacios y deep links ────────────────────────────────────────────────
c(str_contains($v2, 'panelUrlForRoom'), 'destino de panel por tipo');
c(str_contains($v2, "'/almacen?room='") && str_contains($v2, "'/timbre?room='") && str_contains($v2, "'/dashboard?room='"), 'URLs por tipo de espacio');
c(str_contains($v2, '/sensores?room='), 'botón Calibrar con sala');
c(str_contains($sensores, "get('room')"), 'sensores prioriza ?room=');
c(str_contains($v2, 'setHash') && str_contains($v2, 'parseHash'), 'deep links con hash');
c(str_contains($v2, "q.anomaly") && str_contains($v2, "q.stay") && str_contains($v2, "q.visit"), 'hash abre registros concretos');

// ── Ocupaciones ──────────────────────────────────────────────────────────
c(str_contains($analytics, 'function occupations'), 'endpoint occupations');
c(str_contains($analytics, 'function occupationDetail'), 'detalle de ocupación');
c(str_contains($analytics, "UNION ALL"), 'une stays y worker_sessions');
c(str_contains($analytics, "QR_STAY") && str_contains($analytics, "WORKER_QR") && str_contains($analytics, "NFC"), 'clasifica medio de acceso');
c(str_contains($v2, 'Duración reservada') && str_contains($v2, 'Duración real'), 'muestra reservada vs real');
c(str_contains($v2, 'No aplica'), 'empleados sin duración reservada');
c(str_contains($v2, 'Idempotency-Key'), 'cierre de estancia con idempotencia');

// ── QR / deudas / accesos ────────────────────────────────────────────────
c(str_contains($v2, 'Caducado para llegada'), 'estado QR de llegada');
c(str_contains($v2, 'En uso'), 'primer uso no se presenta como cierre');
c(str_contains($v2, 'PENDING_SYNC') && str_contains($v2, "d.status === 'FAILED'"), 'deudas con estados canónicos');
c(str_contains($v2, 'f.room_id || f.type') && str_contains($v2, 'setAccessFilterV2'), 'accesos respetan filtros activos');
c(!preg_match("/Pendientes', debts\.filter\(function \(d\) \{ return d\.status === 'PENDING'/", $v2), 'deudas sin estado PENDING inexistente');

// ── Almacén ──────────────────────────────────────────────────────────────
c(str_contains($analytics, 'function warehouseLogs'), 'endpoint de logs del almacén');
c(str_contains($analytics, 'function warehouseVisitDetail'), 'detalle de visita');
c(str_contains($analytics, "'cameras'") && str_contains($analytics, "'visit_summary'"), 'resumen de cámaras y visitas');
c(str_contains($analytics, "'rooms' => \$rooms"), 'selector de almacenes');
c(str_contains($v2, 'whRoomId') && str_contains($v2, 'loadWarehouseLogsV2'), 'filtros de almacén y logs en UI');
c(str_contains($v2, 'openWarehouseVisit'), 'drawer de visita con grabaciones');
c(str_contains($v2, 'Grabaciones'), 'desglose de grabaciones');

// ── Migraciones / fixture / Formularios ──────────────────────────────────
c(is_file(__DIR__ . '/../../migrations/0146_room_type_normal_to_standard.sql'), 'migración 0146 presente');
$mig = src('migrations/0146_room_type_normal_to_standard.sql');
c(str_contains($mig, "DELETE FROM `room_types`") && str_contains($mig, 'worker_role_room_types'), 'fusión y borrado de TIPO_NORMAL');
c(str_contains(src('bin/panel-demo-fixture.php'), 'DEMO_F120'), 'fixture demo marcado');
c(str_contains(src('bin/panel-demo-fixture.php'), '--purge'), 'purga del fixture');
c(str_contains($html, 'loadWorkerRoles()'), 'alta de trabajador carga roles');

// ── Accesibilidad / móvil ────────────────────────────────────────────────
c(str_contains($css, ':focus-within') || str_contains($css, ':focus'), 'tooltip por foco');
c(str_contains($css, 'pointer:coarse'), 'objetivos táctiles 44px');
c(str_contains($css, 'max-width:620px') && str_contains($css, '.search-wrap{display:block'), 'búsqueda disponible en móvil');
c(str_contains($v2, 'bindFocusRestore'), 'restauración de foco en drawer/modal');
c(str_contains($v2, 'role="tab"'), 'tabs con semántica');

// ── Sin Tuya ─────────────────────────────────────────────────────────────
c(!preg_match('/setInterval\([^)]*tuya|tuya[^\n]*setInterval/i', $analytics . $v2), 'sin polling a Tuya');

// ── Sintaxis JS ──────────────────────────────────────────────────────────
foreach (['panel/assets/panel-v2.js', 'panel/assets/anomaly-info.js'] as $js) {
    $path = __DIR__ . '/../../public/' . $js;
    exec('node --check ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    c($code === 0, "sintaxis {$js}", implode(' ', $out));
}
$out = [];
exec('node --check ' . escapeshellarg(__DIR__ . '/../../public/assets/sensor-wizard-v2.js') . ' 2>&1', $out, $code);
c($code === 0, 'sintaxis sensor-wizard-v2.js', implode(' ', $out));

echo "\n" . str_repeat('-', 60) . "\n";
echo "PASS {$passed} · FAIL {$failed}\n";
exit($failed === 0 ? 0 : 1);
