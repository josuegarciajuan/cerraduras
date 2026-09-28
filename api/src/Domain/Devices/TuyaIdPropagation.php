<?php
declare(strict_types=1);

namespace App\Domain\Devices;

/**
 * TuyaIdPropagation — pure helpers to plan and reverse the propagation of new
 * Tuya `device_id`s after re-pairing the account (migration runbook §6).
 *
 * Everything here is side-effect free (no DB, no filesystem, no clock) so the
 * CLI `bin/tuya-propagate-ids.php` can drive real mutations while the logic is
 * unit-tested without a database or network.
 *
 * Concepts:
 *   - entry   = ['kind' => string, 'old' => string, 'new' => string, 'meta' => array]
 *               `kind` may be '' when the caller does not know it.
 *   - map     = the decoded JSON file, either ['devices' => [...], ...] or a
 *               plain list of entries.
 */
final class TuyaIdPropagation
{
    private function __construct()
    {
        // Static-only helper: not instantiable.
    }

    /**
     * Parse a `--id=KIND:OLD=NEW` CLI argument.
     *
     * @return array{kind:string,old:string,new:string}
     * @throws \InvalidArgumentException on malformed input.
     */
    public static function parseIdArg(string $arg): array
    {
        $arg = trim($arg);
        $colon = strpos($arg, ':');
        if ($colon === false || $colon === 0) {
            throw new \InvalidArgumentException(
                "Formato inválido '{$arg}': se espera KIND:OLD=NEW"
            );
        }

        $kind = strtoupper(trim(substr($arg, 0, $colon)));
        $rest = substr($arg, $colon + 1);
        $eq = strpos($rest, '=');
        if ($eq === false) {
            throw new \InvalidArgumentException(
                "Formato inválido '{$arg}': falta '=' entre OLD y NEW"
            );
        }

        $old = trim(substr($rest, 0, $eq));
        $new = trim(substr($rest, $eq + 1));

        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $kind)) {
            throw new \InvalidArgumentException("Kind inválido '{$kind}' en '{$arg}'");
        }
        if ($old === '' || $new === '') {
            throw new \InvalidArgumentException("'old' y 'new' no pueden estar vacíos en '{$arg}'");
        }
        if (preg_match('/\s/', $old) === 1 || preg_match('/\s/', $new) === 1) {
            throw new \InvalidArgumentException("'old'/'new' no pueden contener espacios en '{$arg}'");
        }
        if ($old === $new) {
            throw new \InvalidArgumentException("'old' y 'new' son iguales en '{$arg}'");
        }

        return ['kind' => $kind, 'old' => $old, 'new' => $new];
    }

    /**
     * Validate and normalise a map (or a plain list of entries).
     *
     * Rules: old/new required and non-empty, old !== new, kind optional,
     * meta optional (must be an object/assoc array). Duplicates are collapsed
     * by `old`: the first occurrence wins, which keeps the operation
     * deterministic and idempotent.
     *
     * @param array<string,mixed>|list<array<string,mixed>> $map
     * @return list<array{kind:string,old:string,new:string,meta:array<string,mixed>}>
     * @throws \InvalidArgumentException on malformed input.
     */
    public static function normalizeEntries(array $map): array
    {
        $raw = $map;
        if (array_key_exists('devices', $map)) {
            $raw = $map['devices'];
            if (!is_array($raw)) {
                throw new \InvalidArgumentException("'devices' del mapa debe ser una lista");
            }
        }

        $entries = [];
        /** @var array<string,true> $seen */
        $seen = [];

        foreach ($raw as $index => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("Entrada #{$index} no es un objeto JSON");
            }

            $old = isset($item['old']) ? trim((string) $item['old']) : '';
            $new = isset($item['new']) ? trim((string) $item['new']) : '';
            $kind = isset($item['kind']) ? strtoupper(trim((string) $item['kind'])) : '';

            if ($old === '' || $new === '') {
                throw new \InvalidArgumentException("Entrada #{$index}: 'old' y 'new' son obligatorios");
            }
            if ($old === $new) {
                throw new \InvalidArgumentException("Entrada #{$index}: 'old' y 'new' son iguales ({$old})");
            }
            if ($kind !== '' && !preg_match('/^[A-Z][A-Z0-9_]*$/', $kind)) {
                throw new \InvalidArgumentException("Entrada #{$index}: kind inválido '{$kind}'");
            }

            $meta = [];
            if (array_key_exists('meta', $item)) {
                if (!is_array($item['meta'])) {
                    throw new \InvalidArgumentException("Entrada #{$index}: 'meta' debe ser un objeto JSON");
                }
                /** @var array<string,mixed> $meta */
                $meta = $item['meta'];
            }

            if (isset($seen[$old])) {
                continue; // dedupe por `old`: gana la primera aparición
            }
            $seen[$old] = true;

            $entries[] = [
                'kind' => $kind,
                'old' => $old,
                'new' => $new,
                'meta' => $meta,
            ];
        }

        return $entries;
    }

    /**
     * Recursively merge a meta patch over the existing meta.
     *
     * Associative sub-objects (e.g. `dp_caps`, `calibration`) are merged
     * key-by-key; lists and scalars in the patch replace the existing value.
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    public static function mergeMeta(array $existing, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (
                is_array($value)
                && isset($existing[$key])
                && is_array($existing[$key])
                && !array_is_list($value)
                && !array_is_list($existing[$key])
            ) {
                /** @var array<string,mixed> $current */
                $current = $existing[$key];
                /** @var array<string,mixed> $incoming */
                $incoming = $value;
                $existing[$key] = self::mergeMeta($current, $incoming);
            } else {
                $existing[$key] = $value;
            }
        }

        return $existing;
    }

    /**
     * Build the inverse UPDATE statements to roll back an applied plan.
     *
     * The generated SQL is intentionally one statement per entry plus a
     * trailing newline so it can be written verbatim to a .sql file.
     *
     * @param list<array{kind:string,old:string,new:string,meta?:array<string,mixed>}> $entries
     */
    public static function buildRollbackSql(array $entries): string
    {
        $lines = [];
        foreach ($entries as $entry) {
            $old = self::sqlLiteral((string) $entry['old']);
            $new = self::sqlLiteral((string) $entry['new']);
            $sql = "UPDATE devices SET external_id='{$old}' WHERE external_id='{$new}'";

            $kind = isset($entry['kind']) ? (string) $entry['kind'] : '';
            if ($kind !== '') {
                $sql .= " AND kind='" . self::sqlLiteral($kind) . "'";
            }

            $lines[] = $sql . ';';
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * Replace every occurrence of `$old` with `$new` in a text blob.
     *
     * Idempotent by nature: once replaced, the old id is gone and a second
     * call only performs another pass (0 matches).
     *
     * @return array{content:string,count:int}
     */
    public static function replaceInContent(string $content, string $old, string $new): array
    {
        if ($old === '') {
            return ['content' => $content, 'count' => 0];
        }

        $count = 0;
        $replaced = str_replace($old, $new, $content, $count);

        return ['content' => $replaced, 'count' => $count];
    }

    private static function sqlLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
