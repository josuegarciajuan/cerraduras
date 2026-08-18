<?php
declare(strict_types=1);

namespace Ws\Support\Errors;

final class ConflictException extends ApiException
{
    /** @param array<string,mixed> $details */
    public function __construct(string $code = 'conflict', string $message = 'Conflict', array $details = [])
    {
        parent::__construct(409, $code, $message, $details);
    }
}
