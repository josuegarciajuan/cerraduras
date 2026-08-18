<?php
declare(strict_types=1);

namespace Ws\Support\Errors;

final class BadRequestException extends ApiException
{
    /** @param array<string,mixed> $details */
    public function __construct(string $message = 'Bad request', array $details = [])
    {
        parent::__construct(400, 'bad_request', $message, $details);
    }
}
