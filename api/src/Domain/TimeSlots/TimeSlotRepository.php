<?php
declare(strict_types=1);

namespace App\Domain\TimeSlots;

use PDO;

/**
 * TimeSlotRepository: persistence for time_slots.
 *
 * The repository offers a listing by room_type and an atomic "replace all"
 * operation used by PUT /room-types/{id}/time-slots. DELETE+INSERT is
 * executed inside a transaction.
 */
final class TimeSlotRepository implements TimeSlotRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return list<TimeSlot> */
    public function listForRoomType(int $roomTypeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT starts_at, ends_at, kind
             FROM time_slots
             WHERE room_type_id = :rt
             ORDER BY starts_at ASC'
        );
        $stmt->execute([':rt' => $roomTypeId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = TimeSlot::fromDb(
                (string) $row['starts_at'],
                (string) $row['ends_at'],
                (string) $row['kind']
            );
        }
        return $out;
    }

    /**
     * Atomically replaces all slots for a room type with the given list.
     * The caller is expected to have validated coverage/overlap beforehand.
     *
     * @param list<TimeSlot> $slots
     */
    public function replaceAll(int $roomTypeId, array $slots): void
    {
        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare('DELETE FROM time_slots WHERE room_type_id = :rt');
            $del->execute([':rt' => $roomTypeId]);

            $ins = $this->pdo->prepare(
                'INSERT INTO time_slots (room_type_id, starts_at, ends_at, kind)
                 VALUES (:rt, :s, :e, :k)'
            );
            foreach ($slots as $slot) {
                $ins->execute([
                    ':rt' => $roomTypeId,
                    ':s'  => TimeSlot::formatHmsForDb($slot->startsAtMinutes),
                    ':e'  => TimeSlot::formatHmsForDb($slot->endsAtMinutes),
                    ':k'  => $slot->kind,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
