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
     *
     * F58 (RF-65): la identidad sigue a segundos (idempotencia de reenvíos),
     * aunque el orden use milisegundos (ver `decide()`).
     */
    public static function fingerprint(
        int    $roomId,
        string $sensor,
        string $value,
        string $occurredAt
    ): string {
        $tsMs   = self::toEpochMs($occurredAt);
        $second = $tsMs === null ? $occurredAt : gmdate('Y-m-d H:i:s', intdiv($tsMs, 1000));

        return sha1($roomId . '|' . $sensor . '|' . $value . '|' . $second);
    }

    /**
     * Classify an incoming event against the current (locked) session snapshot.
     *
     * @param array<string,mixed> $event Normalised sensor event (room_id, sensor, value, occurred_at).
     * @param bool $duplicateFingerprint True when insertOrGet() found an existing fingerprint/source id.
     * @param bool $warehousePresence F75 (RF-89): en salas de almacén solo `tuya_raw_val=presence`
     *        es creíble (el `move` del 24G se dispara desde el pasillo).
     */
    public static function decide(
        array $event,
        IotSession $session,
        bool $duplicateFingerprint = false,
        bool $entryWindowActive = true,
        bool $insideNoExitCycle = true,
        bool $warehousePresence = false
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

        $evtTs  = self::toEpochMs($occurredAt);
        $lastTs = self::toEpochMs($lastAt);

        // 1) Stale: the fact happened before the last applied event of this sensor.
        // F58 (RF-65): comparación en MILISEGUNDOS. Sin ella, OPEN y CLOSED del
        // mismo segundo se ordenaban por llegada/lock y el OPEN podía revertir
        // un CLOSED ya aplicado (puerta "abierta" tras cerrarla).
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
            if ($warehousePresence) {
                // F75 (RF-89): en el almacén la señal fiable del 24G es `presence`
                // (el `move` no respeta far_detection).
                // F76 (RF-91/92): además exige contexto de puerta/visita real; con
                // la puerta abierta el contexto viene en false (fantasmas del hueco).
                if ($raw !== 'presence' || !($entryWindowActive || $insideNoExitCycle)) {
                    return self::NO_CONTEXT;
                }
            } elseif (!self::presenceCredible($raw, $entryWindowActive, $insideNoExitCycle)) {
                return self::NO_CONTEXT;
            }
        }

        return self::APPLY;
    }

    /**
     * Pure (F48/RF-57 v2): ¿es creíble un PRESENT de presencia dado el contexto?
     *
     * Un PRESENT (`presence` o `move`) solo es creíble si:
     *  - `$entryWindowActive`: hay una apertura acreditada reciente y la entrada
     *    **aún no está confirmada** (el huésped está entrando), o
     *  - `$insideNoExitCycle`: hay estancia confirmada y **ninguna apertura
     *    posterior** a la confirmación (`last_open_at < entry_confirmed_at`).
     *
     * Con esto, tras un ciclo de salida (apertura posterior a la confirmación) la
     * presencia del pasillo (que en estos 24G no respeta `far_detection`) **no**
     * puede re-afirmar `PRESENT`. En habitación FREE sin apertura tampoco.
     *
     * `$rawValue` es `tuya_raw_val` ('presence'|'move'|'none') o '' si no aplica.
     */
    public static function presenceCredible(
        string $rawValue,
        bool $entryWindowActive,
        bool $insideNoExitCycle
    ): bool {
        return $entryWindowActive || $insideNoExitCycle;
    }

    /**
     * Parse ISO-8601 (con o sin fracción) o MySQL UTC datetime a epoch en
     * MILISEGUNDOS. Acepta ambos porque el evento llega en ISO y la sesión
     * guarda MySQL (DATETIME(3)). F58 (RF-65): la fracción es lo que permite
     * ordenar dos eventos del mismo segundo.
     */
    private static function toEpochMs(?string $ts): ?int
    {
        if ($ts === null || $ts === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($ts, new \DateTimeZone('UTC'));
            return $dt->getTimestamp() * 1000 + intdiv((int) $dt->format('u'), 1000);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
