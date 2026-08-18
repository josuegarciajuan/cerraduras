<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Emits a Response to the SAPI: status, headers and body.
 *
 * Kept separate from Response so that tests can inspect the Response object
 * without capturing stdout.
 */
final class ResponseEmitter
{
    public function emit(Response $response): void
    {
        if (!headers_sent()) {
            http_response_code($response->status);
            foreach ($response->headers as $name => $value) {
                // Set-Cookie may contain multiple cookies separated by CRLF
                if (strcasecmp($name, 'Set-Cookie') === 0 && strpos($value, "\r\n") !== false) {
                    $cookies = explode("\r\n", $value);
                    foreach ($cookies as $cookieLine) {
                        header('Set-Cookie: ' . $cookieLine, false);
                    }
                } else {
                    header($name . ': ' . $value, true);
                }
            }
        }
        echo $response->body;
    }
}
