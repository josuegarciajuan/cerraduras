<?php
declare(strict_types=1);

namespace App\Domain\Locks;

/**
 * AccessEvent: value object representing one row in access_events.
 *
 * kind values (design.md §4.8):
 *   QR_VALIDATE  — result of a QR scan (before the lock is actually opened).
 *   OPEN         — lock open command sent.
 *   AUTO_LOCK    — automatic lock triggered by exit rule.
 *   MANUAL_LOCK  — lock command from admin endpoint.
 *   DENIED       — access attempt denied (any reason).
 *   WORKER_EXIT  — worker session closed (exit rule, door event, or QR scan).
 *
 * provider: TUYA | SIMULATED.
 */
final class AccessEvent
{
    public const KIND_QR_VALIDATE = 'QR_VALIDATE';
    public const KIND_OPEN        = 'OPEN';
    public const KIND_AUTO_LOCK   = 'AUTO_LOCK';
    public const KIND_MANUAL_LOCK = 'MANUAL_LOCK';
    public const KIND_DENIED      = 'DENIED';
    public const KIND_WORKER_EXIT = 'WORKER_EXIT';

    public const RESULT_OK   = 'OK';
    public const RESULT_FAIL = 'FAIL';

    public const PROVIDER_TUYA      = 'TUYA';
    public const PROVIDER_SIMULATED = 'SIMULATED';

    public int $id;
    public int $roomId;
    public ?int $stayId;
    public ?int $workerSessionId;
    public string $kind;
    public string $result;
    public ?string $reason;
    public string $provider;
    public string $correlationId;
    /** @var array<string,mixed>|null */
    public ?array $meta;
    public string $occurredAt;

    /**
     * @param array<string,mixed>|null $meta
     */
    public function __construct(
        int $id,
        int $roomId,
        ?int $stayId,
        ?int $workerSessionId,
        string $kind,
        string $result,
        ?string $reason,
        string $provider,
        string $correlationId,
        ?array $meta,
        string $occurredAt
    ) {
        $this->id              = $id;
        $this->roomId          = $roomId;
        $this->stayId          = $stayId;
        $this->workerSessionId = $workerSessionId;
        $this->kind            = $kind;
        $this->result          = $result;
        $this->reason          = $reason;
        $this->provider        = $provider;
        $this->correlationId   = $correlationId;
        $this->meta            = $meta;
        $this->occurredAt      = $occurredAt;
    }
}
