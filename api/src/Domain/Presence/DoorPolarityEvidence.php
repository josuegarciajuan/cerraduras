<?php
declare(strict_types=1);

namespace App\Domain\Presence;

/**
 * DoorPolarityEvidence — F104.3.
 *
 * La polaridad del contacto no se puede inferir del "último evento" sin más:
 * si el último reporte es una apertura, su valor crudo no representa la puerta
 * cerrada y el cálculo oscila entre normal e invertida. Esta clase usa la
 * última evidencia de puerta CERRADA bajo la polaridad vigente y, si no la hay,
 * conserva la polaridad actual en vez de adivinar.
 *
 * Es pura: no escribe en BD ni publica nada.
 */
final class DoorPolarityEvidence
{
    public const BASIS_CLOSED_EVENT = 'closed_event';
    public const BASIS_ASSUMED = 'assumed_current';

    /**
     * @param list<array<string,mixed>> $events Eventos de PROXIMITY, preferiblemente del más reciente al más antiguo.
     * @return array{polarity:string,changed:bool,basis:string,raw:mixed,event_id:?int,occurred_at:?string}
     */
    public static function infer(array $events, string $currentPolarity): array
    {
        $current = self::normalizePolarity($currentPolarity);
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $meta = is_array($event['meta'] ?? null) ? $event['meta'] : [];
            if (isset($meta['entity']) && strtolower((string) $meta['entity']) !== 'contact') {
                continue;
            }
            if (($meta['bootstrap'] ?? false) === true) {
                continue;
            }
            $source = strtolower((string) ($meta['source'] ?? ''));
            $discard = strtolower((string) ($event['discard_reason'] ?? ''));
            if ($source === 'resync' || in_array($discard, ['bootstrap', 'resync'], true)) {
                continue;
            }
            // Un evento canonizado con otra polaridad no es evidencia fiable
            // para la polaridad vigente. Los eventos antiguos sin marca se
            // aceptan para no romper instalaciones previas.
            if (isset($meta['door_polarity'])
                && self::normalizePolarity((string) $meta['door_polarity']) !== $current) {
                continue;
            }
            if (strtoupper((string) ($event['value'] ?? '')) !== 'CLOSED') {
                continue;
            }

            $raw = $meta['zigbee_value'] ?? null;
            if ($raw === null && isset($meta['canonical_before_polarity'])) {
                $raw = strtoupper((string) $meta['canonical_before_polarity']) === 'OPEN' ? true : false;
            }
            if ($raw === null) {
                continue;
            }
            $rawClosed = self::isClosedRaw($raw);
            $polarity = $rawClosed ? DoorPolarity::NORMAL : DoorPolarity::INVERTED;
            return [
                'polarity' => $polarity,
                'changed' => $polarity !== $current,
                'basis' => self::BASIS_CLOSED_EVENT,
                'raw' => $raw,
                'event_id' => isset($event['id']) ? (int) $event['id'] : null,
                'occurred_at' => isset($event['occurred_at']) ? (string) $event['occurred_at'] : null,
            ];
        }

        return [
            'polarity' => $current,
            'changed' => false,
            'basis' => self::BASIS_ASSUMED,
            'raw' => null,
            'event_id' => null,
            'occurred_at' => null,
        ];
    }

    public static function isClosedRaw(mixed $raw): bool
    {
        if ($raw === false || $raw === 0) {
            return true;
        }
        $value = strtolower(trim((string) $raw));
        return in_array($value, ['false', '0', 'closed', 'off'], true);
    }

    private static function normalizePolarity(string $polarity): string
    {
        return strtolower(trim($polarity)) === DoorPolarity::INVERTED
            ? DoorPolarity::INVERTED
            : DoorPolarity::NORMAL;
    }
}
