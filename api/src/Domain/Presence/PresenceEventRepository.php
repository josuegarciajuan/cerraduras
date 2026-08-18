<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use PDO;
use PDOException;

/**
 * PresenceEventRepository: PDO persistence for presence_events.
 *
 * The UNIQUE KEY on source_event_id (nullable) means MySQL will reject a
 * duplicate insert with error code 1062. We catch that and return null so
 * callers can handle the duplicate-event case gracefully.
 */
final class PresenceEventRepository implements PresenceEventRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string,mixed>|null $meta
     */
    public function insert(
        int     $roomId,
        string  $sensor,
        string  $value,
        string  $provider,
        string  $occurredAt,
        ?string $sourceEventId,
        ?array  $meta
    ): ?int {
        // Convert ISO-8601 occurredAt to MySQL DATETIME(3) UTC string.
        $occurredUtc = $this->toMysqlUtc($occurredAt);

        $stmt = $this->pdo->prepare(
            'INSERT INTO presence_events
                (room_id, sensor, value, provider, occurred_at, source_event_id, meta_json)
             VALUES
                (:room, :sensor, :val, :prov, :occ, :src, :meta)'
        );

        try {
            $stmt->execute([
                ':room'   => $roomId,
                ':sensor' => $sensor,
                ':val'    => $value,
                ':prov'   => $provider,
                ':occ'    => $occurredUtc,
                ':src'    => $sourceEventId,
                ':meta'   => $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (PDOException $e) {
            // 1062 = Duplicate entry (UNIQUE constraint on source_event_id)
            if (str_contains($e->getMessage(), '1062') || str_contains($e->getCode(), '23000')) {
                return null; // idempotent duplicate
            }
            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<PresenceEvent> */
    public function listForRoom(int $roomId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, room_id, sensor, value, provider,
                    occurred_at, received_at, source_event_id, meta_json
             FROM presence_events
             WHERE room_id = :rid
             ORDER BY occurred_at DESC, id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':rid', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Convert an ISO-8601 string (with any offset) to a UTC DATETIME(3) string
     * suitable for MySQL. Falls back to NOW() string if parsing fails.
     */
    private function toMysqlUtc(string $iso): string
    {
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $iso)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $iso)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $iso);

        if ($dt === false) {
            // Last resort: try strtotime
            $ts = strtotime($iso);
            if ($ts === false) {
                return gmdate('Y-m-d H:i:s.000');
            }
            return gmdate('Y-m-d H:i:s', $ts) . '.000';
        }
        // Normalise to UTC
        $utc = $dt->setTimezone(new \DateTimeZone('UTC'));
        return $utc->format('Y-m-d H:i:s.v');
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): PresenceEvent
    {
        $meta = null;
        if ($row['meta_json'] !== null) {
            $decoded = json_decode((string) $row['meta_json'], true);
            $meta = is_array($decoded) ? $decoded : null;
        }
        return new PresenceEvent(
            (int)    $row['id'],
            (int)    $row['room_id'],
            (string) $row['sensor'],
            (string) $row['value'],
            (string) $row['provider'],
            (string) $row['occurred_at'],
            (string) $row['received_at'],
            $row['source_event_id'] === null ? null : (string) $row['source_event_id'],
            $meta
        );
    }
}
