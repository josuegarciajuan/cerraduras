<?php
declare(strict_types=1);

namespace Ws\Support\Errors;

final class ForbiddenException extends ApiException
{
    /** @param array<string,mixed> $details */
    public function __construct(string $code = 'auth_insufficient_scope', string $message = 'Forbidden', array $details = [])
    {
        parent::__construct(403, $code, $message, $details);
    }
}
