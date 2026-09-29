<?php
declare(strict_types=1);

namespace App\Support;

/**
 * IsoTime: conversión pura de sellos ISO-8601 a MySQL UTC DATETIME(3).
 *
 * F58 (RF-65): los sellos de Tuya (`status[].t`) llegan en milisegundos y el
 * orden de dos eventos del mismo segundo depende de conservar la fracción.
 *
 * IMPORTANTE: se usa RFC3339_EXTENDED (`...s.vP`) y no un `\Z` literal en el
 * formato: PHP interpretaría el literal en la zona por defecto (p. ej.
 * Europe/Madrid) y desplazaría el sello -2 h, rompiendo la comparación con los
 * ISO del evento (el OPEN parecía más nuevo que el CLOSED ya aplicado).
 */
final class IsoTime
{
    /**
     * Devuelve el sello en UTC con milisegundos (`Y-m-d H:i:s.v`) o `null` si
     * el formato no es reconocible.
     */
    public static function toMysqlUtc(string $iso): ?string
    {
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $iso)
            ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $iso)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $iso)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $iso);

        if ($dt === false) {
            $ts = strtotime($iso);
            if ($ts === false) {
                return null;
            }
            return gmdate('Y-m-d H:i:s', $ts) . '.000';
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
