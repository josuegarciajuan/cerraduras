<?php
declare(strict_types=1);

namespace App\Support\Errors;

/**
 * ApiException: base class for domain errors mapped to HTTP responses.
 *
 * Properties:
 *  - httpStatus: final HTTP status code to emit.
 *  - code: short string used by the error envelope (`error.code`).
 *  - details: optional structured context (room_id, slot_kind, ...).
 *
 * Controllers/services should throw specialized subclasses (see siblings)
 * instead of raw RuntimeExceptions.
 */
class ApiException extends \RuntimeException
{
    protected int $httpStatus;
    protected string $errorCode;
    /** @var array<string,mixed>|null */
    protected ?array $errorDetails;

    /**
     * @param array<string,mixed>|null $details
     */
    public function __construct(
        int $httpStatus,
        string $errorCode,
        string $message,
        ?array $details = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->errorDetails = $details;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed>|null */
    public function errorDetails(): ?array
    {
        return $this->errorDetails;
    }
}
