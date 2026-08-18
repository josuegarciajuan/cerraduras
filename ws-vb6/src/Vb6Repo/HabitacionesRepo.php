<?php
declare(strict_types=1);

namespace Ws\Vb6Repo;

use PDO;

/**
 * HabitacionesRepo: read access to bs2026.habitaciones.
 *
 * Only used for verifying that a codhab exists before inserting debts.
 * Accepts both numeric and text codhab formats (P5).
 *
 * See contracts.md §3.4, design.md §17 (S17).
 */
final class HabitacionesRepo
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Find a habitacion by its codhab identifier.
     *
     * P5 resolution (design.md §17):
     *   1. Normalise codhab to string.
     *   2. Search by descripcion = codhab (exact string match).
     *   3. If not found and codhab is numeric, retry with LPAD/TRIM to handle
     *      VB6 zero-padded or space-padded codes.
     *
     * @return array<string,mixed>|null
     */
    public function findByCodhab(string $codhab): ?array
    {
        $codhab = trim((string) $codhab);

        // Attempt 1: exact descripcion match
        $stmt = $this->pdo->prepare(
            'SELECT descripcion, alojadas, maximo, estado, veces
             FROM habitaciones
             WHERE descripcion = :c
             LIMIT 1'
        );
        $stmt->execute([':c' => $codhab]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return $this->normalize($codhab, $row);
        }

        // Attempt 2: if numeric, try casting with leading/trailing space trim in DB
        if (preg_match('/^\d+$/', $codhab)) {
            $stmt2 = $this->pdo->prepare(
                "SELECT descripcion, alojadas, maximo, estado, veces
                 FROM habitaciones
                 WHERE TRIM(descripcion) = :c OR CAST(descripcion AS UNSIGNED) = :n
                 LIMIT 1"
            );
            $stmt2->execute([':c' => $codhab, ':n' => (int) $codhab]);
            $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($row2 !== false) {
                return $this->normalize($codhab, $row2);
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalize(string $codhabRaw, array $row): array
    {
        return [
            'codhab_raw'  => $codhabRaw,
            'descripcion' => (string) $row['descripcion'],
            'alojadas'    => (int) $row['alojadas'],
            'maximo'      => (int) $row['maximo'],
            'estado'      => (string) $row['estado'],
            'veces'       => (int) $row['veces'],
        ];
    }
}
