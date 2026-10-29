<?php
declare(strict_types=1);

/**
 * Unit tests for the F98 key desk (/llaves) — roles, scopes and route guards.
 *
 * Covers, without DB or network:
 *  - CrmUser roles: key_manager is valid and can open /llaves but not /panel.
 *  - ApiKey scopes derived from the CRM role (private scopesForRole()).
 *  - PanelAccessMiddleware / KeyDeskAccessMiddleware / CrmSessionMiddleware
 *    redirects and JSON errors.
 *  - KeyDeskController::nfcAvailable projection (static; dynamic SQLite when
 *    pdo_sqlite is available).
 *  - Static guards over public/index.php and migration 0132.
 *
 * Run:
 *   cd api && php tests/Unit/KeyDeskAccessTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Crm\CrmUser;
use App\Http\Controllers\KeyDeskController;
use App\Http\Middlewares\AuthApiKeyMiddleware;
use App\Http\Middlewares\CrmSessionMiddleware;
use App\Http\Middlewares\KeyDeskAccessMiddleware;
use App\Http\Middlewares\PanelAccessMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\ForbiddenException;
use App\Support\Errors\UnauthorizedException;

$passed = 0;
$failed = 0;
$skipped = 0;

function pass(string $msg): void
{
    global $passed;
    $passed++;
    echo "  PASS  {$msg}\n";
}

function fail(string $msg, string $detail = ''): void
{
    global $failed;
    $failed++;
    echo "  FAIL  {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function skip(string $msg, string $why = ''): void
{
    global $skipped;
    $skipped++;
    echo "  SKIP  {$msg}" . ($why !== '' ? " — {$why}" : '') . "\n";
}

function check(string $msg, bool $condition, string $detail = ''): void
{
    $condition ? pass($msg) : fail($msg, $detail);
}

function same(string $msg, mixed $expected, mixed $actual): void
{
    if ($expected === $actual) {
        pass($msg);
        return;
    }
    fail($msg, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function user(string $role): CrmUser
{
    return new CrmUser([
        'id' => 7,
        'username' => 'u-' . $role,
        'password_hash' => '',
        'role' => $role,
        'active' => true,
    ]);
}

function makeRequest(string $path, array $headers = [], array $cookies = []): Request
{
    $r = new Request();
    $r->method = 'GET';
    $r->path = $path;
    $r->headers = $headers;
    $r->cookies = $cookies;
    $r->query = [];
    $r->jsonBody = null;
    $r->rawBody = '';
    $r->remoteIp = '127.0.0.1';
    return $r;
}

/** @return list<string> */
function keyManagerScopes(): array
{
    $rc = new ReflectionClass(AuthApiKeyMiddleware::class);
    $obj = $rc->newInstanceWithoutConstructor();
    $m = $rc->getMethod('scopesForRole');
    $m->setAccessible(true);
    /** @var list<string> $scopes */
    $scopes = $m->invoke($obj, 'key_manager');
    return $scopes;
}

/** @param list<string> $scopes */
function asSet(array $scopes): array
{
    $scopes = array_values(array_unique($scopes));
    sort($scopes);
    return $scopes;
}

echo "KeyDeskAccess (F98/RF-178..RF-184)\n";
echo str_repeat('-', 60) . "\n";

// =========================================================================
// 1. Roles CRM
// =========================================================================
$roles = CrmUser::validRoles();
check('validRoles() contiene key_manager', in_array('key_manager', $roles, true));
check("isValidRole('key_manager') es true", CrmUser::isValidRole('key_manager'));
check("isValidRole('hacker') es false", !CrmUser::isValidRole('hacker'));

check('key_manager puede abrir /llaves', user('key_manager')->canAccessKeyDesk());
check('admin puede abrir /llaves', user('admin')->canAccessKeyDesk());
check('operator NO puede abrir /llaves', !user('operator')->canAccessKeyDesk());
check('viewer NO puede abrir /llaves', !user('viewer')->canAccessKeyDesk());
check('rol inventado NO puede abrir /llaves', !user('invented')->canAccessKeyDesk());
check('rol inventado no es un rol válido', !CrmUser::isValidRole('invented'));

check('admin puede abrir /panel', user('admin')->canAccessTechnicalPanel());
check('operator puede abrir /panel', user('operator')->canAccessTechnicalPanel());
check('viewer puede abrir /panel', user('viewer')->canAccessTechnicalPanel());
check('key_manager NO puede abrir /panel', !user('key_manager')->canAccessTechnicalPanel());

// =========================================================================
// 2. Scopes derivados del rol
// =========================================================================
$km = keyManagerScopes();
$expectedKm = ['workers:read', 'workers:write', 'worker-roles:read', 'rooms:read', 'keys:read', 'keys:write'];
same(
    'key_manager tiene exactamente los scopes del panel de llaves',
    asSet($expectedKm),
    asSet($km)
);
foreach (['nfc-cards:read', 'nfc-cards:write', 'audit:read', 'rooms:write', 'qr:validate', 'locks:open'] as $forbidden) {
    check("key_manager NO tiene {$forbidden}", !in_array($forbidden, $km, true));
}

$rc = new ReflectionClass(AuthApiKeyMiddleware::class);
$obj = $rc->newInstanceWithoutConstructor();
$m = $rc->getMethod('scopesForRole');
$m->setAccessible(true);
$adminScopes = $m->invoke($obj, 'admin');
$opScopes = $m->invoke($obj, 'operator');

check('admin conserva keys:read', in_array('keys:read', $adminScopes, true));
check('admin conserva keys:write', in_array('keys:write', $adminScopes, true));
check('admin conserva nfc-cards:read', in_array('nfc-cards:read', $adminScopes, true));
check('admin conserva nfc-cards:write', in_array('nfc-cards:write', $adminScopes, true));
check('operator conserva keys:read', in_array('keys:read', $opScopes, true));
check('operator conserva keys:write', in_array('keys:write', $opScopes, true));

// =========================================================================
// 3. PanelAccessMiddleware
// =========================================================================
$panelMw = new PanelAccessMiddleware();

$req = makeRequest('/panel', ['accept' => 'text/html']);
$req->withAttr('crm_user', user('key_manager'));
$res = $panelMw->handle($req, fn() => new Response(200));
check('PanelAccess: key_manager → 302', $res->status === 302, 'status=' . $res->status);
same('PanelAccess: key_manager → Location /llaves', '/llaves', $res->headers['Location'] ?? null);

$called = false;
$req = makeRequest('/panel', ['accept' => 'text/html']);
$req->withAttr('crm_user', user('admin'));
$res = $panelMw->handle($req, function () use (&$called) {
    $called = true;
    return new Response(200, [], 'ok');
});
check('PanelAccess: admin pasa al next', $called && $res->status === 200);

$thrown = null;
$req = makeRequest('/panel', ['accept' => 'application/json']);
$req->withAttr('crm_user', user('key_manager'));
try {
    $panelMw->handle($req, fn() => new Response(200));
} catch (ForbiddenException $e) {
    $thrown = $e;
}
check('PanelAccess: key_manager + JSON → ForbiddenException', $thrown !== null);

// =========================================================================
// 4. KeyDeskAccessMiddleware
// =========================================================================
$keyMw = new KeyDeskAccessMiddleware();

foreach (['key_manager', 'admin'] as $role) {
    $called = false;
    $req = makeRequest('/llaves', ['accept' => 'text/html']);
    $req->withAttr('crm_user', user($role));
    $keyMw->handle($req, function () use (&$called) {
        $called = true;
        return new Response(200);
    });
    check("KeyDeskAccess: {$role} pasa al next", $called);
}

$req = makeRequest('/llaves', ['accept' => 'text/html']);
$req->withAttr('crm_user', user('operator'));
$res = $keyMw->handle($req, fn() => new Response(200));
check('KeyDeskAccess: operator → 302', $res->status === 302, 'status=' . $res->status);
same('KeyDeskAccess: operator → Location /panel', '/panel', $res->headers['Location'] ?? null);

$thrown = null;
$req = makeRequest('/llaves', ['accept' => 'application/json']);
try {
    $keyMw->handle($req, fn() => new Response(200));
} catch (UnauthorizedException $e) {
    $thrown = $e;
}
check('KeyDeskAccess: sin crm_user + JSON → UnauthorizedException', $thrown !== null);

// =========================================================================
// 5. CrmSessionMiddleware (sin cookie: no toca la BD)
// =========================================================================
$crmRc = new ReflectionClass(CrmSessionMiddleware::class);
$crmMw = $crmRc->newInstanceWithoutConstructor();

$res = $crmMw->handle(makeRequest('/llaves'), fn() => new Response(200));
check('CrmSession: /llaves sin cookie → 302', $res->status === 302, 'status=' . $res->status);
same('CrmSession: /llaves sin cookie → /llaves/login', '/llaves/login', $res->headers['Location'] ?? null);

$res = $crmMw->handle(makeRequest('/panel'), fn() => new Response(200));
check('CrmSession: /panel sin cookie → 302', $res->status === 302, 'status=' . $res->status);
same('CrmSession: /panel sin cookie → /panel/login', '/panel/login', $res->headers['Location'] ?? null);

$thrown = null;
try {
    $crmMw->handle(makeRequest('/api/v1/keys'), fn() => new Response(200));
} catch (UnauthorizedException $e) {
    $thrown = $e;
}
check('CrmSession: /api/v1/keys sin cookie → UnauthorizedException', $thrown !== null);

// =========================================================================
// 6. KeyDeskController::nfcAvailable — solo id/number
// =========================================================================
$ctrlRc = new ReflectionClass(KeyDeskController::class);
$method = $ctrlRc->getMethod('nfcAvailable');
$srcLines = file($method->getFileName());
$body = implode('', array_slice(
    $srcLines,
    $method->getStartLine() - 1,
    $method->getEndLine() - $method->getStartLine() + 1
));

check('nfcAvailable() llama a findAvailable()', strpos($body, 'findAvailable()') !== false);
check("nfcAvailable() proyecta 'id'", strpos($body, "'id'") !== false);
check("nfcAvailable() proyecta 'number'", strpos($body, "'number'") !== false);
foreach (['code_hash', 'code_last4', 'notes', 'current_key_id'] as $leak) {
    check("nfcAvailable() no expone {$leak}", stripos($body, $leak) === false);
}

if (extension_loaded('pdo_sqlite')) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec(
        'CREATE TABLE nfc_cards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            number TEXT NOT NULL,
            code_hash TEXT,
            code_last4 TEXT,
            status TEXT NOT NULL,
            current_key_id INTEGER,
            notes TEXT,
            created_at TEXT,
            updated_at TEXT
        )'
    );
    $pdo->exec('CREATE TABLE access_keys (id INTEGER PRIMARY KEY, label TEXT)');
    $ins = $pdo->prepare(
        'INSERT INTO nfc_cards (number, code_hash, code_last4, status, notes, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute(['CARD-A', 'hash-a', '0001', 'AVAILABLE', 'nota a', '2026-01-01', '2026-01-01']);
    $ins->execute(['CARD-B', 'hash-b', '0002', 'ASSIGNED', 'nota b', '2026-01-01', '2026-01-01']);
    $ins->execute(['CARD-C', 'hash-c', '0003', 'AVAILABLE', 'nota c', '2026-01-01', '2026-01-01']);

    $repo = new App\Domain\AccessKeys\NfcCardRepository($pdo);
    $controller = new KeyDeskController($repo);
    $res = $controller->nfcAvailable(makeRequest('/api/v1/llaves/nfc-available'));
    $payload = json_decode($res->body, true);
    $data = $payload['data'] ?? [];
    same('nfcAvailable() HTTP 200', 200, $res->status);
    same('nfcAvailable() devuelve solo AVAILABLE (2)', 2, count($data));
    $keys = $data === [] ? [] : array_keys($data[0]);
    sort($keys);
    same('nfcAvailable() cada fila solo tiene id/number', ['id', 'number'], $keys);
    check('nfcAvailable() no filtra code_hash', !str_contains($res->body, 'hash-a') && !str_contains($res->body, 'hash-c'));
} else {
    skip(
        'nfcAvailable() con SQLite en memoria',
        'extensión pdo_sqlite no disponible; cubierto por análisis estático'
    );
}

// =========================================================================
// 7. Guardia estática de rutas (public/index.php)
// =========================================================================
$indexPath = __DIR__ . '/../../public/index.php';
$indexSrc = file_get_contents($indexPath);
if ($indexSrc === false) {
    fail('index.php legible');
} else {
    pass('index.php legible');

    $routes = [];
    foreach (explode("\n", $indexSrc) as $line) {
        if (strpos($line, '$router->') === false || strpos($line, '$authFactory(') === false) {
            continue;
        }
        if (!preg_match('/\$router->(get|post|patch|delete)\(\s*\'([^\']+)\'/', $line, $rm)) {
            continue;
        }
        if (!preg_match('/\$authFactory\(\[([^\]]*)\]/', $line, $sm)) {
            continue;
        }
        $scopeRaw = trim($sm[1]);
        $scopes = array_map(
            static fn(string $s): string => trim($s, " \t\n\r\0\x0B'\""),
            explode(',', $scopeRaw)
        );
        $routes[] = ['method' => strtolower($rm[1]), 'path' => $rm[2], 'scopes' => $scopes];
    }

    $keyRoutes = array_values(array_filter(
        $routes,
        static fn(array $r): bool => str_starts_with($r['path'], '/api/v1/keys')
    ));
    check('index.php declara rutas /api/v1/keys/*', $keyRoutes !== []);

    $leak = [];
    foreach ($keyRoutes as $r) {
        foreach ($r['scopes'] as $s) {
            if (str_starts_with($s, 'rooms:')) {
                $leak[] = $r['method'] . ' ' . $r['path'] . ' → ' . $s;
            }
        }
    }
    same('ninguna ruta /api/v1/keys/* usa scopes rooms:*', [], $leak);

    $byRoute = [];
    foreach ($keyRoutes as $r) {
        $byRoute[$r['method'] . ' ' . $r['path']] = $r['scopes'];
    }
    $expectedKeyScopes = [
        'get /api/v1/keys' => 'keys:read',
        'post /api/v1/keys' => 'keys:write',
        'get /api/v1/keys/{id}' => 'keys:read',
        'patch /api/v1/keys/{id}' => 'keys:write',
        'delete /api/v1/keys/{id}' => 'keys:write',
        'post /api/v1/keys/{id}/revoke' => 'keys:write',
        'post /api/v1/keys/{id}/qr' => 'keys:write',
        'get /api/v1/keys/defaults' => 'keys:read',
    ];
    foreach ($expectedKeyScopes as $route => $scope) {
        $actual = $byRoute[$route] ?? [];
        check("ruta {$route} usa {$scope}", in_array($scope, $actual, true), 'got ' . implode(',', $actual));
    }

    $nfcRoutes = array_values(array_filter(
        $routes,
        static fn(array $r): bool => str_starts_with($r['path'], '/api/v1/nfc-cards')
    ));
    check('index.php declara rutas /api/v1/nfc-cards/*', $nfcRoutes !== []);
    foreach ($nfcRoutes as $r) {
        $want = $r['method'] === 'get' ? 'nfc-cards:read' : 'nfc-cards:write';
        check(
            "ruta {$r['method']} {$r['path']} usa {$want}",
            in_array($want, $r['scopes'], true),
            'got ' . implode(',', $r['scopes'])
        );
    }

    $nfcAvailable = null;
    foreach ($routes as $r) {
        if ($r['path'] === '/api/v1/llaves/nfc-available') {
            $nfcAvailable = $r;
            break;
        }
    }
    check('existe GET /api/v1/llaves/nfc-available', $nfcAvailable !== null);
    if ($nfcAvailable !== null) {
        same('nfc-available usa keys:read', ['keys:read'], $nfcAvailable['scopes']);
    }
}

// =========================================================================
// 8. Guardia de la migración 0132
// =========================================================================
$migrationPath = __DIR__ . '/../../migrations/0132_key_manager_panel.sql';
if (!is_file($migrationPath)) {
    fail('migración 0132_key_manager_panel.sql existe');
} else {
    pass('migración 0132_key_manager_panel.sql existe');
    $sql = (string) file_get_contents($migrationPath);

    check('migración 0132 es idempotente (FIND_IN_SET)', strpos($sql, 'FIND_IN_SET') !== false);
    foreach ([
        'keys:read', 'keys:write',
        'nfc-cards:read', 'nfc-cards:write',
        'workers:read', 'workers:write',
        'worker-roles:read',
    ] as $scope) {
        check("migración 0132 incluye {$scope}", strpos($sql, $scope) !== false);
    }
    check(
        'migración 0132 no crea usuarios CRM',
        stripos($sql, 'INSERT INTO `crm_users`') === false
            && stripos($sql, 'INSERT INTO crm_users') === false
    );
    check('migración 0132 no contiene contraseñas', stripos($sql, 'password') === false);
}

echo str_repeat('-', 60) . "\n";
echo "Total: {$passed} passed, {$failed} failed, {$skipped} skipped\n";
exit($failed === 0 ? 0 : 1);
