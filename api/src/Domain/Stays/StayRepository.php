<?php
declare(strict_types=1);

namespace App\Domain\Stays;

use PDO;

/**
 * StayRepository: CRUD for the `stays` table.
 *
 * The repository exposes:
 *   - insertReserved(): starts a stay in RESERVED with vb6 refs (some of
 *     which may be null).
 *   - findById(), findActiveForRoom(), listFiltered().
 *   - update(): partial update for vb6 refs, status, timestamps.
 *
 * The rationale for keeping transitions here (instead of bubbling SQL into
 * the state machine) is simple: only the repository knows about SQL. The
 * StayStateMachine validates transitions, then calls update() with the right
 * fields.
 */
final class StayRepository implements StayRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create a stay in RESERVED state.
     *
     * @param array{
     *   codalq?: int|null, codtic?: int|null, codcli?: int|null,
     *   codart?: int|null, codlot?: int|null, codhab?: string|null,
     *   temporada?: string|null, empresa?: int|null, departamento?: int|null
     * } $vb6Refs
     */
    public function insertReserved(int $roomId, int $duracionMinutos, array $vb6Refs): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stays
                (room_id, status, duracion_minutos, reserved_at,
                 vb6_codalq, vb6_codtic, vb6_codcli, vb6_codart, vb6_codlot,
                 vb6_codhab_raw, vb6_temporada, vb6_empresa, vb6_departamento)
             VALUES
                (:room_id, \'RESERVED\', :dur, UTC_TIMESTAMP(3),
                 :codalq, :codtic, :codcli, :codart, :codlot,
                 :codhab, :temp, :emp, :dep)'
        );
        $stmt->execute([
            ':room_id' => $roomId,
            ':dur' => $duracionMinutos,
            ':codalq' => $vb6Refs['codalq'] ?? null,
            ':codtic' => $vb6Refs['codtic'] ?? null,
            ':codcli' => $vb6Refs['codcli'] ?? null,
            ':codart' => $vb6Refs['codart'] ?? null,
            ':codlot' => $vb6Refs['codlot'] ?? null,
            ':codhab' => $vb6Refs['codhab'] ?? null,
            ':temp'   => $vb6Refs['temporada'] ?? null,
            ':emp'    => $vb6Refs['empresa'] ?? null,
            ':dep'    => $vb6Refs['departamento'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?Stay
    {
        $stmt = $this->pdo->prepare($this->baseSelect() . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Returns the currently-active stay for a room, if any. "Active" means
     * status in (RESERVED, OCCUPIED, OVERSTAY). At most one row is expected
     * per room at any given time; the application enforces this invariant.
     */
    public function findActiveForRoom(int $roomId): ?Stay
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() .
            " WHERE room_id = :rid AND status IN ('RESERVED','OCCUPIED','OVERSTAY')
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string,mixed> $filters Supported keys:
     *   room_id, status, vb6_codtic, vb6_codalq, from (ISO), to (ISO)
     * @return list<Stay>
     */
    public function listFiltered(array $filters, int $limit = 50, int $offset = 0): array
    {
        $wheres = [];
        $params = [];
        if (isset($filters['room_id']) && $filters['room_id'] !== '') {
            $wheres[] = 'room_id = :rid';
            $params[':rid'] = (int) $filters['room_id'];
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $wheres[] = 'status = :st';
            $params[':st'] = (string) $filters['status'];
        }
        if (isset($filters['vb6_codtic']) && $filters['vb6_codtic'] !== '') {
            $wheres[] = 'vb6_codtic = :ct';
            $params[':ct'] = (int) $filters['vb6_codtic'];
        }
        if (isset($filters['vb6_codalq']) && $filters['vb6_codalq'] !== '') {
            $wheres[] = 'vb6_codalq = :ca';
            $params[':ca'] = (int) $filters['vb6_codalq'];
        }
        if (isset($filters['from']) && $filters['from'] !== '') {
            $wheres[] = 'reserved_at >= :from';
            $params[':from'] = (string) $filters['from'];
        }
        if (isset($filters['to']) && $filters['to'] !== '') {
            $wheres[] = 'reserved_at < :to';
            $params[':to'] = (string) $filters['to'];
        }

        $sql = $this->baseSelect();
        if (!empty($wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }
        $sql .= ' ORDER BY id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Partial update. Keys are SQL column names (not RoomType-style snake).
     *
     * Allowed columns (see migration 0007_stays.sql):
     *   status, first_entry_at, exit_detected_at, closed_at,
     *   vb6_codalq, vb6_codtic, vb6_codcli, vb6_codart, vb6_codlot,
     *   vb6_codhab_raw, vb6_temporada, vb6_empresa, vb6_departamento
     *
     * @param array<string,mixed> $fields
     * @param string|null $expectedStatus  If set, the UPDATE is conditional:
     *        AND status = :expected_status. Returns 0 if the expected status
     *        doesn't match (optimistic concurrency guard).
     */
    public function update(int $id, array $fields, ?string $expectedStatus = null): int
    {
        if (empty($fields)) {
            return 0;
        }
        $allowed = [
            'status' => ':status',
            'first_entry_at' => ':first_entry_at',
            'exit_detected_at' => ':exit_detected_at',
            'closed_at' => ':closed_at',
            'vb6_codalq' => ':codalq',
            'vb6_codtic' => ':codtic',
            'vb6_codcli' => ':codcli',
            'vb6_codart' => ':codart',
            'vb6_codlot' => ':codlot',
            'vb6_codhab_raw' => ':codhab',
            'vb6_temporada' => ':temp',
            'vb6_empresa' => ':emp',
            'vb6_departamento' => ':dep',
        ];
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $col => $value) {
            if (!isset($allowed[$col])) {
                continue;
            }
            $sets[] = "`{$col}` = {$allowed[$col]}";
            $params[$allowed[$col]] = $value;
        }
        if (empty($sets)) {
            return 0;
        }
        $sql = 'UPDATE stays SET ' . implode(', ', $sets) . ' WHERE id = :id';
        if ($expectedStatus !== null) {
            $sql .= ' AND status = :expected_status';
            $params[':expected_status'] = $expectedStatus;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private function baseSelect(): string
    {
        return 'SELECT id, room_id, status, duracion_minutos,
                       reserved_at, first_entry_at, exit_detected_at, closed_at,
                       vb6_codalq, vb6_codtic, vb6_codcli, vb6_codart, vb6_codlot,
                       vb6_codhab_raw, vb6_temporada, vb6_empresa, vb6_departamento,
                       created_at, updated_at
                FROM stays';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): Stay
    {
        return new Stay(
            (int) $row['id'],
            (int) $row['room_id'],
            (string) $row['status'],
            (int) $row['duracion_minutos'],
            (string) $row['reserved_at'],
            self::nullableStr($row['first_entry_at']),
            self::nullableStr($row['exit_detected_at']),
            self::nullableStr($row['closed_at']),
            self::nullableInt($row['vb6_codalq']),
            self::nullableInt($row['vb6_codtic']),
            self::nullableInt($row['vb6_codcli']),
            self::nullableInt($row['vb6_codart']),
            self::nullableInt($row['vb6_codlot']),
            self::nullableStr($row['vb6_codhab_raw']),
            self::nullableStr($row['vb6_temporada']),
            self::nullableInt($row['vb6_empresa']),
            self::nullableInt($row['vb6_departamento']),
            (string) $row['created_at'],
            (string) $row['updated_at']
        );
    }

    /**
     * @param mixed $v
     */
    private static function nullableStr($v): ?string
    {
        return $v === null ? null : (string) $v;
    }

    /**
     * @param mixed $v
     */
    private static function nullableInt($v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
