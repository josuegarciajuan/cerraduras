<?php
declare(strict_types=1);

namespace Ws\Http;

/** Minimal router — identical pattern to App\Http\Router. */
final class Router
{
    /** @var list<array{method:string,regex:string,params:list<string>,handler:callable,middlewares:list<Middleware>}> */
    private array $routes = [];
    /** @var list<Middleware> */
    private array $global = [];

    public function use(Middleware $m): void { $this->global[] = $m; }

    public function get(string $p, callable $h, array $mw = []): void    { $this->add('GET',    $p, $h, $mw); }
    public function post(string $p, callable $h, array $mw = []): void   { $this->add('POST',   $p, $h, $mw); }
    public function patch(string $p, callable $h, array $mw = []): void  { $this->add('PATCH',  $p, $h, $mw); }
    public function put(string $p, callable $h, array $mw = []): void    { $this->add('PUT',    $p, $h, $mw); }
    public function delete(string $p, callable $h, array $mw = []): void { $this->add('DELETE', $p, $h, $mw); }

    public function add(string $method, string $pattern, callable $handler, array $middlewares = []): void
    {
        [$regex, $params] = $this->compile($pattern);
        $this->routes[] = [
            'method'      => strtoupper($method),
            'regex'       => $regex,
            'params'      => $params,
            'handler'     => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function dispatch(Request $request): Response
    {
        [$handler, $routeMw] = $this->resolve($request);
        return $this->pipeline(array_merge($this->global, $routeMw), $handler)($request);
    }

    /** @return array{0:callable,1:list<Middleware>} */
    private function resolve(Request $request): array
    {
        $pathMatched = false;
        $allowed     = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $m) === 1) {
                $pathMatched = true;
                if ($route['method'] === $request->method) {
                    foreach ($route['params'] as $name) {
                        $request->routeParams[$name] = isset($m[$name]) ? (string) $m[$name] : '';
                    }
                    return [$route['handler'], $route['middlewares']];
                }
                $allowed[] = $route['method'];
            }
        }
        if ($pathMatched) {
            $a = array_unique($allowed);
            return [static function (Request $req) use ($a): Response {
                return Response::error(405, 'method_not_allowed', 'Method not allowed',
                    (string) $req->attr('correlation_id', ''), ['allowed' => array_values($a)]);
            }, []];
        }
        return [static function (Request $req): Response {
            return Response::error(404, 'not_found', 'Route not found',
                (string) $req->attr('correlation_id', ''));
        }, []];
    }

    /** @return array{0:string,1:list<string>} */
    private function compile(string $pattern): array
    {
        $params = [];
        $regex  = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            }, $pattern);
        return ['/^' . str_replace('/', '\/', (string) $regex) . '$/', $params];
    }

    /**
     * @param list<Middleware> $middlewares
     * @param callable $handler
     */
    private function pipeline(array $middlewares, callable $handler): callable
    {
        $next = static fn(Request $r): Response => $handler($r);
        for ($i = count($middlewares) - 1; $i >= 0; $i--) {
            $mw = $middlewares[$i];
            $cn = $next;
            $next = static fn(Request $r): Response => $mw->handle($r, $cn);
        }
        return $next;
    }
}
