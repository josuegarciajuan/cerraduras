<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use PDO;
use RuntimeException;

/**
 * IotSessionRepository: PDO persistence for iot_sessions.
 *
 * Fase 41: the room state has a single authoritative writer. State writes
 * happen inside a transaction opened here (beginTransaction/commit/rollBack),
 * taking a row lock with lockByRoomId() and issuing column-scoped updates with
 * updateState(). markExitEvaluated() gives the exit rule real idempotency
 * across the webhook and N exit-scan instances.
 */
final class IotSessionRepository implements IotSessionRepositoryInterface
{
    /** Columns selected for hydration (state + F41 ordering marks). */
    private const STATE_COLUMNS =
        'id, room_id, stay_id, door_state, presence_state,
         last_open_at, last_close_at, last_absent_since, exit_evaluated_at, updated_at,
         last_door_event_at, last_presence_event_at, last_door_value, last_presence_value,
         presence_pending_state, presence_pending_since, presence_pending_due_at,
         presence_pending_marker, presence_pending_config_key, presence_outcome_json,
         presence_episode_marker, presence_episode_session,
         presence_episode_started_at, presence_episode_distance_marker, light_off_at';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findByRoomId(int $roomId): ?IotSession
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::STATE_COLUMNS . ' FROM iot_sessions WHERE room_id = :rid LIMIT 1'
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function upsert(IotSession $session): void
    {
        $this->pdo->prepare(
            'INSERT INTO iot_sessions
                (room_id, stay_id, door_state, presence_state,
                 last_open_at, last_close_at, last_absent_since, exit_evaluated_at,
                 last_door_event_at, last_presence_event_at, last_door_value, last_presence_value,
                 presence_pending_state, presence_pending_since, presence_pending_due_at,
                 presence_pending_marker, presence_pending_config_key, presence_outcome_json,
                 presence_episode_marker, presence_episode_session,
                 presence_episode_started_at, presence_episode_distance_marker, light_off_at)
             VALUES
                (:room, :stay, :door, :pres, :open, :close, :abs, :exit,
                 :ldea, :lpea, :ldv, :lpv,
                 :ppst, :ppsi, :ppdu, :ppmk, :ppck, :pout,
                 :pemk, :pesn, :pest, :pedm, :loa)
             ON DUPLICATE KEY UPDATE
                stay_id                = VALUES(stay_id),
                door_state             = VALUES(door_state),
                presence_state         = VALUES(presence_state),
                last_open_at           = VALUES(last_open_at),
                last_close_at          = VALUES(last_close_at),
                last_absent_since      = VALUES(last_absent_since),
                exit_evaluated_at      = VALUES(exit_evaluated_at),
                last_door_event_at     = VALUES(last_door_event_at),
                last_presence_event_at = VALUES(last_presence_event_at),
                last_door_value        = VALUES(last_door_value),
                last_presence_value    = VALUES(last_presence_value),
                presence_pending_state      = VALUES(presence_pending_state),
                presence_pending_since      = VALUES(presence_pending_since),
                presence_pending_due_at     = VALUES(presence_pending_due_at),
                presence_pending_marker     = VALUES(presence_pending_marker),
                presence_pending_config_key = VALUES(presence_pending_config_key),
                presence_outcome_json       = VALUES(presence_outcome_json),
                presence_episode_marker          = VALUES(presence_episode_marker),
                presence_episode_session         = VALUES(presence_episode_session),
                presence_episode_started_at      = VALUES(presence_episode_started_at),
                presence_episode_distance_marker = VALUES(presence_episode_distance_marker),
                light_off_at                         = VALUES(light_off_at)'
        )->execute([
            ':room'  => $session->roomId,
            ':stay'  => $session->stayId,
            ':door'  => $session->doorState,
            ':pres'  => $session->presenceState,
            ':open'  => $session->lastOpenAt,
            ':close' => $session->lastCloseAt,
            ':abs'   => $session->lastAbsentSince,
            ':exit'  => $session->exitEvaluatedAt,
            ':ldea'  => $session->lastDoorEventAt,
            ':lpea'  => $session->lastPresenceEventAt,
            ':ldv'   => $session->lastDoorValue,
            ':lpv'   => $session->lastPresenceValue,
            ':ppst'  => $session->presencePendingState,
            ':ppsi'  => $session->presencePendingSince,
            ':ppdu'  => $session->presencePendingDueAt,
            ':ppmk'  => $session->presencePendingMarker,
            ':ppck'  => $session->presencePendingConfigKey,
            ':pout'  => $this->encodeOutcome($session->presenceOutcome),
            ':pemk'  => $session->presenceEpisodeMarker,
            ':pesn'  => $session->presenceEpisodeSession,
            ':pest'  => $session->presenceEpisodeStartedAt,
            ':pedm'  => $session->presenceEpisodeDistanceMarker,
            ':loa'   => $session->lightOffAt,
        ]);
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function lockByRoomId(int $roomId): IotSession
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('lockByRoomId() requires an active transaction');
        }

        // Materialise the row (no-op on conflict) so the FOR UPDATE below locks
        // a real row even in READ COMMITTED.
        $this->pdo->prepare(
            "INSERT INTO iot_sessions (room_id, door_state, presence_state, updated_at)
             VALUES (:rid, 'UNKNOWN', 'UNKNOWN', UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE id = id"
        )->execute([':rid' => $roomId]);

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::STATE_COLUMNS . ' FROM iot_sessions WHERE room_id = :rid LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('iot_sessions row could not be locked for room ' . $roomId);
        }
        return $this->hydrate($row);
    }

    public function updateState(IotSession $session): void
    {
        $this->pdo->prepare(
            'UPDATE iot_sessions SET
                stay_id                = :stay,
                door_state             = :door,
                presence_state         = :pres,
                last_open_at           = :open,
                last_close_at          = :close,
                last_absent_since      = :abs,
                exit_evaluated_at      = :exit,
                last_door_event_at     = :ldea,
                last_presence_event_at = :lpea,
                last_door_value        = :ldv,
                last_presence_value    = :lpv,
                presence_pending_state      = :ppst,
                presence_pending_since      = :ppsi,
                presence_pending_due_at     = :ppdu,
                presence_pending_marker     = :ppmk,
                presence_pending_config_key = :ppck,
                presence_outcome_json       = :pout,
                presence_episode_marker          = :pemk,
                presence_episode_session         = :pesn,
                presence_episode_started_at      = :pest,
                presence_episode_distance_marker = :pedm,
                light_off_at                     = :loa,
                updated_at             = UTC_TIMESTAMP(3)
             WHERE id = :id'
        )->execute([
            ':id'    => $session->id,
            ':stay'  => $session->stayId,
            ':door'  => $session->doorState,
            ':pres'  => $session->presenceState,
            ':open'  => $session->lastOpenAt,
            ':close' => $session->lastCloseAt,
            ':abs'   => $session->lastAbsentSince,
            ':exit'  => $session->exitEvaluatedAt,
            ':ldea'  => $session->lastDoorEventAt,
            ':lpea'  => $session->lastPresenceEventAt,
            ':ldv'   => $session->lastDoorValue,
            ':lpv'   => $session->lastPresenceValue,
            ':ppst'  => $session->presencePendingState,
            ':ppsi'  => $session->presencePendingSince,
            ':ppdu'  => $session->presencePendingDueAt,
            ':ppmk'  => $session->presencePendingMarker,
            ':ppck'  => $session->presencePendingConfigKey,
            ':pout'  => $this->encodeOutcome($session->presenceOutcome),
            ':pemk'  => $session->presenceEpisodeMarker,
            ':pesn'  => $session->presenceEpisodeSession,
            ':pest'  => $session->presenceEpisodeStartedAt,
            ':pedm'  => $session->presenceEpisodeDistanceMarker,
            ':loa'   => $session->lightOffAt,
        ]);
    }

    public function markExitEvaluated(int $roomId, string $closeAtUtc): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE iot_sessions
                SET exit_evaluated_at = :now, updated_at = UTC_TIMESTAMP(3)
              WHERE room_id = :rid
                AND (exit_evaluated_at IS NULL OR exit_evaluated_at < :close)'
        );
        $stmt->execute([
            ':now'   => $closeAtUtc,
            ':rid'   => $roomId,
            ':close' => $closeAtUtc,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): IotSession
    {
        return new IotSession(
            (int)    $row['id'],
            (int)    $row['room_id'],
            $row['stay_id'] === null ? null : (int) $row['stay_id'],
            (string) $row['door_state'],
            (string) $row['presence_state'],
            $row['last_open_at']       === null ? null : (string) $row['last_open_at'],
            $row['last_close_at']      === null ? null : (string) $row['last_close_at'],
            $row['last_absent_since']  === null ? null : (string) $row['last_absent_since'],
            $row['exit_evaluated_at']  === null ? null : (string) $row['exit_evaluated_at'],
            (string) $row['updated_at'],
            $row['last_door_event_at']     === null ? null : (string) $row['last_door_event_at'],
            $row['last_presence_event_at'] === null ? null : (string) $row['last_presence_event_at'],
            $row['last_door_value']        === null ? null : (string) $row['last_door_value'],
            $row['last_presence_value']    === null ? null : (string) $row['last_presence_value'],
            ($row['presence_pending_state'] ?? null) === null ? null : (string) $row['presence_pending_state'],
            ($row['presence_pending_since'] ?? null) === null ? null : (string) $row['presence_pending_since'],
            ($row['presence_pending_due_at'] ?? null) === null ? null : (string) $row['presence_pending_due_at'],
            ($row['presence_pending_marker'] ?? null) === null ? null : (string) $row['presence_pending_marker'],
            ($row['presence_pending_config_key'] ?? null) === null ? null : (string) $row['presence_pending_config_key'],
            $this->decodeOutcome($row['presence_outcome_json'] ?? null),
            ($row['presence_episode_marker'] ?? null) === null ? null : (string) $row['presence_episode_marker'],
            ($row['presence_episode_session'] ?? null) === null ? null : (string) $row['presence_episode_session'],
            ($row['presence_episode_started_at'] ?? null) === null ? null : (string) $row['presence_episode_started_at'],
            ($row['presence_episode_distance_marker'] ?? null) === null ? null : (string) $row['presence_episode_distance_marker'],
            ($row['light_off_at'] ?? null) === null ? null : (string) $row['light_off_at']
        );
    }

    /** @param array<string,mixed>|null $outcome */
    private function encodeOutcome(?array $outcome): ?string
    {
        if ($outcome === null) {
            return null;
        }
        $json = json_encode($outcome, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }

    /** @return array<string,mixed>|null */
    private function decodeOutcome(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
