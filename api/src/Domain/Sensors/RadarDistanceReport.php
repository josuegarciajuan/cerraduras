<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

/**
 * RadarDistanceReport — F107/RF-230.
 *
 * Un reporte explícito del DP 9 del radar. El conversor externo publica un
 * marcador opaco `v1|session|counter|value|origin`; este parser lo valida y
 * convierte en evidencia. El marcador es la identidad: si se repite, es la
 * misma trama; si cambia, es un reporte nuevo, aunque la distancia sea igual.
 */
final class RadarDistanceReport
{
    public const PREFIX = 'v1';

    /**
     * @return array{marker:string,session_id:string,report_counter:int,distance_m:float,origin:string,received_at:string}|null
     */
    public static function fromMarker(mixed $marker, string $receivedAt): ?array
    {
        if (!is_string($marker) || $marker === '') {
            return null;
        }
        $parts = explode('|', $marker);
        if (count($parts) !== 5 || $parts[0] !== self::PREFIX) {
            return null;
        }
        [, $session, $counter, $value, $origin] = $parts;
        if ($session === '' || strlen($session) > 64 || !ctype_digit($counter)) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $distance = (float) $value;
        if (!is_finite($distance) || $distance < 0.0 || $distance > 99.999) {
            return null;
        }
        $origin = strtolower($origin);
        if (!in_array($origin, ['push', 'query', 'unattributed'], true)) {
            return null;
        }
        // F108: la consulta directa no está soportada. Una marca antigua
        // `query` no se presenta como respuesta; queda sin atribuir.
        if ($origin === 'query') {
            $origin = 'unattributed';
        }
        if ($receivedAt === '') {
            return null;
        }
        return [
            'marker' => $marker,
            'session_id' => $session,
            'report_counter' => (int) $counter,
            'distance_m' => round($distance, 3),
            'origin' => $origin,
            'received_at' => $receivedAt,
        ];
    }
}
