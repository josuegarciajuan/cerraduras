<?php
declare(strict_types=1);

namespace Ws\Support\Errors;

final class NotFoundException extends ApiException
{
    /** @param array<string,mixed> $details */
    public function __construct(string $message = 'Not found', array $details = [])
    {
        parent::__construct(404, 'not_found', $message, $details);
    }
}
