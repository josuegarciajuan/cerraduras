<?php
declare(strict_types=1);

namespace App\Domain\Warehouse;

use App\Domain\Presence\PresenceEvent;

/**
 * WarehouseLightDecision — automatización de la luz del almacén (F114+).
 *
 * El flujo hotelero apaga la luz en `ExitActionService`, que exige una
 * estancia (`stay`). El almacén no tiene estancias: usa visitas de almacén.
 * Esta decisión pura traduce las transiciones de la sesión IoT al estado
 * deseado de la luz:
 *
 *   PRESENT            -> ON
 *   ABSENT             -> OFF
 *   PROXIMITY OPEN     -> ON
 *   PROXIMITY CLOSED + presencia ABSENT -> OFF
 *
 * Si el estado actual del switch ya coincide, devuelve null para no publicar
 * comandos redundantes. No hace I/O ni consulta dispositivos.
 */
final class WarehouseLightDecision
{
    public const ON = 'ON';
    public const OFF = 'OFF';

    /**
     * @param string $sensor  PROXIMITY | PRESENCE
     * @param string $value   OPEN/CLOSED/PRESENT/ABSENT
     * @param bool   $applied Transición aplicada (no candidato/noop)
     * @param string $sessionPresence PRESENT | ABSENT | UNKNOWN después del evento
     * @param string|null $currentSwitchState ON | OFF | null
     * @return string|null ON | OFF | null (sin cambio)
     */
    public static function decide(
        string $sensor,
        string $value,
        bool $applied,
        string $sessionPresence,
        ?string $currentSwitchState = null
    ): ?string {
        if (!$applied) {
            return null;
        }

        $target = match ($sensor) {
            PresenceEvent::SENSOR_PRESENCE => match ($value) {
                PresenceEvent::VALUE_PRESENT => self::ON,
                PresenceEvent::VALUE_ABSENT => self::OFF,
                default => null,
            },
            PresenceEvent::SENSOR_PROXIMITY => match ($value) {
                PresenceEvent::VALUE_OPEN => self::ON,
                PresenceEvent::VALUE_CLOSED => $sessionPresence === \App\Domain\Presence\IotSession::PRESENCE_ABSENT
                    ? self::OFF
                    : null,
                default => null,
            },
            default => null,
        };

        if ($target === null) {
            return null;
        }

        $current = strtoupper(trim((string) $currentSwitchState));
        if ($current === $target) {
            return null;
        }

        return $target;
    }
}
