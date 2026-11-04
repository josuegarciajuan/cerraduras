<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use DateTimeImmutable;
use DateTimeZone;

/**
 * RadarAbsenceEdgeDecision — salida fiable del radar Zigbee.
 *
 * El ZY-M100/S_2 es edge-triggered para el DP 1: publica `ABSENT` una sola vez
 * cuando deja de detectar. El estado combinado de Zigbee2MQTT repite ese valor
 * cacheado en cada trama, por lo que exigir "3 reportes / 30 s" solo añade
 * latencia, no evidencia nueva.
 *
 * Esta decisión pura toma el último reporte DP 1 persistido:
 *   - `ABSENT` con antigüedad >= gracia  => ABSENT_CONFIRMED (aplicar).
 *   - `ABSENT` con antigüedad <  gracia  => CANDIDATE_ABSENT (sin efectos).
 *   - `PRESENT` o sin reporte            => null (decisión normal del radar).
 *
 * No hace I/O, no consulta el dispositivo y no crea temporizadores.
 */
final class RadarAbsenceEdgeDecision
{
    public const DEFAULT_GRACE_SECONDS = 5;
    public const STATE_PENDING = 'CANDIDATE_ABSENT';
    public const STATE_CONFIRMED = 'ABSENT_CONFIRMED';

    /**
     * @param array{value?:string,marker?:string,received_at?:string}|null $latestReport
     * @return array<string,mixed>|null
     */
    public static function evaluate(
        ?array $latestReport,
        DateTimeImmutable $now,
        int $graceSeconds = self::DEFAULT_GRACE_SECONDS
    ): ?array {
        if ($latestReport === null) {
            return null;
        }
        $value = strtoupper(trim((string) ($latestReport['value'] ?? '')));
        if ($value !== PresenceEvent::VALUE_ABSENT) {
            return null;
        }

        $graceSeconds = max(1, $graceSeconds);
        $receivedAt = trim((string) ($latestReport['received_at'] ?? ''));
        $age = null;
        if ($receivedAt !== '') {
            $ts = strtotime($receivedAt . (str_contains($receivedAt, 'T') ? '' : ' UTC'));
            if ($ts !== false) {
                $age = max(0, $now->getTimestamp() - $ts);
            }
        }

        $confirmed = $age !== null && $age >= $graceSeconds;
        $nowUtc = $now->setTimezone(new DateTimeZone('UTC'));

        return [
            'source' => 'dp1_edge',
            'mode' => 'dp1_edge',
            'operational_mode' => 'dp1_edge',
            'state' => $confirmed ? self::STATE_CONFIRMED : self::STATE_PENDING,
            'raw' => $value,
            'value' => $value,
            'confirmed' => $confirmed,
            'apply' => $confirmed,
            'reports' => 1,
            'span_seconds' => (float) ($age ?? 0),
            'max_gap_seconds' => 0.0,
            'door_corroborated' => false,
            'stale' => false,
            'discard_reason' => $confirmed ? null : 'absent_edge_pending',
            'decided_at' => $nowUtc->format('Y-m-d\TH:i:s.v\Z'),
            'marker' => (string) ($latestReport['marker'] ?? ''),
            'report_age_seconds' => $age,
            'grace_seconds' => $graceSeconds,
        ];
    }
}
