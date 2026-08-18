<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Request: thin immutable-ish wrapper around PHP superglobals.
 *
 * The HTTP layer only parses the request; domain logic never touches $_SERVER
 * or $_GET directly. This keeps controllers/middlewares testable.
 */
final class Request
{
    public string $method;
    public string $path;
    /** @var array<string,string> lower-cased header names */
    public array $headers;
    /** @var array<string,string> */
    public array $query;
    /** @var array<string,mixed>|null parsed JSON body, or null if none */
    public ?array $jsonBody;
    public string $rawBody;
    /** @var array<string,string> route parameters filled in by the Router */
    public array $routeParams = [];
    /** @var array<string,mixed> request-scoped attributes populated by middlewares */
    public array $attributes = [];
    /** @var array<string,string> */
    public array $cookies = [];
    public string $remoteIp;

    public static function fromGlobals(): self
    {
        $r = new self();
        $r->method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $qPos = strpos($uri, '?');
        $r->path = $qPos === false ? $uri : substr($uri, 0, $qPos);
        if ($r->path === '' || $r->path === false) {
            $r->path = '/';
        }

        $r->headers = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && strncmp($k, 'HTTP_', 5) === 0) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $r->headers[$name] = (string) $v;
            }
        }
        // Content-Type / Content-Length are not HTTP_-prefixed in $_SERVER
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $r->headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $r->headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        $r->query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $r->query[$k] = (string) $v;
            }
        }

        $raw = file_get_contents('php://input');
        $r->rawBody = $raw === false ? '' : $raw;
        $r->jsonBody = null;
        if ($r->rawBody !== '' && self::isJson($r->headers['content-type'] ?? '')) {
            $decoded = json_decode($r->rawBody, true);
            if (is_array($decoded)) {
                $r->jsonBody = $decoded;
            }
        }

        $r->remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $r->cookies = [];
        foreach ($_COOKIE as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $r->cookies[$k] = (string) $v;
            }
        }

        return $r;
    }

    public function header(string $name): ?string
    {
        $key = strtolower($name);
        return $this->headers[$key] ?? null;
    }

    public function query(string $name, ?string $default = null): ?string
    {
        return $this->query[$name] ?? $default;
    }

    public function routeParam(string $name): ?string
    {
        return $this->routeParams[$name] ?? null;
    }

    public function attr(string $name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function withAttr(string $name, $value): self
    {
        $this->attributes[$name] = $value;
        return $this;
    }

    private static function isJson(string $contentType): bool
    {
        $ct = strtolower($contentType);
        return $ct !== '' && (strpos($ct, 'application/json') !== false);
    }
}
