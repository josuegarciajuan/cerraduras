<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Response: simple value object. Emission is handled by the ResponseEmitter.
 *
 * Kept intentionally minimal; no streaming.
 */
final class Response
{
    public int $status;
    /** @var array<string,string> */
    public array $headers;
    public string $body;

    public function __construct(int $status = 200, array $headers = [], string $body = '')
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    /**
     * Build a JSON response with the standard content type and a compact encoding.
     *
     * @param array<string,mixed>|list<mixed> $payload
     */
    public static function json(int $status, array $payload, array $headers = []): self
    {
        $body = (string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $headers['X-Content-Type-Options'] = 'nosniff';
        return new self($status, $headers, $body);
    }

    /**
     * Standard error envelope (contracts.md §1.4).
     *
     * @param array<string,mixed>|null $details
     */
    public static function error(
        int $status,
        string $code,
        string $message,
        string $correlationId,
        ?array $details = null
    ): self {
        $error = [
            'code' => $code,
            'message' => $message,
            'correlation_id' => $correlationId,
        ];
        if ($details !== null) {
            $error['details'] = $details;
        }
        return self::json($status, ['error' => $error]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Sets a cookie via the Set-Cookie header.
     */
    public function withCookie(
        string $name,
        string $value,
        int $expiresAt = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): self {
        $cookie = sprintf('%s=%s', urlencode($name), urlencode($value));
        if ($expiresAt > 0) {
            $cookie .= '; Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $expiresAt);
        }
        $cookie .= '; Path=' . $path;
        if ($domain !== '') {
            $cookie .= '; Domain=' . $domain;
        }
        if ($secure) {
            $cookie .= '; Secure';
        }
        if ($httpOnly) {
            $cookie .= '; HttpOnly';
        }
        $cookie .= '; SameSite=' . $sameSite;

        // Set-Cookie can appear multiple times; append
        if (isset($this->headers['Set-Cookie'])) {
            $this->headers['Set-Cookie'] .= "\r\n" . $cookie;
        } else {
            $this->headers['Set-Cookie'] = $cookie;
        }
        return $this;
    }
}
