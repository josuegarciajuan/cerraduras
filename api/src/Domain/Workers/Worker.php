<?php
declare(strict_types=1);

namespace App\Domain\Workers;

/**
 * Worker: hotel staff member with a master QR key.
 *
 * Each worker has a permanent QR token (revocable) that opens any door
 * their role permits — without stay/cooldown/time-slot restrictions.
 *
 * See RF-W1, RF-W4, design.md F38 §1.
 */
final class Worker
{
    public int $id;
    public string $name;
    public int $roleId;
    public string $qrTokenHash;
    public string $qrJti;
    public bool $active;
    public ?string $notes;
    public string $createdAt;
    public string $updatedAt;

    public function __construct(
        int $id,
        string $name,
        int $roleId,
        string $qrTokenHash,
        string $qrJti,
        bool $active,
        ?string $notes,
        string $createdAt,
        string $updatedAt
    ) {
        $this->id           = $id;
        $this->name         = $name;
        $this->roleId       = $roleId;
        $this->qrTokenHash  = $qrTokenHash;
        $this->qrJti        = $qrJti;
        $this->active       = $active;
        $this->notes        = $notes;
        $this->createdAt    = $createdAt;
        $this->updatedAt    = $updatedAt;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): void
    {
        $this->active      = false;
        $this->qrTokenHash = '';
    }

    public function revokeQr(string $newJti, string $newHash): void
    {
        $this->qrJti       = $newJti;
        $this->qrTokenHash = $newHash;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'role_id'        => $this->roleId,
            'active'         => $this->active,
            'notes'          => $this->notes,
            'created_at'     => $this->createdAt,
            'updated_at'     => $this->updatedAt,
        ];
    }
}
