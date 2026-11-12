<?php
declare(strict_types=1);

namespace App\Domain\Analytics;

use DateTimeImmutable;

/**
 * AccessMovementCollapser — colapsa movimientos de acceso duplicados (F129).
 *
 * Una sola apertura con ficha puede quedar registrada en `key_access_events`
 * (NFC) y en `access_events` (QR_VALIDATE + OPEN) compartiendo el mismo
 * `correlation_id`. Este colapsador une esas filas para la lista visible del
 * panel sin tocar contadores ni escrituras.
 *
 * Reglas puras, sin I/O. Tolera claves ausentes y valores no string.
 */
final class AccessMovementCollapser
{
    /** Tipos que representan un movimiento de acceso colapsable. */
    private const MOVEMENT_TYPES = ['QR', 'NFC', 'QR_VALIDATE', 'OPEN', 'DENIED'];

    /**
     * @param array<int|string, mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function collapse(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $source = is_string($row['source'] ?? null) ? $row['source'] : '';
            $type = self::typeOf($row);
            $correlation = $row['correlation_id'] ?? null;

            if (self::isMovement($type) && self::hasCorrelation($correlation)) {
                $groupKey = 'c:' . (string) $correlation;
            } else {
                $id = isset($row['id']) && is_scalar($row['id']) ? (string) $row['id'] : '';
                $groupKey = 'u:' . $source . ':' . $id;
            }

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [];
            }
            $groups[$groupKey][] = $row;
        }

        $collapsed = [];
        foreach ($groups as $groupRows) {
            $collapsed[] = self::merge($groupRows);
        }

        usort($collapsed, static function (array $a, array $b): int {
            return self::compareDesc($a['occurred_at'] ?? null, $b['occurred_at'] ?? null);
        });

        return $collapsed;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private static function merge(array $rows): array
    {
        $first = $rows[0];

        $keyRow = null;
        foreach ($rows as $row) {
            if (($row['source'] ?? null) === 'KEY') {
                $keyRow = $row;
                break;
            }
        }

        // Fila base: conserva el resto de campos sin inventarlos.
        $base = $keyRow ?? $first;
        $merged = $base;

        // occurred_at = la más temprana del grupo.
        $earliest = $first;
        $earliestTs = self::timestamp($earliest['occurred_at'] ?? null);
        foreach ($rows as $row) {
            $ts = self::timestamp($row['occurred_at'] ?? null);
            if ($ts !== null && ($earliestTs === null || $ts < $earliestTs)) {
                $earliest = $row;
                $earliestTs = $ts;
            }
        }
        if (array_key_exists('occurred_at', $earliest)) {
            $merged['occurred_at'] = $earliest['occurred_at'];
        }

        // type: KEY manda; si no, QR_VALIDATE; si no, el primero.
        if ($keyRow !== null) {
            $merged['type'] = self::typeOf($keyRow);
        } else {
            $type = null;
            foreach ($rows as $row) {
                if (self::typeOf($row) === 'QR_VALIDATE') {
                    $type = 'QR_VALIDATE';
                    break;
                }
            }
            if ($type === null) {
                $type = self::typeOf($first);
            }
            $merged['type'] = $type;
        }

        // result: FAIL si cualquier fila del grupo es FAIL.
        $result = 'OK';
        $reason = null;
        foreach ($rows as $row) {
            if (($row['result'] ?? null) === 'FAIL') {
                $result = 'FAIL';
                if ($reason === null) {
                    $candidate = $row['reason'] ?? null;
                    if (is_string($candidate) && trim($candidate) !== '') {
                        $reason = $candidate;
                    }
                }
            }
        }
        $merged['result'] = $result;
        $merged['reason'] = $reason;

        // room_id / room_code: primera fila que los tenga.
        $merged['room_id'] = self::firstValue($rows, 'room_id') ?? ($base['room_id'] ?? null);
        $merged['room_code'] = self::firstValue($rows, 'room_code') ?? ($base['room_code'] ?? null);

        // Datos de credencial/trabajador.
        foreach (['worker_id', 'worker_name', 'key_label', 'owner_type'] as $field) {
            if ($keyRow !== null) {
                $merged[$field] = array_key_exists($field, $keyRow)
                    ? $keyRow[$field]
                    : self::firstValue($rows, $field);
            } else {
                $merged[$field] = self::firstValue($rows, $field);
            }
        }

        $merged['source'] = $keyRow !== null ? 'KEY' : 'ACCESS';

        return $merged;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function typeOf(array $row): ?string
    {
        $type = $row['type'] ?? $row['credential_type'] ?? null;

        return is_string($type) && $type !== '' ? $type : null;
    }

    private static function isMovement(?string $type): bool
    {
        return $type !== null && in_array($type, self::MOVEMENT_TYPES, true);
    }

    private static function hasCorrelation(mixed $correlation): bool
    {
        if (is_string($correlation)) {
            return trim($correlation) !== '';
        }
        if ($correlation === null) {
            return false;
        }

        return is_scalar($correlation);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function firstValue(array $rows, string $field): mixed
    {
        foreach ($rows as $row) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            $value = $row[$field];
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function timestamp(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (float) (new DateTimeImmutable($value))->format('U.u');
        } catch (\Throwable $e) {
            $parsed = strtotime($value);

            return $parsed === false ? null : (float) $parsed;
        }
    }

    private static function compareDesc(mixed $a, mixed $b): int
    {
        $ta = self::timestamp($a);
        $tb = self::timestamp($b);

        if ($ta === null && $tb === null) {
            return 0;
        }
        if ($ta === null) {
            return 1;
        }
        if ($tb === null) {
            return -1;
        }

        return $tb <=> $ta;
    }
}
