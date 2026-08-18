<?php
declare(strict_types=1);

namespace App\Support\Errors;

/**
 * 404 not_found.
 */
final class NotFoundException extends ApiException
{
    public function __construct(string $message = 'Resource not found', ?array $details = null)
    {
        parent::__construct(404, 'not_found', $message, $details);
    }
}
