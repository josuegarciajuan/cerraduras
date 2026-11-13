<?php
declare(strict_types=1);

namespace App\Domain\Presence;

/**
 * RadarRoomLightPolicy — F137.
 *
 * Política pura del apagado de luz para salas normales con una política radar
 * explícita (`rooms.presence_mode_override`). El encendido sigue gobernado por
 * la apertura de puerta y por la credencial (F124); aquí solo se decide cuándo
 * programar o cancelar el OFF.
 *
 * El apagado exige un ciclo de puerta completado (OPEN y después CLOSED):
 *   - una presencia ABSENT aplicada después de ese ciclo, o
 *   - un CLOSED aplicado con la sesión ya en ABSENT.
 *
 * Un OPEN o un PRESENT aplicado cancelan cualquier OFF pendiente. No hace I/O:
 * el llamador persiste `iot_sessions.light_off_at` y exit-scan ejecuta el
 * comando tras el retardo configurable.
 */
final class RadarRoomLightPolicy
{
    public const ACTION_CANCEL_OFF   = 'cancel_off';
    public const ACTION_SCHEDULE_OFF = 'schedule_off';
    public const ACTION_NONE         = 'none';

    public static function decide(
        string $sensor,
        string $value,
        string $sessionPresence,
        bool $hasDoorCycle
    ): string {
        $sensor = strtoupper(trim($sensor));
        $value = strtoupper(trim($value));
        $sessionPresence = strtoupper(trim($sessionPresence));

        // Un ciclo nuevo (apertura) o una presencia confirmada cancelan el OFF.
        if ($sensor === PresenceEvent::SENSOR_PROXIMITY
            && $value === PresenceEvent::VALUE_OPEN
        ) {
            return self::ACTION_CANCEL_OFF;
        }
        if ($sensor === PresenceEvent::SENSOR_PRESENCE
            && $value === PresenceEvent::VALUE_PRESENT
        ) {
            return self::ACTION_CANCEL_OFF;
        }

        // Sin ciclo de puerta no se apaga: evita apagar una luz encendida
        // manualmente o por credencial sin que nadie haya cruzado la puerta.
        if (!$hasDoorCycle) {
            return self::ACTION_NONE;
        }

        if ($sensor === PresenceEvent::SENSOR_PRESENCE
            && $value === PresenceEvent::VALUE_ABSENT
        ) {
            return self::ACTION_SCHEDULE_OFF;
        }
        if ($sensor === PresenceEvent::SENSOR_PROXIMITY
            && $value === PresenceEvent::VALUE_CLOSED
            && $sessionPresence === IotSession::PRESENCE_ABSENT
        ) {
            return self::ACTION_SCHEDULE_OFF;
        }

        return self::ACTION_NONE;
    }

    /**
     * Un ciclo de puerta está completado cuando hay un cierre posterior (o del
     * mismo instante) a la última apertura. Ambos valores son DATETIME(3) UTC.
     */
    public static function hasDoorCycle(?string $lastOpenAt, ?string $lastCloseAt): bool
    {
        if ($lastOpenAt === null || $lastCloseAt === null
            || trim($lastOpenAt) === '' || trim($lastCloseAt) === ''
        ) {
            return false;
        }
        $open = strtotime($lastOpenAt . ' UTC');
        $close = strtotime($lastCloseAt . ' UTC');
        return $open !== false && $close !== false && $close >= $open;
    }
}
