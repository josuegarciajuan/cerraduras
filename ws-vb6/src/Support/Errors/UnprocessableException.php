<?php
declare(strict_types=1);

namespace Ws\Support\Errors;

final class UnprocessableException extends ApiException
{
    /** @param array<string,mixed> $details */
    public function __construct(string $code = 'unprocessable', string $message = 'Unprocessable', array $details = [])
    {
        parent::__construct(422, $code, $message, $details);
    }
}
