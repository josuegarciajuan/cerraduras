<?php
declare(strict_types=1);

namespace App\Domain\Sensors;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * AttemptEvidence — F95/RF-168.
 *
 * Resumen puro de la ventana de un intento, para que la UI no mezcle eventos
 * anteriores. Calcula ciclos de puerta y presencia real a partir de los eventos
 * del intento (el servidor sigue siendo la autoridad del veredicto).
 */
final class AttemptEvidence
{
    /**
     * @param list<array<string,mixed>> $events
     * @return array{door:array<string,mixed>,presence:array<string,mixed>}
     */
    public static function build(array $events): array
    {
        $doorEvents = [];
        $presence = [];
        foreach ($events as $e) {
            if (!is_array($e)) {
                continue;
            }
            $sensor = strtoupper((string) ($e['sensor'] ?? ''));
            if ($sensor === 'PROXIMITY' && ($e['applied'] ?? false) === true) {
                $doorEvents[] = $e;
            }
            if ($sensor === 'PRESENCE'
                && in_array((string) ($e['value'] ?? ''), ['PRESENT', 'ABSENT'], true)
            ) {
                $src = strtolower((string) (($e['meta'] ?? [])['source'] ?? ''));
                if ($src !== 'resync') {
                    $presence[] = $e;
                }
            }
        }
        $doorEvents = self::sort($doorEvents);
        $presence = self::sort($presence);

        // Ciclos: pares OPEN -> CLOSED estrictos.
        $cycles = [];
        $openAt = null;
        $lastDoor = null;
        foreach ($doorEvents as $e) {
            $value = (string) ($e['value'] ?? '');
            $lastDoor = $value;
            if ($value === 'OPEN' && $openAt === null) {
                $openAt = (string) ($e['occurred_at'] ?? '');
                continue;
            }
            if ($value === 'CLOSED' && $openAt !== null) {
                $cycles[] = ['open_at' => $openAt, 'close_at' => (string) ($e['occurred_at'] ?? '')];
                $openAt = null;
            }
        }
        $inProgress = $openAt !== null;
        $target = 3;
        $cycleCount = count($cycles);
        $waitingFor = $inProgress ? 'close' : ($cycleCount >= $target ? 'complete' : 'open');

        // Presencia real: primer/último PRESENT y continuidad.
        $presents = array_values(array_filter($presence, static fn (array $e): bool => ($e['value'] ?? '') === 'PRESENT'));
        $absents = array_values(array_filter($presence, static fn (array $e): bool => ($e['value'] ?? '') === 'ABSENT'));
        $firstPresent = $presents[0]['occurred_at'] ?? null;
        $lastPresent = $presents[count($presents) - 1]['occurred_at'] ?? null;
        $firstMs = self::ms($firstPresent);
        $lastMs = self::ms($lastPresent);
        $span = ($firstMs !== null && $lastMs !== null) ? max(0, ($lastMs - $firstMs) / 1000) : 0.0;
        $absentAfter = false;
        foreach ($absents as $a) {
            $ts = self::ms($a['occurred_at'] ?? null);
            if ($ts !== null && $firstMs !== null && $ts > $firstMs) {
                $absentAfter = true;
                break;
            }
        }
        $lastPresence = $presence === [] ? null : (string) ($presence[count($presence) - 1]['value'] ?? '');

        return [
            'door' => [
                'cycles' => $cycles,
                'count' => count($cycles),
                'target' => $target,
                'last_value' => $lastDoor,
                'waiting_for' => $waitingFor,
                'complete' => $cycleCount >= $target,
                'events' => count($doorEvents),
            ],
            'presence' => [
                'reports' => count($presence),
                'presents' => count($presents),
                'absents' => count($absents),
                'first_present_at' => $firstPresent,
                'last_present_at' => $lastPresent,
                'span_seconds' => round($span, 3),
                'continuous' => $presents !== [] && !$absentAfter,
                'active' => $lastPresence === 'PRESENT',
                'last_value' => $lastPresence,
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return list<array<string,mixed>>
     */
    private static function sort(array $events): array
    {
        usort($events, static function (array $a, array $b): int {
            return (self::ms($a['occurred_at'] ?? null) ?? 0) <=> (self::ms($b['occurred_at'] ?? null) ?? 0);
        });
        return $events;
    }

    private static function ms(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return $dt->getTimestamp() * 1000 + intdiv((int) $dt->format('u'), 1000);
        } catch (Throwable) {
            return null;
        }
    }
}
