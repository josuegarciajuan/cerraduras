<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use PDO;
use PDOException;

/**
 * PresenceEventRepository: PDO persistence for presence_events.
 *
 * Fase 41 (RF-44): the UNIQUE key on source_event_id and the UNIQUE key on
 * event_fingerprint both reject duplicates with error 1062. insertOrGet()
 * catches that and returns the existing row so the caller can classify the
 * event as `duplicate` without losing the raw evidence.
 */
final class PresenceEventRepository implements PresenceEventRepositoryInterface
{
    private const EVENT_COLUMNS =
        'id, room_id, sensor, value, provider, occurred_at, received_at,
         source_event_id, meta_json, event_fingerprint, applied, discard_reason';

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
        $occurredUtc = $this->toMysqlUtc($occurredAt);
        $fingerprint = SensorEventDecision::fingerprint($roomId, $sensor, $value, $occurredAt);

        $stmt = $this->pdo->prepare(
            'INSERT INTO presence_events
                (room_id, sensor, value, provider, occurred_at, source_event_id, meta_json, event_fingerprint)
             VALUES
                (:room, :sensor, :val, :prov, :occ, :src, :meta, :fp)'
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
                ':fp'     => $fingerprint,
            ]);
        } catch (PDOException $e) {
            if ($this->isDuplicate($e)) {
                return null; // idempotent duplicate
            }
            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed>|null $meta
     * @return array{event: PresenceEvent, is_new: bool}
     */
    public function insertOrGet(
        int     $roomId,
        string  $sensor,
        string  $value,
        string  $provider,
        string  $occurredAt,
        ?string $sourceEventId,
        ?array  $meta
    ): array {
        $occurredUtc = $this->toMysqlUtc($occurredAt);
        $fingerprint = SensorEventDecision::fingerprint($roomId, $sensor, $value, $occurredAt);

        $stmt = $this->pdo->prepare(
            'INSERT INTO presence_events
                (room_id, sensor, value, provider, occurred_at, source_event_id, meta_json, event_fingerprint)
             VALUES
                (:room, :sensor, :val, :prov, :occ, :src, :meta, :fp)'
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
                ':fp'     => $fingerprint,
            ]);
            $existing = $this->findById((int) $this->pdo->lastInsertId());
            if ($existing !== null) {
                return ['event' => $existing, 'is_new' => true];
            }
            // Should not happen; build a minimal in-memory representation.
            return ['event' => new PresenceEvent(
                0, $roomId, $sensor, $value, $provider, $occurredUtc, $occurredUtc,
                $sourceEventId, $meta, $fingerprint, null, null
            ), 'is_new' => true];
        } catch (PDOException $e) {
            if (!$this->isDuplicate($e)) {
                throw $e;
            }
        }

        $existing = $this->findByFingerprintOrSource($fingerprint, $sourceEventId);
        if ($existing === null) {
            // Race: the conflicting row vanished; rethrow as a hard error by retrying once.
            throw new PDOException('Duplicate key detected but existing presence_event row not found');
        }
        return ['event' => $existing, 'is_new' => false];
    }

    public function markAudit(int $id, bool $applied, ?string $discardReason): void
    {
        // Only fill unaudited rows: a re-send must not overwrite the original decision.
        $this->pdo->prepare(
            'UPDATE presence_events
                SET applied = :applied, discard_reason = :reason
              WHERE id = :id AND applied IS NULL'
        )->execute([
            ':applied' => $applied ? 1 : 0,
            ':reason'  => $applied ? null : $discardReason,
            ':id'      => $id,
        ]);
    }

    /** @return list<PresenceEvent> */
    public function listForRoom(int $roomId, int $limit = 20, bool $appliedOnly = false): array
    {
        // F48: `appliedOnly` excluye hechos descartados (ver interfaz).
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::EVENT_COLUMNS . '
             FROM presence_events
             WHERE room_id = :rid'
             . ($appliedOnly ? ' AND applied = 1' : '') . '
             ORDER BY occurred_at DESC, id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':rid', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function findById(int $id): ?PresenceEvent
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::EVENT_COLUMNS . ' FROM presence_events WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    private function findByFingerprintOrSource(string $fingerprint, ?string $sourceEventId): ?PresenceEvent
    {
        $sql = 'SELECT ' . self::EVENT_COLUMNS . ' FROM presence_events
                WHERE event_fingerprint = :fp';
        $params = [':fp' => $fingerprint];
        if ($sourceEventId !== null && $sourceEventId !== '') {
            $sql .= ' OR source_event_id = :src';
            $params[':src'] = $sourceEventId;
        }
        $sql .= ' ORDER BY id ASC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    private function isDuplicate(PDOException $e): bool
    {
        return str_contains($e->getMessage(), '1062') || str_contains((string) $e->getCode(), '23000');
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
            $ts = strtotime($iso);
            if ($ts === false) {
                return gmdate('Y-m-d H:i:s.000');
            }
            return gmdate('Y-m-d H:i:s', $ts) . '.000';
        }
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
            $meta,
            $row['event_fingerprint'] === null ? null : (string) $row['event_fingerprint'],
            $row['applied'] === null ? null : ((int) $row['applied'] === 1),
            $row['discard_reason'] === null ? null : (string) $row['discard_reason']
        );
    }
}
