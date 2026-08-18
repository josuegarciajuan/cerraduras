<?php
declare(strict_types=1);

namespace App\Support\Errors;

/**
 * 400 bad_request: malformed JSON, missing fields, invalid types at input layer.
 */
final class BadRequestException extends ApiException
{
    public function __construct(string $message = 'Bad request', ?array $details = null)
    {
        parent::__construct(400, 'bad_request', $message, $details);
    }
}
