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

    /**
     * F73/RF-85: cap in-progress recordings to $maxSeconds (0 = unlimited).
     * @return int number of recordings marked to stop
     */
    public function enforceRecordingCap(int $maxSeconds): int;

    public function isWarehouseRoom(int $roomId): bool;

    /**
     * F76 (RF-92): `entered_at` de la visita ENTRADA activa creada por un ciclo
     * de puerta o QR (no por presencia). Sirve de ancla de "huésped dentro" para
     * la credibilidad de presencia; devuelve null si no hay visita así.
     */
    public function activeEnteredVisitAt(int $roomId): ?string;
}
