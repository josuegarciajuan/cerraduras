<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Minimal router:
 *  - Static and parametric segments: /rooms/{id}, /qr/{jti}/revoke
 *  - One handler per route + per-route middlewares
 *  - Plus a global middleware stack that wraps every request
 *  - Method Not Allowed detection (405) when a path matches but method does not
 *
 * Design notes:
 *  - Parameters are captured as strings; controllers cast as needed.
 *  - Dispatch returns a Response object; emission is caller's responsibility.
 */
final class Router
{
    /** @var list<array{method:string, pattern:string, regex:string, params:list<string>, handler:callable, middlewares:list<Middleware>}> */
    private array $routes = [];

    /** @var list<Middleware> */
    private array $globalMiddlewares = [];

    public function use(Middleware $m): void
    {
        $this->globalMiddlewares[] = $m;
    }

    /**
     * @param string $method HTTP method (GET, POST, ...)
     * @param string $pattern path pattern with {name} placeholders
     * @param callable(Request): Response $handler
     * @param list<Middleware> $middlewares route-specific middlewares (executed AFTER global ones)
     */
    public function add(string $method, string $pattern, callable $handler, array $middlewares = []): void
    {
        $method = strtoupper($method);
        [$regex, $params] = $this->compile($pattern);
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => $regex,
            'params' => $params,
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function get(string $pattern, callable $handler, array $mw = []): void
    {
        $this->add('GET', $pattern, $handler, $mw);
    }
    public function post(string $pattern, callable $handler, array $mw = []): void
    {
        $this->add('POST', $pattern, $handler, $mw);
    }
    public function put(string $pattern, callable $handler, array $mw = []): void
    {
        $this->add('PUT', $pattern, $handler, $mw);
    }
    public function patch(string $pattern, callable $handler, array $mw = []): void
    {
        $this->add('PATCH', $pattern, $handler, $mw);
    }
    public function delete(string $pattern, callable $handler, array $mw = []): void
    {
        $this->add('DELETE', $pattern, $handler, $mw);
    }

    public function dispatch(Request $request): Response
    {
        // Resolve handler + route middlewares by matching; if no route matches
        // we build a synthetic handler that returns 404 or 405. The global
        // middleware pipeline is applied uniformly in all three cases so that
        // correlation ids and logging work for every request.
        [$handler, $routeMiddlewares] = $this->resolveHandler($request);
        $pipeline = $this->buildPipeline(
            array_merge($this->globalMiddlewares, $routeMiddlewares),
            $handler
        );
        return $pipeline($request);
    }

    /**
     * @return array{0: callable(Request): Response, 1: list<Middleware>}
     */
    private function resolveHandler(Request $request): array
    {
        $pathMatched = false;
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $m) === 1) {
                $pathMatched = true;
                if ($route['method'] === $request->method) {
                    foreach ($route['params'] as $name) {
                        $request->routeParams[$name] = isset($m[$name]) ? (string) $m[$name] : '';
                    }
                    return [$route['handler'], $route['middlewares']];
                }
                $allowedMethods[] = $route['method'];
            }
        }

        if ($pathMatched && count($allowedMethods) > 0) {
            $allowed = array_values(array_unique($allowedMethods));
            $handler = static function (Request $req) use ($allowed): Response {
                $correlationId = (string) $req->attr('correlation_id', '');
                return Response::error(
                    405,
                    'method_not_allowed',
                    'Method not allowed for this route',
                    $correlationId,
                    ['allowed' => $allowed]
                )->withHeader('Allow', implode(', ', $allowed));
            };
            return [$handler, []];
        }

        $handler = static function (Request $req): Response {
            $correlationId = (string) $req->attr('correlation_id', '');
            return Response::error(
                404,
                'not_found',
                'Route not found',
                $correlationId
            );
        };
        return [$handler, []];
    }

    /**
     * @param list<Middleware> $middlewares
     * @param callable(Request): Response $handler
     * @return callable(Request): Response
     */
    private function buildPipeline(array $middlewares, callable $handler): callable
    {
        $next = static function (Request $req) use ($handler): Response {
            return $handler($req);
        };
        // Wrap from the last middleware backwards so the first runs outermost.
        for ($i = count($middlewares) - 1; $i >= 0; $i--) {
            $mw = $middlewares[$i];
            $currentNext = $next;
            $next = static function (Request $req) use ($mw, $currentNext): Response {
                return $mw->handle($req, $currentNext);
            };
        }
        return $next;
    }

    /**
     * Compile a pattern like "/rooms/{id}/state" into a regex with named captures.
     *
     * @return array{0:string, 1:list<string>} [regex, paramNames]
     */
    private function compile(string $pattern): array
    {
        if ($pattern === '' || $pattern[0] !== '/') {
            throw new \InvalidArgumentException("Route pattern must start with '/': {$pattern}");
        }
        $params = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $pattern
        );
        // Escape slashes
        $regex = str_replace('/', '\/', (string) $regex);
        return ['/^' . $regex . '$/', $params];
    }
}
