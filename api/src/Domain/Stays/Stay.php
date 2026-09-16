<?php
declare(strict_types=1);

namespace App\Domain\Stays;

/**
 * Stay: a rental session for a given room.
 *
 * Lifecycle (design.md §6.2):
 *   RESERVED -> OCCUPIED -> EXITED -> CLOSED
 *                        \-> OVERSTAY (can coexist with EXITED / CLOSED)
 *   RESERVED -> CANCELED
 *
 * VB6 references are captured at QR-issue time (or later via
 * PATCH /stays/{id}/vb6-refs) so the WS-VB6 bridge can insert into `deudas`
 * and `log_usuarios`. All vb6_* fields are optional at creation; the
 * synchronization service decides whether a debt can be synced depending on
 * which refs are present.
 */
final class Stay
{
    public const STATUS_RESERVED = 'RESERVED';
    public const STATUS_OCCUPIED = 'OCCUPIED';
    public const STATUS_OVERSTAY = 'OVERSTAY';
    public const STATUS_EXITED   = 'EXITED';
    public const STATUS_CLOSED   = 'CLOSED';
    public const STATUS_CANCELED = 'CANCELED';

    public int $id;
    public int $roomId;
    public string $status;
    public int $duracionMinutos;

    // All timestamps are stored as DATETIME(3) UTC strings; null when unset.
    public string $reservedAt;
    public ?string $firstEntryAt;
    /** F41: authoritative "guest confirmed inside" mark (door closed with presence). */
    public ?string $entryConfirmedAt;
    public ?string $exitDetectedAt;
    public ?string $closedAt;

    // VB6 references.
    public ?int $vb6Codalq;
    public ?int $vb6Codtic;
    public ?int $vb6Codcli;
    public ?int $vb6Codart;
    public ?int $vb6Codlot;
    public ?string $vb6CodhabRaw;
    public ?string $vb6Temporada;
    public ?int $vb6Empresa;
    public ?int $vb6Departamento;

    public string $createdAt;
    public string $updatedAt;

    public function __construct(
        int $id,
        int $roomId,
        string $status,
        int $duracionMinutos,
        string $reservedAt,
        ?string $firstEntryAt,
        ?string $exitDetectedAt,
        ?string $closedAt,
        ?int $vb6Codalq,
        ?int $vb6Codtic,
        ?int $vb6Codcli,
        ?int $vb6Codart,
        ?int $vb6Codlot,
        ?string $vb6CodhabRaw,
        ?string $vb6Temporada,
        ?int $vb6Empresa,
        ?int $vb6Departamento,
        string $createdAt,
        string $updatedAt,
        ?string $entryConfirmedAt = null
    ) {
        $this->id = $id;
        $this->roomId = $roomId;
        $this->status = $status;
        $this->duracionMinutos = $duracionMinutos;
        $this->reservedAt = $reservedAt;
        $this->firstEntryAt = $firstEntryAt;
        $this->entryConfirmedAt = $entryConfirmedAt;
        $this->exitDetectedAt = $exitDetectedAt;
        $this->closedAt = $closedAt;
        $this->vb6Codalq = $vb6Codalq;
        $this->vb6Codtic = $vb6Codtic;
        $this->vb6Codcli = $vb6Codcli;
        $this->vb6Codart = $vb6Codart;
        $this->vb6Codlot = $vb6Codlot;
        $this->vb6CodhabRaw = $vb6CodhabRaw;
        $this->vb6Temporada = $vb6Temporada;
        $this->vb6Empresa = $vb6Empresa;
        $this->vb6Departamento = $vb6Departamento;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->roomId,
            'status' => $this->status,
            'duracion_minutos' => $this->duracionMinutos,
            'reserved_at' => $this->reservedAt,
            'first_entry_at' => $this->firstEntryAt,
            'entry_confirmed_at' => $this->entryConfirmedAt,
            'exit_detected_at' => $this->exitDetectedAt,
            'closed_at' => $this->closedAt,
            'vb6_refs' => [
                'codalq' => $this->vb6Codalq,
                'codtic' => $this->vb6Codtic,
                'codcli' => $this->vb6Codcli,
                'codart' => $this->vb6Codart,
                'codlot' => $this->vb6Codlot,
                'codhab' => $this->vb6CodhabRaw,
                'temporada' => $this->vb6Temporada,
                'empresa' => $this->vb6Empresa,
                'departamento' => $this->vb6Departamento,
            ],
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * True if the stay is still effectively active (blocks re-issuing a QR
     * for the same room).
     */
    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_RESERVED,
            self::STATUS_OCCUPIED,
            self::STATUS_OVERSTAY,
        ], true);
    }

    /**
     * Returns true if the stay has reached a terminal state (no more lifecycle
     * transitions expected from the domain side).
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_CLOSED,
            self::STATUS_CANCELED,
        ], true);
    }
}
