<?php
declare(strict_types=1);

namespace Ws\Http;

final class Response
{
    public int    $status;
    /** @var array<string,string> */
    public array  $headers;
    public string $body;

    public function __construct(int $status = 200, array $headers = [], string $body = '')
    {
        $this->status  = $status;
        $this->headers = $headers;
        $this->body    = $body;
    }

    /** @param array<string,mixed>|list<mixed> $payload */
    public static function json(int $status, array $payload, array $headers = []): self
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers['Content-Type']           = 'application/json; charset=utf-8';
        $headers['X-Content-Type-Options'] = 'nosniff';
        return new self($status, $headers, $body);
    }

    /** @param array<string,mixed>|null $details */
    public static function error(
        int     $status,
        string  $code,
        string  $message,
        string  $correlationId,
        ?array  $details = null
    ): self {
        $error = ['code' => $code, 'message' => $message, 'correlation_id' => $correlationId];
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
}
