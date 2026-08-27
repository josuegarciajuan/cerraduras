<?php
declare(strict_types=1);

namespace App\Domain\FactoryDevices;

final class FactoryDevice
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_CLAIMED = 'CLAIMED';

    public function __construct(
        public int $id,
        public string $chipId,
        public string $status,
        public string $firstAnnouncedAt,
        public string $lastAnnouncedAt,
        public ?string $claimedAt,
        public ?string $claimedBy,
        public string $createdAt,
        public string $updatedAt,
        public ?int $deviceId = null
    ) {}

    public function claim(string $actor, string $at): void
    {
        if ($this->status !== self::STATUS_PENDING) return;
        $this->status = self::STATUS_CLAIMED;
        $this->claimedAt = $at;
        $this->claimedBy = $actor;
        $this->updatedAt = $at;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['id'=>$this->id, 'chip_id'=>$this->chipId, 'status'=>$this->status,
            'first_announced_at'=>$this->firstAnnouncedAt, 'last_announced_at'=>$this->lastAnnouncedAt,
            'claimed_at'=>$this->claimedAt, 'claimed_by'=>$this->claimedBy,
            'created_at'=>$this->createdAt, 'updated_at'=>$this->updatedAt, 'device_id'=>$this->deviceId];
    }
}
