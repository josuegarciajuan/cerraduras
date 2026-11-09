<?php
declare(strict_types=1);

/**
 * PanelFiltersTest — F121.
 *
 * Regresión estática de los filtros de listados del panel CRM:
 *  - Accesos por sala y tipo (credencial + evento de puerta);
 *  - QRs, deudas, fichas NFC, llaves y monitor;
 *  - extensión aditiva de analytics/accesses y debts;
 *  - sin migraciones, sin Tuya y sin pollers.
 *
 * Run: php tests/Unit/PanelFiltersTest.php
 */

$passed = 0;
$failed = 0;

function pfPass(string $msg): void { global $passed; $passed++; echo "  ✅ {$msg}\n"; }
function pfFail(string $msg, string $detail = ''): void { global $failed; $failed++; echo "  ❌ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
function pfCheck(bool $ok, string $msg, string $detail = ''): void { $ok ? pfPass($msg) : pfFail($msg, $detail); }
function pfSrc(string $rel): string { $path = __DIR__ . '/../../' . $rel; return is_file($path) ? (string) file_get_contents($path) : ''; }

echo "Filtros del panel (F121)\n";
echo str_repeat('=', 60) . "\n\n";

$v2        = pfSrc('public/panel/assets/panel-v2.js');
$analytics = pfSrc('src/Http/Controllers/AnalyticsController.php');
$debts     = pfSrc('src/Domain/Debts/DebtRepository.php');
$debtCtrl  = pfSrc('src/Http/Controllers/DebtController.php');
$debtIface = pfSrc('src/Domain/Debts/DebtRepositoryInterface.php');

// ── Estado y helpers ──────────────────────────────────────────────────────
foreach ([
    ["o.accessFilters = { worker_id: '', key_id: '', result: '', room_id: '', type: '', from: '', to: '' }", 'estado de filtros de accesos'],
    ['accessTypeLabel', 'etiqueta legible del tipo'],
    ['accessTypeOptions', 'catálogo de tipos de acceso'],
    ['roomFilterOptions', 'selector reutilizable de salas'],
    ['setAccessFilterV2', 'setter de filtros de accesos'],
    ['clearAccessFiltersV2', 'limpieza de filtros de accesos'],
] as [$needle, $label]) {
    pfCheck(str_contains($v2, $needle), $label);
}

foreach (['QR de llave', 'Ficha NFC', 'QR validado', 'Apertura', 'Cierre automático', 'Cierre manual', 'Denegado', 'Salida de empleado'] as $label) {
    pfCheck(str_contains($v2, $label), "tipo visible: {$label}");
}

// ── Accesos ───────────────────────────────────────────────────────────────
pfCheck(str_contains($v2, "setAccessFilterV2(\\'room_id\\'"), 'Accesos filtra por sala');
pfCheck(str_contains($v2, "setAccessFilterV2(\\'type\\'"), 'Accesos filtra por tipo');
pfCheck(str_contains($v2, 'f.room_id || f.type'), 'la vista de Accesos reconoce los filtros nuevos');
pfCheck(str_contains($v2, 'this.accessTypeLabel(e.type || e.credential_type)'), 'la tabla usa la etiqueta canónica');
pfCheck(!str_contains($v2, "var filtered = !!(f.worker_id || f.key_id || f.result || f.from || f.to);"), 'Accesos ya no pierde los eventos al filtrar');
pfCheck(str_contains($v2, 'source = a ? (a.recent || [])'), 'la tabla filtrada usa la unión de ambas ramas');

// ── Backend de accesos ────────────────────────────────────────────────────
foreach ([
    ["\$request->query('room_id')", 'room_id'],
    ["\$request->query('type')", 'type'],
    ["\$request->query('worker_id')", 'worker_id'],
    ["\$request->query('result')", 'result'],
] as [$needle, $label]) {
    pfCheck(str_contains($analytics, $needle), "analytics/accesses acepta {$label}");
}
pfCheck(str_contains($analytics, "foreach (['from', 'to'] as \$bound)"), 'analytics/accesses acepta from/to');
pfCheck(str_contains($analytics, "in_array(\$type, ['QR', 'NFC'], true)"), 'type separa credenciales QR/NFC');
pfCheck(str_contains($analytics, "'QR_VALIDATE', 'OPEN', 'AUTO_LOCK', 'MANUAL_LOCK', 'DENIED', 'WORKER_EXIT'"), 'type separa eventos de puerta');
pfCheck(str_contains($analytics, 'LEFT JOIN worker_sessions ws'), 'worker_id usa worker_sessions en eventos');
pfCheck(str_contains($analytics, 'e.room_id AS room_id'), 'recent devuelve room_id para enlazar la sala');

// ── QRs ───────────────────────────────────────────────────────────────────
pfCheck(str_contains($v2, 'o.loadQrs = async function ()'), 'QRs con carga filtrada');
pfCheck(str_contains($v2, "p.set('jti'"), 'QRs busca por JTI');
pfCheck(str_contains($v2, "p.set('limit', '200')"), 'QRs amplía el límite visible');
pfCheck(str_contains($v2, 'setQrFilter'), 'QRs expone setter de filtros');
pfCheck(str_contains($v2, 'clearQrFilters'), 'QRs permite limpiar filtros');

// ── Deudas ────────────────────────────────────────────────────────────────
pfCheck(str_contains($v2, 'o.loadDebts = async function ()'), 'deudas con carga filtrada');
pfCheck(str_contains($v2, "p.set('room_id', f.room_id)"), 'deudas filtra por sala en el cliente');
pfCheck(str_contains($v2, 'setDebtFilter'), 'deudas expone setter de filtros');
pfCheck(str_contains($debtCtrl, "'room_id'  => \$request->query('room_id')"), 'DebtController acepta room_id');
pfCheck(str_contains($debts, "\$wheres[] = 'room_id = :rid'"), 'DebtRepository filtra por room_id');
pfCheck(str_contains($debtIface, 'status, stay_id, room_id, from, to'), 'interfaz documenta room_id');

// ── NFC / llaves / monitor ────────────────────────────────────────────────
pfCheck(str_contains($v2, 'setNfcFilter'), 'NFC expone filtro de estado');
pfCheck(str_contains($v2, 'nfcFilter.q'), 'NFC permite búsqueda');
pfCheck(str_contains($v2, "setKeyFilter(\\'key_type\\'"), 'llaves filtran por tipo QR/NFC');
pfCheck(str_contains($v2, "setKeyFilter(\\'worker_id\\'"), 'llaves filtran por trabajador');
pfCheck(str_contains($v2, 'o.loadKeys = async function ()'), 'llaves carga key_type desde el endpoint');
pfCheck(str_contains($v2, 'monitorFilters'), 'monitor mantiene filtros de eventos');
foreach (['room_id', 'kind', 'result'] as $field) {
    pfCheck(str_contains($v2, 'monitorFilters.' . $field), "monitor filtra por {$field}");
}

// ── Sin Tuya / sin migración ──────────────────────────────────────────────
pfCheck(!preg_match('/setInterval\([^)]*tuya|tuya[^\n]*setInterval/i', $v2 . $analytics), 'sin polling a Tuya');
pfCheck(!is_file(__DIR__ . '/../../migrations/0147_f121_filters.sql'), 'F121 no añade migración');

// ── Sintaxis JS ───────────────────────────────────────────────────────────
$path = __DIR__ . '/../../public/panel/assets/panel-v2.js';
exec('node --check ' . escapeshellarg($path) . ' 2>&1', $out, $code);
pfCheck($code === 0, 'sintaxis panel-v2.js', implode(' ', $out));

echo "\n";
echo $failed === 0
    ? "OK — {$passed} comprobaciones\n"
    : "FALLOS — {$passed} ok, {$failed} fallos\n";
exit($failed === 0 ? 0 : 1);
