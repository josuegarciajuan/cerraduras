<?php
declare(strict_types=1);

namespace App\Domain\Presence;

/**
 * SensorEventDecision: pure ordering/idempotency logic for sensor events.
 *
 * Fase 41 (RF-44):
 *   - fingerprint(): logical identity of a physical fact; collapses Pulsar /
 *     poller re-sends of the same sensor+value within the same second.
 *   - decide(): classifies an incoming event as APPLY / DUPLICATE / STALE / NOOP
 *     using the last applied marks of the same sensor on the locked session.
 *
 * This class performs no I/O: it is the testable core of IotSessionService.
 * The discard reasons returned here are exactly the values persisted in
 * presence_events.discard_reason (contracts.md §2.2).
 */
final class SensorEventDecision
{
    /** Event must be applied to the session state. */
    public const APPLY = 'apply';

    /** Exact logical duplicate (same fingerprint, or same instant + value). */
    public const DUPLICATE = 'duplicate';

    /** occurred_at earlier than the last applied event of the same sensor. */
    public const STALE = 'stale';

    /** Value is already the current one; no transition. */
    public const NOOP = 'noop';

    /**
     * F48 (RF-57): PRESENT de presencia no creíble por contexto (p. ej. `move`
     * fuera de la ventana de entrada, o presencia en habitación FREE sin estancia).
     * Se audita pero no altera el estado (evita presencia fantasma del pasillo).
     */
    public const NO_CONTEXT = 'no_context';

    /**
     * Logical identity of a physical fact (contracts.md §2.1):
     *   sha1( room_id | sensor | value | floor(occurred_at, second) )
     */
    public static function fingerprint(
        int    $roomId,
        string $sensor,
        string $value,
        string $occurredAt
    ): string {
        $ts     = self::toEpoch($occurredAt);
        $second = $ts === null ? $occurredAt : gmdate('Y-m-d H:i:s', $ts);

        return sha1($roomId . '|' . $sensor . '|' . $value . '|' . $second);
    }

    /**
     * Classify an incoming event against the current (locked) session snapshot.
     *
     * @param array<string,mixed> $event Normalised sensor event (room_id, sensor, value, occurred_at).
     * @param bool $duplicateFingerprint True when insertOrGet() found an existing fingerprint/source id.
     */
    public static function decide(
        array $event,
        IotSession $session,
        bool $duplicateFingerprint = false,
        bool $entryWindowActive = true,
        bool $stayActive = true
    ): string {
        if ($duplicateFingerprint) {
            return self::DUPLICATE;
        }

        $sensor     = (string) ($event['sensor'] ?? '');
        $value      = (string) ($event['value'] ?? '');
        $occurredAt = (string) ($event['occurred_at'] ?? '');

        $isDoor  = $sensor === PresenceEvent::SENSOR_PROXIMITY;
        $lastAt  = $isDoor ? $session->lastDoorEventAt : $session->lastPresenceEventAt;
        $lastVal = $isDoor ? $session->lastDoorValue : $session->lastPresenceValue;

        $evtTs  = self::toEpoch($occurredAt);
        $lastTs = self::toEpoch($lastAt);

        // 1) Stale: the fact happened before the last applied event of this sensor.
        if ($evtTs !== null && $lastTs !== null && $evtTs < $lastTs) {
            return self::STALE;
        }

        // 2) Same instant + same value: the messenger re-sent the same fact.
        if ($evtTs !== null && $lastTs !== null && $evtTs === $lastTs && $value === $lastVal) {
            return self::DUPLICATE;
        }

        // 3) Value already in force: no transition.
        if ($lastVal !== null && $value === $lastVal) {
            return self::NOOP;
        }

        // 4) F48 (RF-57): credibilidad por contexto. Solo aplica a eventos REALES
        // del sensor (provider TUYA); las inyecciones simuladas (/sim/*) se
        // respetan tal cual (herramienta de desarrollo/tests). Solo afecta a
        // PRESENT de presencia; ABSENT (`none`) siempre se aplica para limpiar.
        $provider = (string) ($event['provider'] ?? '');
        if ($provider === PresenceEvent::PROVIDER_TUYA
            && $sensor === PresenceEvent::SENSOR_PRESENCE
            && $value === PresenceEvent::VALUE_PRESENT
        ) {
            $raw = strtolower((string) (($event['meta'] ?? [])['tuya_raw_val'] ?? ''));
            if (!self::presenceCredible($raw, $entryWindowActive, $stayActive)) {
                return self::NO_CONTEXT;
            }
        }

        return self::APPLY;
    }

    /**
     * Pure (F48/RF-57): ¿es creíble un PRESENT de presencia dado el contexto?
     *
     * - `move` (movimiento) NO respeta `far_detection` en estos 24G: dispara desde
     *   el pasillo/exterior. Solo se acepta dentro de la ventana de entrada.
     * - Cualquier PRESENT exige contexto (ventana de entrada o estancia activa):
     *   evita presencia fantasma en una habitación FREE.
     *
     * `$rawValue` es `tuya_raw_val` ('presence'|'move'|'none') o '' si no aplica.
     */
    public static function presenceCredible(
        string $rawValue,
        bool $entryWindowActive,
        bool $stayActive
    ): bool {
        if ($rawValue === 'move' && !$entryWindowActive) {
            return false;
        }
        return $entryWindowActive || $stayActive;
    }

    /**
     * Parse ISO-8601 or MySQL UTC datetime strings to an epoch (seconds).
     * Accepts both because the event carries ISO and the session stores MySQL.
     */
    private static function toEpoch(?string $ts): ?int
    {
        if ($ts === null || $ts === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($ts, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
