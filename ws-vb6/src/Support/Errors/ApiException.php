<?php
declare(strict_types=1);

namespace Ws\Support\Errors;

/**
 * Base exception for all domain/application errors in WS-VB6.
 * Maps directly to an HTTP status code and a machine-readable error code.
 */
class ApiException extends \RuntimeException
{
    private int    $httpStatus;
    private string $errorCode;
    /** @var array<string,mixed> */
    private array  $details;

    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        int    $httpStatus,
        string $errorCode,
        string $message,
        array  $details = []
    ) {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->errorCode  = $errorCode;
        $this->details    = $details;
    }

    public function httpStatus(): int    { return $this->httpStatus; }
    public function errorCode(): string  { return $this->errorCode;  }
    /** @return array<string,mixed> */
    public function details(): array     { return $this->details;    }
}
