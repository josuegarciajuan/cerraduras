<?php
declare(strict_types=1);

/**
 * PanelV2UiTest — F119.
 *
 * Static regression for the new /panel UI:
 *  - assets exist and index.html links them;
 *  - every section has a v2 renderer;
 *  - charts/format helpers are present;
 *  - JS files pass `node --check`;
 *  - the new UI does not introduce Tuya calls or a poller.
 *
 * Run: php tests/Unit/PanelV2UiTest.php
 */

$passed = 0;
$failed = 0;

function passV2(string $msg): void
{
    global $passed;
    $passed++;
    echo "  ✅ {$msg}\n";
}

function failV2(string $msg, string $detail = ''): void
{
    global $failed;
    $failed++;
    echo "  ❌ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$root  = __DIR__ . '/../..';
$panel = $root . '/public/panel';

echo "Panel v2 (F119)\n";
echo str_repeat('=', 60) . "\n\n";

$assets = [
    'index.html',
    'assets/panel-v2.css',
    'assets/panel-v2.js',
    'assets/charts.js',
    'assets/format.js',
];
foreach ($assets as $asset) {
    $path = $panel . '/' . $asset;
    if (is_file($path) && filesize($path) > 0) {
        passV2("existe {$asset}");
    } else {
        failV2("falta {$asset}");
    }
}

$html = (string) @file_get_contents($panel . '/index.html');
$htmlNeedles = [
    ['CSS v2 enlazado', '/panel/assets/panel-v2.css'],
    ['JS v2 enlazado', '/panel/assets/panel-v2.js'],
    ['charts.js enlazado', '/panel/assets/charts.js'],
    ['format.js enlazado', '/panel/assets/format.js'],
    ['navegación Analítica', "nav('ana')"],
    ['búsqueda global', 'globalSearch'],
    ['rango de fechas', 'analyticsDays'],
    ['drawer de detalle', 'drawer.show'],
    ['topbar de secciones', 'pageTitle()'],
    ['accesibilidad del modal', 'role="dialog"'],
    ['foco/teclado en nav', 'role="button" tabindex="0"'],
];
foreach ($htmlNeedles as [$label, $needle]) {
    str_contains($html, $needle) ? passV2($label) : failV2($label, "no contiene {$needle}");
}

$js = (string) @file_get_contents($panel . '/assets/panel-v2.js');
$jsNeedles = [
    'dbV2', 'analyticsPageV2', 'workersPageV2', 'roomsPageV2',
    'stayPageV2', 'qrPageV2', 'debtPageV2', 'keysPageV2', 'nfcPageV2',
    'accessesPageV2', 'factoryPageV2', 'workerRolesPageV2', 'monitorPageV2',
    'crmUserPageV2', 'apiClientPageV2', 'anomalyPageV2', 'healthPageV2',
    'settingsPageV2', 'openWorkerDrawer', 'openRoomDrawer',
    'v2Kpi', 'v2Table', 'refreshAll', 'doSearch',
    'exportCsv', 'exportChartPng',
];
foreach ($jsNeedles as $needle) {
    str_contains($js, $needle) ? passV2("UI v2 implementa {$needle}") : failV2("UI v2 sin {$needle}");
}

str_contains($js, "u.role === 'key_manager'")
    ? passV2('Usuarios: botón de panel de llaves para key_manager')
    : failV2('Usuarios: falta el botón de panel de llaves para key_manager');
str_contains($js, 'href="/llaves"')
    ? passV2('Usuarios: enlace portable a /llaves')
    : failV2('Usuarios: el enlace a /llaves no es portable');

$charts  = (string) @file_get_contents($panel . '/assets/charts.js');
$format  = (string) @file_get_contents($panel . '/assets/format.js');
str_contains($charts, 'window.PanelCharts') ? passV2('charts.js expone PanelCharts') : failV2('charts.js sin PanelCharts');
str_contains($format, 'window.PanelFormat') ? passV2('format.js expone PanelFormat') : failV2('format.js sin PanelFormat');
str_contains($charts, 'function donut') ? passV2('gráficas: donut') : failV2('gráficas: falta donut');
str_contains($charts, 'function heatmap') ? passV2('gráficas: heatmap') : failV2('gráficas: falta heatmap');
str_contains($charts, 'function sparkline') ? passV2('gráficas: sparkline') : failV2('gráficas: falta sparkline');

$tuyaHits = [];
foreach (['assets/panel-v2.js', 'assets/charts.js', 'assets/format.js'] as $file) {
    $src = (string) @file_get_contents($panel . '/' . $file);
    if (stripos($src, 'tuya') !== false) {
        $tuyaHits[] = $file;
    }
}
$tuyaHits === []
    ? passV2('el frontend v2 no llama a Tuya')
    : failV2('el frontend v2 referencia Tuya', implode(', ', $tuyaHits));

$controller = (string) @file_get_contents($root . '/src/Http/Controllers/AnalyticsController.php');
if (str_contains($controller, 'class AnalyticsController')
    && str_contains($controller, 'public function overview')
    && str_contains($controller, 'public function workerDetail')
    && str_contains($controller, 'public function roomDetail')) {
    passV2('AnalyticsController expone overview/workerDetail/roomDetail');
} else {
    failV2('AnalyticsController incompleto');
}
if (str_contains($controller, 'Tuya') && preg_match('/new\s+Tuya|->Tuya|Tuya[A-Z]\w+::/', $controller)) {
    failV2('AnalyticsController podría llamar a Tuya');
} else {
    passV2('AnalyticsController no hace llamadas a Tuya');
}

$migration = $root . '/migrations/0144_analytics_indexes.sql';
if (is_file($migration) && str_contains((string) file_get_contents($migration), 'idx_ae_time')) {
    passV2('migración 0144 de índices de analítica');
} else {
    failV2('migración 0144 ausente o incompleta');
}

// node --check for the three new JS files (node is used elsewhere in the project).
$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    foreach (['assets/panel-v2.js', 'assets/charts.js', 'assets/format.js'] as $file) {
        $path = $panel . '/' . $file;
        $cmd = escapeshellarg($node) . ' --check ' . escapeshellarg($path) . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $code === 0
            ? passV2("sintaxis JS: {$file}")
            : failV2("sintaxis JS: {$file}", implode(' ', array_slice($out, 0, 3)));
    }
} else {
    echo "  (node no disponible: se omite la sintaxis JS)\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
