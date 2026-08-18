<?php
declare(strict_types=1);

namespace App\Domain\Locks;

interface AccessEventRepositoryInterface
{
    /**
     * Persist one access event row.
     *
     * @param array<string,mixed>|null $meta
     */
    public function insert(
        int     $roomId,
        ?int    $stayId,
        string  $kind,
        string  $result,
        ?string $reason,
        string  $provider,
        string  $correlationId,
        ?array  $meta,
        ?int    $workerSessionId = null
    ): int;
}
