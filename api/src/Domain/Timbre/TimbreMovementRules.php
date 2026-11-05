<?php
declare(strict_types=1);

namespace App\Domain\Timbre;

/**
 * TimbreMovementRules — reglas puras del timbrado (F117).
 *
 * No hace I/O. Traduce presencia/estado de puerta a dirección, decide cuándo
 * empieza un cruce y qué estado final tiene un movimiento al parar.
 */
final class TimbreMovementRules
{
    public const DIRECTION_ENTRY    = 'ENTRY';
    public const DIRECTION_EXIT     = 'EXIT';
    public const DIRECTION_CROSSING = 'CROSSING';
    public const DIRECTION_UNKNOWN  = 'UNKNOWN';

    public const STATUS_RECORDING  = 'RECORDING';
    public const STATUS_COMPLETED  = 'COMPLETED';
    public const STATUS_NO_DOOR    = 'NO_DOOR';
    public const STATUS_NO_PRESENCE = 'NO_PRESENCE';
    public const STATUS_LEFT_OPEN  = 'LEFT_OPEN';
    public const STATUS_MAX_REACHED = 'MAX_REACHED';
    public const STATUS_CANCELLED  = 'CANCELLED';

    public const TRIGGER_SCAN  = 'SCAN';
    public const TRIGGER_DOOR  = 'DOOR';
    public const TRIGGER_RADAR = 'RADAR';

    /** Presencia justo antes de abrir => salida; sin presencia => entrada. */
    public static function directionFromPresence(bool $presenceBefore): string
    {
        return $presenceBefore ? self::DIRECTION_EXIT : self::DIRECTION_ENTRY;
    }

    /** Tipo visible en el listado: sinllave / qr / ficha. */
    public static function typeFor(?string $credentialType, string $trigger): string
    {
        $credential = strtoupper(trim((string) $credentialType));
        if ($credential === 'NFC') {
            return 'ficha';
        }
        if ($credential === 'QR') {
            return 'qr';
        }
        return 'sinllave';
    }

    /** Solo una puerta ya abierta y sin movimiento puede producir un cruce. */
    public static function shouldStartCrossing(
        string $state,
        bool $doorOpen,
        bool $presence,
        bool $hasMovement
    ): bool {
        return $state === 'IDLE' && $doorOpen && $presence && !$hasMovement;
    }

    /**
     * Estado final al parar una grabación.
     *
     * @param bool $maxReached true si venció el tope general o de puerta abierta
     */
    public static function stopStatus(
        string $direction,
        bool $doorLeftOpen,
        bool $presenceSeen,
        bool $maxReached = false
    ): string {
        if ($maxReached) {
            return $doorLeftOpen ? self::STATUS_LEFT_OPEN : self::STATUS_MAX_REACHED;
        }
        if ($direction === self::DIRECTION_ENTRY && !$presenceSeen) {
            return self::STATUS_NO_PRESENCE;
        }
        if ($doorLeftOpen) {
            return self::STATUS_LEFT_OPEN;
        }
        return self::STATUS_COMPLETED;
    }

    /** ¿La parada depende de la ausencia de presencia? */
    public static function stopsOnPresenceEnd(string $direction): bool
    {
        return in_array($direction, [self::DIRECTION_ENTRY, self::DIRECTION_CROSSING, self::DIRECTION_UNKNOWN], true);
    }

    /** ¿La parada depende del cierre de la puerta? */
    public static function stopsOnDoorClose(string $direction): bool
    {
        return $direction === self::DIRECTION_EXIT;
    }
}
