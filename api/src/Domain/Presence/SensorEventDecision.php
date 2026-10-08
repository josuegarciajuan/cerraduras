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
 *     / REFRESH using the last applied marks of the same sensor on the locked
 *     session. REFRESH (F86/RF-115) es un reporte real de puerta con el mismo
 *     valor: refresca la frescura sin cambiar el estado.
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
     * F86 (RF-115): reporte REAL de puerta con el MISMO valor pero instante nuevo.
     * El contacto es edge-triggered: un nuevo reporte del mismo valor es un cambio
     * físico que el dominio daba por hecho (p. ej. un `CLOSED` perdido dejó `OPEN`
     * atascado). Refresca la frescura sin cambiar `door_state`.
     */
    public const REFRESH = 'refresh';

    /**
     * F48 (RF-57): PRESENT de presencia no creíble por contexto (p. ej. `move`
     * fuera de la ventana de entrada, o presencia en habitación FREE sin estancia).
     * Se audita pero no altera el estado (evita presencia fantasma del pasillo).
     */
    public const NO_CONTEXT = 'no_context';

    /**
     * F88 (RF-123.3): PRESENT de radar del almacén sin evidencia física (movimiento
     * de cámara ni ciclo de puerta reciente). Se audita pero no se aplica: evita
     * visitas y grabaciones fantasma.
     */
    public const UNCORROBORATED = 'uncorroborated';

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
     * @param bool $warehousePresence F80 (RF-103.1): en salas de almacén la presencia es creíble al
     *        instante (F71 restaurado); se aceptan `presence` y `move` y no hay veto por puerta.
     * @param bool $physicalEvidence F88 (RF-123.3): en el almacén, evidencia física
     *        (movimiento de cámara o ciclo de puerta reciente). Si es `false`, un
     *        PRESENT de radar se descarta como `uncorroborated` (sin visita ni grabación).
     */
    public static function decide(
        array $event,
        IotSession $session,
        bool $duplicateFingerprint = false,
        bool $entryWindowActive = true,
        bool $insideNoExitCycle = true,
        bool $warehousePresence = false,
        bool $physicalEvidence = true
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

        $provider = (string) ($event['provider'] ?? '');
        $source   = strtolower((string) (($event['meta'] ?? [])['source'] ?? ''));

        // 2b) F86 (RF-115): reporte REAL de puerta con el mismo valor y un instante
        // NUEVO. El sensor es edge-triggered: si vuelve a reportar el mismo valor es
        // que la puerta cambió a ese estado cuando el dominio creía el anterior (un
        // `CLOSED` perdido dejó `OPEN` "atascado"). Se refresca la frescura sin
        // cambiar `door_state`; el resync REST queda excluido (no es una transición).
        if ($isDoor && $lastVal !== null && $value === $lastVal
            && $provider === PresenceEvent::PROVIDER_TUYA && $source !== 'resync') {
            return self::REFRESH;
        }

        // 3) Value already in force: no transition.
        if ($lastVal !== null && $value === $lastVal) {
            return self::NOOP;
        }

        // 4) F48 (RF-57): credibilidad por contexto. Solo aplica a eventos REALES
        // del sensor (provider TUYA); las inyecciones simuladas (/sim/*) se
        // respetan tal cual (herramienta de desarrollo/tests). Solo afecta a
        // PRESENT de presencia; ABSENT (`none`) siempre se aplica para limpiar.
        if ($provider === PresenceEvent::PROVIDER_TUYA
            && $sensor === PresenceEvent::SENSOR_PRESENCE
            && $value === PresenceEvent::VALUE_PRESENT
        ) {
            $raw = strtolower((string) (($event['meta'] ?? [])['tuya_raw_val'] ?? ''));
            if ($warehousePresence) {
                // F88 (RF-123.3): en el almacén la presencia se acepta al instante
                // (F80/F71) SOLO si hay evidencia física independiente (movimiento de
                // cámara o ciclo de puerta reciente). Sin ella, el radar aislado es un
                // fantasma (`uncorroborated`) y no debe crear visita ni grabación.
                // ABSENT (`none`) no pasa por esta rama y siempre se aplica.
                return $physicalEvidence ? self::APPLY : self::UNCORROBORATED;
            }
            if (!self::presenceCredible($raw, $entryWindowActive, $insideNoExitCycle)) {
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
