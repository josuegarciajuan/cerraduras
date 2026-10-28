<?php
declare(strict_types=1);

namespace App\Domain\Presence;

interface PresenceEventRepositoryInterface
{
    /**
     * Persist a presence event.
     *
     * Returns the new row ID on success.
     * Returns null if source_event_id already exists (idempotent duplicate).
     *
     * @deprecated F41: use insertOrGet(), which also covers the logical
     *             fingerprint and returns the raw event for auditing.
     *
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
    ): ?int;

    /**
     * Insert the raw event computing its logical fingerprint, or return the
     * existing row when the fingerprint / source_event_id collides (1062).
     *
     * The raw event is always available for auditing, new or not (RF-44.3).
     *
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
    ): array;

    /**
     * Fill applied/discard_reason on an already-persisted raw event.
     * Idempotent: only fills rows that are still unaudited (applied IS NULL)
     * so a transport re-send never rewrites the original decision.
     */
    public function markAudit(int $id, bool $applied, ?string $discardReason): void;

    /**
     * Return the latest N events for a room, newest first.
     *
     * F48: `$appliedOnly = true` devuelve solo hechos aplicados (`applied = 1`).
     * Lo usa el panel (`/live`/SSE): los eventos descartados (duplicate/stale/
     * noop/no_context) no deben disparar la coreografía.
     *
     * @return list<PresenceEvent>
     */
    public function listForRoom(int $roomId, int $limit = 20, bool $appliedOnly = false): array;

    /**
     * F93/RF-154.3: eventos de la sala desde un instante (ventana temporal
     * explícita, no un número arbitrario de filas). Orden: más reciente primero.
     *
     * @return list<PresenceEvent>
     */
    public function listForRoomSince(int $roomId, \DateTimeImmutable $since, int $limit = 5000): array;

    /**
     * F93/RF-156.1: fusiona claves en meta_json de un evento ya persistido
     * (p. ej. la decisión radar calculada bajo el lock de la sesión).
     */
    public function updateMetaJson(int $id, array $delta): void;
}
