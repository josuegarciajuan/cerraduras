<?php
declare(strict_types=1);

/**
 * PanelEventsTest — F129.
 *
 * Regresión estática del registro de eventos unificado del panel:
 *  - endpoint GET /api/v1/analytics/events con permiso audit:read;
 *  - fuentes: presencia/puerta, accesos de puerta, llaves/fichas y luz;
 *  - filtros y paginación por cursor + live tail;
 *  - sección «Registro de eventos» en el panel (nav, vista, CSV);
 *  - migración aditiva de índices de tiempo;
 *  - sin Tuya, sin escrituras y sin pollers nuevos.
 *
 * Run: php tests/Unit/PanelEventsTest.php
 */

$passed = 0;
$failed = 0;

function evPass(string $msg): void { global $passed; $passed++; echo "  ✅ {$msg}\n"; }
function evFail(string $msg, string $detail = ''): void { global $failed; $failed++; echo "  ❌ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
function evCheck(bool $ok, string $msg, string $detail = ''): void { $ok ? evPass($msg) : evFail($msg, $detail); }
function evSrc(string $rel): string { $path = __DIR__ . '/../../' . $rel; return is_file($path) ? (string) file_get_contents($path) : ''; }

echo "Registro de eventos del panel (F129)\n";
echo str_repeat('=', 60) . "\n\n";

$controller = evSrc('src/Http/Controllers/AnalyticsController.php');
$routes     = evSrc('public/index.php');
$panel      = evSrc('public/panel/assets/panel-v2.js');
$html       = evSrc('public/panel/index.html');
$migration  = evSrc('migrations/0149_event_log_indexes.sql');

// ── Endpoint ──────────────────────────────────────────────────────────────
evCheck(str_contains($controller, 'public function events(Request $request): Response'), 'AnalyticsController::events existe');
evCheck(str_contains($routes, "'/api/v1/analytics/events'"), 'ruta /api/v1/analytics/events registrada');
evCheck(str_contains($routes, "'events'],        \$authFactory(['audit:read'])"), 'la ruta exige audit:read');

// ── Fuentes ───────────────────────────────────────────────────────────────
evCheck(str_contains($controller, "pe.value <> 'ABSENT'"), 'la presencia ausente se excluye del registro');
evCheck(!str_contains($panel, "['ABSENT', 'Ausente']"), 'el filtro Ausente ya no se ofrece');

foreach ([
    ['presence_events pe', 'presencia/puerta'],
    ['access_events e', 'accesos de puerta'],
    ['key_access_events e', 'llaves y fichas'],
    ['switch_command_evidence sce', 'órdenes de luz'],
] as [$needle, $label]) {
    evCheck(str_contains($controller, $needle), "fuente incluida: {$label}");
}

// ── Filtros ───────────────────────────────────────────────────────────────
foreach ([
    ['category', 'categoría'],
    ['type', 'tipo'],
    ['result', 'resultado'],
    ['applied', 'aplicado'],
    ['room_id', 'sala'],
    ['worker_id', 'trabajador'],
    ['key_id', 'llave'],
    ['device_id', 'dispositivo'],
    ['q', 'texto'],
    ['before_at', 'cursor (fecha)'],
    ['before_source', 'cursor (fuente)'],
    ['before_id', 'cursor (id)'],
    ['since', 'live tail'],
    ['limit', 'límite'],
] as [$param, $label]) {
    evCheck(str_contains($controller, "\$request->query('{$param}')"), 'filtro aceptado: ' . $label);
}
evCheck(str_contains($controller, "foreach (['from', 'to'] as \$bound)"), 'filtro aceptado: rango exacto desde/hasta');

evCheck(str_contains($controller, "\$limit = max(1, min(200, \$limit));"), 'limit acotado a 1..200');
evCheck(str_contains($controller, "in_array(\$category, \$allowedCategories, true)"), 'categorías validadas');
evCheck(str_contains($controller, "'door', 'presence', 'access', 'key', 'light'"), 'catálogo de categorías completo');
evCheck(str_contains($controller, "'OK', 'FAIL'"), 'resultado validado');
evCheck(substr_count($controller, ' UNION ALL ') >= 2, 'UNION ALL para unificar fuentes');

// ── Panel ─────────────────────────────────────────────────────────────────
evCheck(str_contains($panel, "ev: ['Registro de eventos'"), 'metadatos de la página');
evCheck(str_contains($html, "nav('ev')"), 'enlace de navegación');
evCheck(str_contains($panel, "case 'ev': return this.eventsPageV2();"), 'render de la página');
evCheck(str_contains($panel, 'o.eventsPageV2 = function ()'), 'vista eventsPageV2');
evCheck(str_contains($panel, 'o.loadEvents = async function (more)'), 'carga paginada');
evCheck(str_contains($panel, 'o.loadEventsNewer = async function ()'), 'live tail');
evCheck(str_contains($panel, 'o.toggleEventsLive = function ()'), 'control de live tail');
evCheck(str_contains($panel, 'o.exportEventsCsv = function ()'), 'exportación CSV');
evCheck(str_contains($panel, 'o.eventDetailAt = function (index)'), 'drawer de detalle');
evCheck(str_contains($panel, 'setInterval(function () {'), 'temporizador del live tail');
evCheck(str_contains($panel, "if (this._eventsTimer) { clearInterval(this._eventsTimer); this._eventsTimer = null; }"), 'el temporizador se limpia al navegar');
evCheck(str_contains($panel, '/api/v1/analytics/events?'), 'la vista consume el endpoint');
evCheck(str_contains($panel, 'eventCategoryOptions') && str_contains($panel, 'eventTypeOptions'), 'selectores de categoría y tipo');
evCheck(str_contains($panel, 'before_at=') && str_contains($panel, 'since='), 'cursor y live tail en la query');

// ── Migración ─────────────────────────────────────────────────────────────
evCheck(str_contains($migration, 'presence_events'), 'migración toca presence_events');
evCheck(str_contains($migration, 'idx_pe_time'), 'índice de tiempo de presence_events');
evCheck(str_contains($migration, 'switch_command_evidence'), 'migración toca switch_command_evidence');
evCheck(str_contains($migration, 'idx_sce_time'), 'índice de tiempo de switch_command_evidence');
evCheck(!str_contains($migration, 'DROP ') && !str_contains($migration, 'DELETE '), 'migración solo aditiva');

// ── Sin Tuya ni pollers ───────────────────────────────────────────────────
$eventsMethod = '';
if (preg_match('/public function events\(Request \$request\): Response(.*?)\n    public function search/s', $controller, $m)) {
    $eventsMethod = $m[1];
}
evCheck($eventsMethod !== '', 'método events aislable');
evCheck(stripos($eventsMethod, 'tuya') === false, 'el registro no usa Tuya');
evCheck(stripos($eventsMethod, 'curl') === false && stripos($eventsMethod, 'file_get_contents') === false, 'sin llamadas externas');
evCheck(!str_contains($eventsMethod, 'INSERT ') && !str_contains($eventsMethod, 'UPDATE '), 'solo lectura');

echo "\n";
echo str_repeat('=', 60) . "\n";
echo "Resultado: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
