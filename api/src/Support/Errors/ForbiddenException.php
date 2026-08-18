<?php
declare(strict_types=1);

namespace App\Support\Errors;

/**
 * 403 forbidden: valid identity but the action is not allowed.
 * Used for scope checks, IP whitelisting and business policies (e.g. cooldown).
 */
final class ForbiddenException extends ApiException
{
    public function __construct(
        string $code = 'auth_insufficient_scope',
        string $message = 'Forbidden',
        ?array $details = null
    ) {
        parent::__construct(403, $code, $message, $details);
    }
}
