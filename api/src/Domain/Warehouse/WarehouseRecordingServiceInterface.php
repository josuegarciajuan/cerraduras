<?php
declare(strict_types=1);

namespace App\Domain\Warehouse;

/**
 * WarehouseRecordingServiceInterface: entry point for the recording engine
 * (F63/RF-71). Signals come from the worker QR flow (QR_OK) and the IoT pipeline
 * (door/presence). Time-based signals (X_EXPIRED / M_EXPIRED) are driven by the
 * recorder daemon via tickDeadlines().
 */
interface WarehouseRecordingServiceInterface
{
    /**
     * @param array<string,mixed> $meta optional {worker_id, worker_session_id}
     */
    public function onSignal(int $roomId, string $event, array $meta = []): void;

    /**
     * Fire X_EXPIRED / M_EXPIRED for warehouse rooms whose deadline elapsed.
     * @return int number of signals fired
     */
    public function tickDeadlines(): int;

    public function isWarehouseRoom(int $roomId): bool;
}
