<?php
declare(strict_types=1);

namespace Ws\Support\Errors;

final class UnauthorizedException extends ApiException
{
    /** @param array<string,mixed> $details */
    public function __construct(string $message = 'Unauthorized', array $details = [])
    {
        parent::__construct(401, 'auth_invalid_key', $message, $details);
    }
}
