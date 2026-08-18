<?php
declare(strict_types=1);

namespace Ws\Http;

/** Thin request wrapper — identical pattern to App\Http\Request. */
final class Request
{
    public string  $method;
    public string  $path;
    /** @var array<string,string> */
    public array   $headers = [];
    /** @var array<string,string> */
    public array   $query   = [];
    /** @var array<string,mixed>|null */
    public ?array  $jsonBody = null;
    public string  $rawBody  = '';
    /** @var array<string,string> */
    public array   $routeParams = [];
    /** @var array<string,mixed> */
    public array   $attributes  = [];
    public string  $remoteIp    = '';

    public static function fromGlobals(): self
    {
        $r = new self();
        $r->method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        $uri   = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $qPos  = strpos($uri, '?');
        $r->path = $qPos === false ? $uri : substr($uri, 0, $qPos);
        if ($r->path === '' || $r->path === false) {
            $r->path = '/';
        }

        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && strncmp($k, 'HTTP_', 5) === 0) {
                $r->headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $r->headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $r->query[$k] = (string) $v;
            }
        }

        $raw = file_get_contents('php://input');
        $r->rawBody = $raw === false ? '' : $raw;
        if ($r->rawBody !== '' && str_contains(strtolower($r->headers['content-type'] ?? ''), 'application/json')) {
            $decoded = json_decode($r->rawBody, true);
            if (is_array($decoded)) {
                $r->jsonBody = $decoded;
            }
        }

        $r->remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $r;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function query(string $name, ?string $default = null): ?string
    {
        return $this->query[$name] ?? $default;
    }

    public function routeParam(string $name): ?string
    {
        return $this->routeParams[$name] ?? null;
    }

    public function attr(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttr(string $name, mixed $value): self
    {
        $this->attributes[$name] = $value;
        return $this;
    }
}
