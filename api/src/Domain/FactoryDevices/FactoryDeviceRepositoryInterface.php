<?php
declare(strict_types=1);

namespace App\Domain\FactoryDevices;

interface FactoryDeviceRepositoryInterface
{
    public function findById(int $id): ?FactoryDevice;
    public function findByChipId(string $chipId): ?FactoryDevice;
    /** @return array{0:FactoryDevice,1:bool} */
    public function announce(string $chipId, string $enrollmentHash): array;
    public function claimAndAudit(int $id, string $actor, ?int $actorClientId = null, ?string $label = null, ?int $packId = null): ?FactoryDevice;
    /** @return list<FactoryDevice> */
    public function list(string $status): array;
}
