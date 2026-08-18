<?php declare(strict_types=1);

namespace App\Domain\Devices;

final class DevicePack
{
    public int $id;
    public string $code;
    public string $name;
    /** @var list<array{id:int,kind:string,external_id:string}> resolved from devices table */
    public array $devices = [];
    public ?int $roomId;
    public string $createdAt;
    public string $updatedAt;

    /** @param array<string,mixed> $props */
    public function __construct(array $props)
    {
        $this->id        = (int) ($props['id'] ?? 0);
        $this->code      = (string) ($props['code'] ?? '');
        $this->name      = (string) ($props['name'] ?? '');
        $this->roomId    = isset($props['room_id']) && $props['room_id'] !== null ? (int) $props['room_id'] : null;
        $this->createdAt = (string) ($props['created_at'] ?? '');
        $this->updatedAt = (string) ($props['updated_at'] ?? '');
    }

    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'code'       => $this->code,
            'name'       => $this->name,
            'room_id'    => $this->roomId,
            'devices'    => $this->devices,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
