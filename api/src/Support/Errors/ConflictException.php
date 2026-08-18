<?php
declare(strict_types=1);

namespace App\Support\Errors;

/**
 * 409 conflict: e.g. room_busy, idempotency_conflict, stay_wrong_state.
 */
final class ConflictException extends ApiException
{
    public function __construct(
        string $code = 'conflict',
        string $message = 'Conflict',
        ?array $details = null
    ) {
        parent::__construct(409, $code, $message, $details);
    }
}
