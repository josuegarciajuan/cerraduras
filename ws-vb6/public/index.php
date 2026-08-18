<?php
declare(strict_types=1);

/**
 * WS-VB6 entry point — composition root.
 *
 * Wires config, logger, router, middlewares and dispatches the request.
 * All endpoints are prefixed /ws-vb6/v1 per contracts.md §3.
 */

require __DIR__ . '/../src/Support/Autoload.php';

use Ws\Http\Request;
use Ws\Http\ResponseEmitter;
use Ws\Http\Router;
use Ws\Http\Middlewares\AuthApiKeyMiddleware;
use Ws\Http\Middlewares\ErrorHandlerMiddleware;
use Ws\Http\Middlewares\IdempotencyMiddleware;
use Ws\Http\Middlewares\RequestLogMiddleware;
use Ws\Debts\DebtsSyncService;
use Ws\Http\Controllers\DebtsController;
use Ws\Http\Controllers\HealthController;
use Ws\Http\Controllers\HabitacionesController;
use Ws\Http\Controllers\StaysEventsController;
use Ws\Pricing\DebtPricing;
use Ws\Stays\StayEventsSyncService;
use Ws\Support\Config;
use Ws\Support\Db\PdoFactory;
use Ws\Support\Idempotency\IdempotencyStore;
use Ws\Support\Logger\Logger;
use Ws\Vb6Repo\HabitacionesRepo;
use Ws\Vb6Repo\Vb6DeudasRepo;
use Ws\Vb6Repo\Vb6LogUsuariosRepo;

Config::load(__DIR__ . '/../.env');
date_default_timezone_set(Config::get('APP_TZ', 'Europe/Madrid') ?? 'UTC');

// --- Logger ---
$logger = new Logger(
    Config::get('LOG_LEVEL', 'info') ?? 'info',
    self_resolveLogPath(__DIR__ . '/..', Config::get('LOG_PATH'))
);

// --- Router ---
$router = new Router();
$router->use(new RequestLogMiddleware($logger));
$router->use(new ErrorHandlerMiddleware($logger));

// --- Public routes ---
$health = new HealthController();
$router->get('/ws-vb6/v1/health', [$health, 'show']);

// --- Auth middleware (shared across all protected routes) ---
$auth = new AuthApiKeyMiddleware();

// --- Idempotency store (lazy: only created when aux DB is needed) ---
$idempotencyFactory = static function (string $scope): IdempotencyMiddleware {
    static $store = null;
    if ($store === null) {
        $store = new IdempotencyStore(PdoFactory::aux());
    }
    return new IdempotencyMiddleware($store, $scope);
};

// --- Habitaciones (vb6-bridge:read) ---
$habitaciones = new HabitacionesController(
    new HabitacionesRepo(PdoFactory::vb6())
);
$router->get(
    '/ws-vb6/v1/habitaciones/{codhab}',
    [$habitaciones, 'show'],
    [$auth]
);

// --- Debts (F12 — debt.created) ---
$debtsController = new DebtsController(
    new DebtsSyncService(
        new Vb6DeudasRepo(PdoFactory::vb6()),
        new DebtPricing(PdoFactory::vb6())
    )
);
$router->post(
    '/ws-vb6/v1/debts',
    [$debtsController, 'create'],
    [$auth, $idempotencyFactory('debts')]
);

// --- Stays events (F13 — stay.closed, stay.overstay) ---
$staysEventsController = new StaysEventsController(
    new StayEventsSyncService(
        new Vb6LogUsuariosRepo(PdoFactory::vb6())
    )
);
$router->post(
    '/ws-vb6/v1/stays/events',
    [$staysEventsController, 'create'],
    [$auth, $idempotencyFactory('stays.events')]
);

// --- Dispatch ---
$request  = Request::fromGlobals();
$response = $router->dispatch($request);
(new ResponseEmitter())->emit($response);

function self_resolveLogPath(string $root, ?string $configured): ?string
{
    if ($configured === null || $configured === '') return null;
    if ($configured[0] === '/') return $configured;
    return rtrim($root, '/') . '/' . $configured;
}
