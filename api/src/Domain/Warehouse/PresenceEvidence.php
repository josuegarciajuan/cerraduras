<?php
declare(strict_types=1);

namespace App\Domain\Warehouse;

/**
 * PresenceEvidence: clasificador PURO de evidencia de un episodio de radar del
 * almacén (F84 / RF-107).
 *
 * F80 (RF-103) mantiene la presencia del radar creíble al instante, con o sin
 * contexto. El análisis de los fantasmas de la madrugada 2026-10-06 muestra que
 * un falso positivo es un parpadeo único (`move` -> `presence` -> `none`),
 * mientras que una persona en movimiento reemite transiciones (`move`/`presence`).
 *
 * Al terminar el episodio, el motor confirma la visita si:
 *   - movimientos distintos >= min_moves (def. 2), o
 *   - eventos PRESENT distintos >= min_events (def. 3), o
 *   - duración >= static_seconds (def. 300), o
 *   - hubo un evento de puerta aplicado dentro del episodio (evidencia extra).
 *
 * Sin I/O, sin tiempo: totalmente unit-testeable.
 */
final class PresenceEvidence
{
    public const DEFAULT_MIN_MOVES = 2;
    public const DEFAULT_MIN_EVENTS = 3;
    public const DEFAULT_STATIC_SECONDS = 300;

    /**
     * @param int   $moves   nº de eventos `move` distintos del episodio
     * @param int   $events  nº de eventos PRESENT distintos (move+presence)
     * @param float $seconds duración del episodio
     * @param bool  $doorEvent hubo evento PROXIMITY aplicado dentro del episodio
     * @param array{min_moves?:int,min_events?:int,static_seconds?:int} $config
     */
    public static function isConfirmed(
        int $moves,
        int $events,
        float $seconds,
        bool $doorEvent,
        array $config = []
    ): bool {
        $minMoves  = self::intCfg($config, 'min_moves', self::DEFAULT_MIN_MOVES);
        $minEvents = self::intCfg($config, 'min_events', self::DEFAULT_MIN_EVENTS);
        $static    = self::intCfg($config, 'static_seconds', self::DEFAULT_STATIC_SECONDS);

        if ($moves >= $minMoves) {
            return true;
        }
        if ($events >= $minEvents) {
            return true;
        }
        if ($seconds >= $static) {
            return true;
        }
        return $doorEvent;
    }

    /**
     * Normaliza los umbrales de una fila de `room_types` (o de config).
     *
     * @param array<string,mixed> $row
     * @return array{min_moves:int,min_events:int,static_seconds:int}
     */
    public static function configFromRow(array $row): array
    {
        $moves  = (int) ($row['warehouse_presence_min_moves'] ?? 0);
        $events = (int) ($row['warehouse_presence_min_events'] ?? 0);
        $static = (int) ($row['warehouse_presence_static_seconds'] ?? 0);
        return [
            'min_moves'      => $moves > 0 ? $moves : self::DEFAULT_MIN_MOVES,
            'min_events'     => $events > 0 ? $events : self::DEFAULT_MIN_EVENTS,
            'static_seconds' => $static > 0 ? $static : self::DEFAULT_STATIC_SECONDS,
        ];
    }

    /** @param array<string,mixed> $config */
    private static function intCfg(array $config, string $key, int $default): int
    {
        if (!isset($config[$key])) {
            return $default;
        }
        $v = (int) $config[$key];
        return $v > 0 ? $v : $default;
    }
}
