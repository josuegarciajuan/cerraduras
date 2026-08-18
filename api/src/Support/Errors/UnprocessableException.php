<?php
declare(strict_types=1);

namespace App\Support\Errors;

/**
 * 422 unprocessable_entity: business rule violations.
 * Examples: slot_not_rentable, slots_overlap, slots_not_full_day,
 * duration_out_of_range, missing_vb6_refs.
 */
final class UnprocessableException extends ApiException
{
    public function __construct(
        string $code,
        string $message,
        ?array $details = null
    ) {
        parent::__construct(422, $code, $message, $details);
    }
}
