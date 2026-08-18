<?php
declare(strict_types=1);

namespace Ws\Vb6Repo;

use PDO;

/**
 * Vb6LogUsuariosRepo: write access to bs2026.log_usuarios.
 *
 * Called by StayEventsSyncService for stay.closed and stay.overstay events.
 * Writes a log entry with rfid=APP_VB6_SYSTEM_RFID.
 *
 * See design.md §19.2, contracts.md §3.3.
 */
class Vb6LogUsuariosRepo
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Insert a log entry.
     *
     * @param string $rfid  ≤10 chars — the system identifier
     * @param string $fecha YYYY-MM-DD HH:MM:SS (UTC or local, as received)
     * @param string $tipo  'EXIT' | 'OUT' | 'IN' | 'ERROR' (CHAR(5))
     */
    public function insertLog(string $rfid, string $fecha, string $tipo): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO log_usuarios (rfid, fecha, tipo) VALUES (:rfid, :fecha, :tipo)'
        );
        $stmt->execute([
            ':rfid'  => $rfid,
            ':fecha' => $fecha,
            ':tipo'  => $tipo,
        ]);
    }
}
