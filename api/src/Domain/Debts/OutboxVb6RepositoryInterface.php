<?php
declare(strict_types=1);

namespace App\Domain\Debts;

interface OutboxVb6RepositoryInterface
{
    /**
     * @param array<string,mixed> $payload
     */
    public function enqueue(string $topic, array $payload, string $idempotencyKey): int;

    public function scheduleRetry(string $idempotencyKey): bool;
}
