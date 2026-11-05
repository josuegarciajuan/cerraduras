<?php
declare(strict_types=1);

namespace App\Domain\Timbre;

use App\Domain\Warehouse\WarehouseRecordingServiceInterface;
use PDO;

/**
 * RoomRecordingCoordinator — enruta las señales de grabación por tipo de sala.
 *
 * Conserva la interfaz histórica del almacén para no cambiar a todos los
 * llamadores: el almacén sigue usando su motor y TIMBRADO usa el nuevo.
 */
final class RoomRecordingCoordinator implements WarehouseRecordingServiceInterface
{
    public function __construct(
        private PDO $pdo,
        private WarehouseRecordingServiceInterface $warehouse,
        private TimbreRecordingService $timbre
    ) {
    }

    public function onSignal(int $roomId, string $event, array $meta = []): void
    {
        $type = $this->roomTypeCode($roomId);
        if ($type === 'ALMACEN_BEBIDAS') {
            $this->warehouse->onSignal($roomId, $event, $meta);
            return;
        }
        if ($type === 'TIMBRADO') {
            $this->timbre->onSignal($roomId, $event, $meta);
        }
    }

    public function tickDeadlines(): int
    {
        return $this->warehouse->tickDeadlines() + $this->timbre->tickDeadlines();
    }

    public function enforceRecordingCap(int $maxSeconds): int
    {
        return $this->warehouse->enforceRecordingCap($maxSeconds);
    }

    /** Nombre heredado: true para cualquier sala gestionada por un motor. */
    public function isWarehouseRoom(int $roomId): bool
    {
        return in_array($this->roomTypeCode($roomId), ['ALMACEN_BEBIDAS', 'TIMBRADO'], true);
    }

    public function activeEnteredVisitAt(int $roomId): ?string
    {
        return $this->roomTypeCode($roomId) === 'ALMACEN_BEBIDAS'
            ? $this->warehouse->activeEnteredVisitAt($roomId)
            : null;
    }

    public function latestMotionAt(int $roomId, ?string $position = null): ?string
    {
        return $this->roomTypeCode($roomId) === 'ALMACEN_BEBIDAS'
            ? $this->warehouse->latestMotionAt($roomId, $position)
            : null;
    }

    private function roomTypeCode(int $roomId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT rt.code FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
              WHERE r.id = :rid LIMIT 1'
        );
        $stmt->execute([':rid' => $roomId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }
}
