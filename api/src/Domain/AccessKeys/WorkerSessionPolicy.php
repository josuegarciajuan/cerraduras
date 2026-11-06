<?php
declare(strict_types=1);

namespace App\Domain\AccessKeys;

/**
 * WorkerSessionPolicy — F117/RF-303: TIMBRADO no crea ni bloquea sesiones de
 * trabajador. Cada escaneo debe poder abrir la puerta; la auditoría de la
 * apertura vive en `timbre_movements` y `key_access_events`, no en
 * `worker_sessions`.
 *
 * El resto de salas (hotel/almacén) conserva el comportamiento actual:
 * sesión por entrada y conflicto `already_inside` si se reescanea estando
 * dentro.
 */
final class WorkerSessionPolicy
{
    public const ROOM_TYPE_TIMBRADO = 'TIMBRADO';

    public static function tracksSessions(?string $roomTypeCode): bool
    {
        return strtoupper(trim((string) $roomTypeCode)) !== self::ROOM_TYPE_TIMBRADO;
    }
}
