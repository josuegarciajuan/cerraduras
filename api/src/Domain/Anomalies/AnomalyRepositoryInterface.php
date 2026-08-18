<?php
declare(strict_types=1);

namespace App\Domain\Anomalies;

interface AnomalyRepositoryInterface
{
    /**
     * Persist a new anomaly. The caller must set detected_at before calling.
     * Returns the assigned ID.
     */
    public function save(Anomaly $anomaly): int;

    /**
     * Find an OPEN anomaly for the given room and type.
     * Used for deduplication: if one already exists, don't create another.
     */
    public function findOpenByRoomAndType(int $roomId, string $anomalyType): ?Anomaly;

    /**
     * Find all anomalies for a room, optionally filtered by status.
     *
     * @return list<Anomaly>
     */
    public function findByRoom(int $roomId, ?string $status = null): array;

    /**
     * Find anomalies with optional filters.
     *
     * @param array{room_id?:int, anomaly_type?:string, severity?:string, status?:string} $filters
     * @return list<Anomaly>
     */
    public function findAll(array $filters, int $limit = 50, int $offset = 0): array;

    /**
     * Count total anomalies matching filters (for pagination).
     *
     * @param array{room_id?:int, anomaly_type?:string, severity?:string, status?:string} $filters
     */
    public function count(array $filters): int;

    /**
     * Find an anomaly by its ID.
     */
    public function findById(int $id): ?Anomaly;

    /**
     * Update status and audit fields (acknowledge / dismiss).
     *
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): void;

    /**
     * Find all OPEN anomalies for a room.
     * Used by RoomLiveController to include active anomalies.
     *
     * @return list<Anomaly>
     */
    public function findOpenForRoom(int $roomId): array;

    /**
     * Find all resolvable (OPEN + ACKNOWLEDGED) anomalies for a room.
     * Used by the anomaly-scanner worker to auto-dismiss anomalies whose
     * triggering condition has cleared. ACKNOWLEDGED anomalies should also
     * be auto-resolved (F37 — vista histórica).
     *
     * @return list<Anomaly>
     */
    public function findResolvableForRoom(int $roomId): array;
}
