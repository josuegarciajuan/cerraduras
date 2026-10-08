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
     * F80 (RF-103.2): `entered_at` de la visita ENTRADA activa del almacén, creada
     * por QR, puerta **o presencia**. Ancla informativa de "dentro"; F80 ya no la usa
     * para vetar presencia (deroga F76/RF-92.1). Devuelve null si no hay visita así.
     */
    public function activeEnteredVisitAt(int $roomId): ?string;

    /**
     * F88 (RF-123.3): instante (MySQL UTC) del último movimiento detectado por las
     * cámaras de la sala, opcionalmente restringido a una posición
     * (`EXTERIOR`/`INTERIOR`). `null` si no hay dato. Solo BD local; sin cuota.
     */
    public function latestMotionAt(int $roomId, ?string $position = null): ?string;
}
