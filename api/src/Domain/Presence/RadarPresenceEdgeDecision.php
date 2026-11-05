<?php
declare(strict_types=1);

namespace App\Domain\Presence;

use DateTimeImmutable;
use DateTimeZone;

/**
 * RadarPresenceEdgeDecision — borde DP1 del radar Zigbee (F114+).
 *
 * El ZY-M100/S_2 publica el DP 1 solo cuando cambia. La trama combinada de
 * Zigbee2MQTT repite el último valor cacheado, así que contar frames como
 * "reportes independientes" añade latencia sin evidencia nueva.
 *
 * Esta decisión pura usa el último reporte DP 1 persistido:
 *   - PRESENT con antigüedad <= maxAge => PRESENT_CONFIRMED (aplicar ya).
 *   - ABSENT con antigüedad >= gracia  => ABSENT_CONFIRMED.
 *   - ABSENT con antigüedad <  gracia  => CANDIDATE_ABSENT (sin efectos).
 *
 * El marcador no se usa como "cambio de valor": hay marcadores PRESENT
 * consecutivos y ABSENT repetidos. La autoridad es el valor del DP1 comparado
 * con el estado aplicado por el llamador; esta clase solo valida el borde y la
 * antigüedad.
 *
 * No hace I/O, no consulta el dispositivo y no crea temporizadores.
 */
final class RadarPresenceEdgeDecision
{
    public const DEFAULT_ABSENT_GRACE_MS = 500;
    public const DEFAULT_PRESENT_MAX_AGE_MS = 120000;

    public const STATE_PRESENT = 'PRESENT_CONFIRMED';
    public const STATE_CANDIDATE_ABSENT = 'CANDIDATE_ABSENT';
    public const STATE_ABSENT = 'ABSENT_CONFIRMED';

    /**
     * @param array{value?:string,marker?:string,received_at?:string}|null $latestReport
     * @return array<string,mixed>|null
     */
    public static function evaluate(
        ?array $latestReport,
        DateTimeImmutable $frameTime,
        int $absentGraceMs = self::DEFAULT_ABSENT_GRACE_MS,
        int $presentMaxAgeMs = self::DEFAULT_PRESENT_MAX_AGE_MS,
        ?string $currentState = null
    ): ?array {
        if ($latestReport === null) {
            return null;
        }
        $value = strtoupper(trim((string) ($latestReport['value'] ?? '')));
        if (!in_array($value, [PresenceEvent::VALUE_PRESENT, PresenceEvent::VALUE_ABSENT], true)) {
            return null;
        }

        $reportMs = self::parseMs($latestReport['received_at'] ?? null);
        if ($reportMs === null) {
            return null;
        }
        $frameMs = self::frameMs($frameTime);
        $ageMs = max(0, $frameMs - $reportMs);
        $ageSeconds = $ageMs / 1000;

        // Sin transición: el frame cacheado solo refresca liveness. Evita
        // recalcular el histórico completo en cada trama de una presencia larga.
        if ($currentState === $value) {
            return self::result(
                $value === PresenceEvent::VALUE_PRESENT ? self::STATE_PRESENT : self::STATE_ABSENT,
                $value,
                false,
                'noop',
                $latestReport,
                $ageSeconds,
                null,
                $frameTime
            );
        }

        if ($value === PresenceEvent::VALUE_PRESENT) {
            if ($ageMs > max(1000, $presentMaxAgeMs)) {
                return null; // DP1 demasiado viejo: que decida el fallback.
            }
            return self::result(
                self::STATE_PRESENT,
                $value,
                true,
                null,
                $latestReport,
                $ageSeconds,
                null,
                $frameTime
            );
        }

        $graceMs = max(0, $absentGraceMs);
        $confirmed = $ageMs >= $graceMs;
        return self::result(
            $confirmed ? self::STATE_ABSENT : self::STATE_CANDIDATE_ABSENT,
            $value,
            $confirmed,
            $confirmed ? null : 'absent_pending',
            $latestReport,
            $ageSeconds,
            $graceMs / 1000,
            $frameTime
        );
    }

    /**
     * @param array<string,mixed> $latestReport
     * @return array<string,mixed>
     */
    private static function result(
        string $state,
        string $value,
        bool $apply,
        ?string $discardReason,
        array $latestReport,
        float $ageSeconds,
        ?float $graceSeconds,
        DateTimeImmutable $frameTime
    ): array {
        $frameUtc = $frameTime->setTimezone(new DateTimeZone('UTC'));

        return [
            'source' => 'dp1_edge',
            'mode' => 'dp1_edge',
            'operational_mode' => 'dp1_edge',
            'state' => $state,
            'raw' => $value,
            'value' => $value,
            'confirmed' => $apply,
            'apply' => $apply,
            'reports' => 1,
            'span_seconds' => $ageSeconds,
            'max_gap_seconds' => 0.0,
            'door_corroborated' => false,
            'stale' => false,
            'discard_reason' => $discardReason,
            'decided_at' => $frameUtc->format('Y-m-d\TH:i:s.v\Z'),
            'marker' => (string) ($latestReport['marker'] ?? ''),
            'report_age_seconds' => $ageSeconds,
            'grace_seconds' => $graceSeconds,
        ];
    }

    /**
     * Antigüedad en milisegundos. Acepta el formato MySQL (UTC) del repositorio
     * y el ISO con milisegundos del mapper.
     */
    public static function parseMs(mixed $timestamp): ?int
    {
        if (!is_string($timestamp) || trim($timestamp) === '') {
            return null;
        }
        $value = trim($timestamp);
        $utc = new DateTimeZone('UTC');
        foreach ([
            'Y-m-d H:i:s.u',
            'Y-m-d\TH:i:s.v\Z',
            'Y-m-d\TH:i:s.u\Z',
            'Y-m-d H:i:s',
            'Y-m-d\TH:i:s\Z',
        ] as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $value, $utc);
            if ($dt !== false) {
                return self::frameMs($dt);
            }
        }
        $ts = strtotime($value . (str_contains($value, 'T') ? '' : ' UTC'));
        return $ts === false ? null : $ts * 1000;
    }

    private static function frameMs(DateTimeImmutable $dt): int
    {
        $utc = $dt->setTimezone(new DateTimeZone('UTC'));
        return $utc->getTimestamp() * 1000 + intdiv((int) $utc->format('u'), 1000);
    }
}
