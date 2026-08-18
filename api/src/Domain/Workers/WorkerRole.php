<?php
declare(strict_types=1);

namespace App\Domain\Workers;

/**
 * WorkerRole: defines what room_types a worker can access.
 *
 * See RF-W2, design.md F38 §1.
 */
final class WorkerRole
{
    public int $id;
    public string $name;
    public ?string $description;
    /** @var list<int> */
    public array $roomTypeIds;
    public string $createdAt;
    public string $updatedAt;

    /**
     * @param list<int> $roomTypeIds
     */
    public function __construct(
        int $id,
        string $name,
        ?string $description,
        array $roomTypeIds,
        string $createdAt,
        string $updatedAt
    ) {
        $this->id          = $id;
        $this->name        = $name;
        $this->description = $description;
        $this->roomTypeIds = $roomTypeIds;
        $this->createdAt   = $createdAt;
        $this->updatedAt   = $updatedAt;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'description'   => $this->description,
            'room_type_ids' => $this->roomTypeIds,
            'created_at'    => $this->createdAt,
            'updated_at'    => $this->updatedAt,
        ];
    }
}
