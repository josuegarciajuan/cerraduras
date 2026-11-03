<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

/**
 * RadarPresenceReport — F111.
 *
 * Reporte explicito del DP 1 del radar. El conversor externo publica un
 * marcador opaco "p1|session|counter|PRESENT|origin"; este parser lo valida y
 * lo convierte en evidencia.
 *
 * El marcador es la identidad: Zigbee2MQTT republica el estado combinado cada
 * vez que cambia cualquier atributo (iluminancia, LQI, distancia), de modo que
 * el campo "presence" del payload puede ser un valor heredado. Si el marcador
 * se repite, no hubo un DP 1 nuevo; si cambia, hubo un reporte real.
 */
final class RadarPresenceReport
{
    public const PREFIX = 'p1';
    public const MAX_MARKER_BYTES = 160;

    /**
     * @return array{marker:string,session_id:string,report_counter:int,value:string,origin:string,received_at:string}|null
     */
    public static function fromMarker(mixed $marker, string $receivedAt): ?array
    {
        if (!is_string($marker) || $marker === '' || strlen($marker) > self::MAX_MARKER_BYTES) {
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
        $value = strtoupper(trim($value));
        if (!in_array($value, ['PRESENT', 'ABSENT'], true)) {
            return null;
        }
        $origin = strtolower(trim($origin));
        if (!in_array($origin, ['push', 'unattributed'], true)) {
            return null;
        }
        if ($receivedAt === '') {
            return null;
        }
        return [
            'marker' => $marker,
            'session_id' => $session,
            'report_counter' => (int) $counter,
            'value' => $value,
            'origin' => $origin,
            'received_at' => $receivedAt,
        ];
    }
}
