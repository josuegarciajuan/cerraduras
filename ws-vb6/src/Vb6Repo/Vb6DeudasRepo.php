<?php
declare(strict_types=1);

namespace Ws\Vb6Repo;

use PDO;
use PDOException;

/**
 * Vb6DeudasRepo: write access to bs2026.deudas.
 *
 * Idempotency is handled at the DB level via UNIQUE KEY (codtic, temporada).
 * Duplicate insert is silently ignored (INSERT IGNORE).
 *
 * See design.md §19.1, contracts.md §3.2.
 */
final class Vb6DeudasRepo
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Insert a debt record into bs2026.deudas.
     *
     * Returns true if a new row was inserted, false if the (codtic, temporada)
     * pair already existed (idempotent duplicate).
     *
     * @param array{
     *   codtic: int,
     *   temporada: string,
     *   codcli_old: int,
     *   codcli_new: int,
     *   fecha: string,
     *   importe: int,
     *   pagada: string
     * } $data
     */
    public function insertDebt(array $data): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO deudas
                (codtic, temporada, codcli_old, codcli_new, fecha, importe, pagada)
             VALUES
                (:codtic, :temporada, :codcli_old, :codcli_new, :fecha, :importe, :pagada)'
        );

        $stmt->execute([
            ':codtic'     => $data['codtic'],
            ':temporada'  => $data['temporada'],
            ':codcli_old' => $data['codcli_old'],
            ':codcli_new' => $data['codcli_new'],
            ':fecha'      => $data['fecha'],
            ':importe'    => $data['importe'],
            ':pagada'     => $data['pagada'],
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Check if a debt already exists for the given (codtic, temporada) pair.
     *
     * @return array<string,mixed>|null
     */
    public function findByKey(int $codtic, string $temporada): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM deudas WHERE codtic = :c AND temporada = :t LIMIT 1'
        );
        $stmt->execute([':c' => $codtic, ':t' => $temporada]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
