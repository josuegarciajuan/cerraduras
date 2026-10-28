<?php
declare(strict_types=1);

namespace App\Infrastructure\Sensors;

/**
 * DeviceHealth — F95/RF-163.4.
 *
 * La salud no puede medirse igual para todos los dispositivos:
 *   - PRESENCE (ZY-M100-S_2, Router): reporta periódicamente. Si no hay push
 *     reciente, está "SIN DATOS".
 *   - PROXIMITY (SNZB-04P, EndDevice de batería) y SWITCH (ZBMINIL2, EndDevice):
 *     solo envían al cambiar. Su silencio es normal y no equivale a "sin acceso".
 *
 * `idle` es un dispositivo reconocido sin actividad reciente; se muestra en
 * verde con el detalle en ámbar. `stale` es el único silencio que se considera
 * una falta de datos (radar).
 */
final class DeviceHealth
{
    public const ONLINE = 'online';
    public const IDLE = 'idle';
    public const STALE = 'stale';
    public const MISSING = 'missing';

    public static function expectsPeriodicPush(string $kind): bool
    {
        return strtoupper($kind) === 'PRESENCE';
    }

    /**
     * @return array{state:string,label:string,tone:string,detail:string,expects_periodic_push:bool}
     */
    public static function classify(bool $exists, bool $fresh, string $kind, ?int $ageSeconds): array
    {
        $periodic = self::expectsPeriodicPush($kind);
        if (!$exists) {
            return [
                'state' => self::MISSING,
                'label' => 'SIN ACCESO',
                'tone' => 'bad',
                'detail' => 'No se reconoce el dispositivo',
                'expects_periodic_push' => $periodic,
            ];
        }
        if ($fresh) {
            return [
                'state' => self::ONLINE,
                'label' => 'EN LÍNEA',
                'tone' => 'ok',
                'detail' => $ageSeconds !== null ? 'hace ' . $ageSeconds . ' s' : 'reportando',
                'expects_periodic_push' => $periodic,
            ];
        }
        if ($periodic) {
            return [
                'state' => self::STALE,
                'label' => 'SIN DATOS',
                'tone' => 'warn',
                'detail' => $ageSeconds !== null ? 'sin reportes hace ' . $ageSeconds . ' s' : 'sin reportes recientes',
                'expects_periodic_push' => true,
            ];
        }
        return [
            'state' => self::IDLE,
            'label' => 'RECONOCIDO',
            'tone' => 'ok',
            'detail' => $ageSeconds !== null ? 'sin actividad hace ' . $ageSeconds . ' s' : 'sin actividad reciente',
            'expects_periodic_push' => false,
        ];
    }
}
