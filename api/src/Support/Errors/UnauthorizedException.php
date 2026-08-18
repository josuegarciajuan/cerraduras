<?php
declare(strict_types=1);

namespace App\Support\Errors;

/**
 * 401 auth_invalid_key: API key missing or invalid.
 */
final class UnauthorizedException extends ApiException
{
    public function __construct(string $message = 'Invalid or missing API key')
    {
        parent::__construct(401, 'auth_invalid_key', $message);
    }
}
