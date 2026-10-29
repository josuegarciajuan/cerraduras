<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use PDO;

/**
 * RoomSensorStateRepository — F97.3.
 *
 * Persiste la última lectura física aplicada por sala/sensor/transporte.
 * Es independiente de `iot_sessions` (ciclo de estancia) para que crear o
 * resetear un QR no apague el imán ni borre la posición física conocida.
 *
 * La escritura normal la realiza IotSessionService dentro de la misma
 * transacción del evento aplicado. Las confirmaciones manuales de polaridad
 * usan `setManual()`/`setUnknown()`.
 */
final class RoomSensorStateRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{room_id:int,sensor:string,provider:string,external_id:?string,
     *               state_value:?string,last_event_id:?int,state_source:string,
     *               occurred_at:?string,received_at:?string,updated_at:string}|null
     */
    public function get(int $roomId, string $sensor, string $provider): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT room_id, sensor, provider, external_id, state_value, last_event_id,
                    state_source, occurred_at, received_at, updated_at
               FROM room_sensor_state
              WHERE room_id = :room AND sensor = :sensor AND provider = :provider
              LIMIT 1'
        );
        $stmt->execute([
            ':room' => $roomId,
            ':sensor' => strtoupper($sensor),
            ':provider' => strtoupper($provider),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['room_id'] = (int) $row['room_id'];
        $row['last_event_id'] = $row['last_event_id'] === null ? null : (int) $row['last_event_id'];
        return $row;
    }

    public function record(
        int $roomId,
        string $sensor,
        string $provider,
        string $value,
        ?int $eventId,
        ?string $occurredAt,
        ?string $receivedAt,
        ?string $externalId = null
    ): void {
        $this->upsert(
            $roomId,
            $sensor,
            $provider,
            $value,
            $eventId,
            'event',
            $occurredAt,
            $receivedAt,
            $externalId
        );
    }

    /** Confirmación manual del operador (no hay evento nuevo). */
    public function setManual(
        int $roomId,
        string $sensor,
        string $provider,
        string $value,
        ?int $barrierEventId,
        ?string $externalId = null
    ): void {
        $this->upsert(
            $roomId,
            $sensor,
            $provider,
            $value,
            $barrierEventId,
            'manual_confirm',
            null,
            null,
            $externalId
        );
    }

    /** Invalida el estado y fija una barrera: se exigirá un reporte posterior. */
    public function setUnknown(
        int $roomId,
        string $sensor,
        string $provider,
        ?int $barrierEventId,
        ?string $externalId = null
    ): void {
        $this->upsert(
            $roomId,
            $sensor,
            $provider,
            null,
            $barrierEventId,
            'invalidated',
            null,
            null,
            $externalId
        );
    }

    public function maxAppliedEventId(int $roomId, string $sensor, string $provider): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(id) FROM presence_events
              WHERE room_id = :room AND sensor = :sensor AND provider = :provider
                AND applied = 1'
        );
        $stmt->execute([
            ':room' => $roomId,
            ':sensor' => strtoupper($sensor),
            ':provider' => strtoupper($provider),
        ]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? 0 : (int) $value;
    }

    private function upsert(
        int $roomId,
        string $sensor,
        string $provider,
        ?string $value,
        ?int $eventId,
        string $source,
        ?string $occurredAt,
        ?string $receivedAt,
        ?string $externalId
    ): void {
        $this->pdo->prepare(
            'INSERT INTO room_sensor_state
                (room_id, sensor, provider, external_id, state_value, last_event_id,
                 state_source, occurred_at, received_at, updated_at)
             VALUES
                (:room, :sensor, :provider, :external, :value, :event,
                 :source, :occurred, :received, UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE
                external_id = VALUES(external_id),
                state_value = VALUES(state_value),
                last_event_id = GREATEST(COALESCE(last_event_id, 0), COALESCE(VALUES(last_event_id), 0)),
                state_source = VALUES(state_source),
                occurred_at = COALESCE(VALUES(occurred_at), occurred_at),
                received_at = COALESCE(VALUES(received_at), received_at),
                updated_at = UTC_TIMESTAMP(3)'
        )->execute([
            ':room' => $roomId,
            ':sensor' => strtoupper($sensor),
            ':provider' => strtoupper($provider),
            ':external' => $externalId === '' ? null : $externalId,
            ':value' => $value,
            ':event' => $eventId,
            ':source' => $source,
            ':occurred' => $occurredAt,
            ':received' => $receivedAt,
        ]);
    }
}
