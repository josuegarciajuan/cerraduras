<?php
declare(strict_types=1);

namespace App\Domain\FactoryDevices;

use App\Support\Errors\BadRequestException;
use App\Support\Errors\NotFoundException;

final class FactoryDeviceService
{
    public function __construct(private FactoryDeviceRepositoryInterface $repository) {}

    /** @return array{data:array<string,mixed>,created:bool} */
    public function announce(string $chipId, string $factoryKey): array
    {
        if (!preg_match('/^[0-9a-f]{12}$/', $chipId)) {
            throw new BadRequestException('chip_id must be exactly 12 lowercase hexadecimal characters');
        }
        if (!FactoryCredential::validKey($factoryKey)) {
            throw new BadRequestException('factory credential is invalid');
        }
        [$device, $created] = $this->repository->announce($chipId, FactoryCredential::hash($factoryKey));
        return ['data' => $device->toArray(), 'created' => $created];
    }

    /** @return list<array<string,mixed>> */
    public function list(string $status = 'PENDING'): array
    {
        $status = strtoupper($status);
        if (!in_array($status, ['PENDING', 'CLAIMED', 'ALL'], true)) {
            throw new BadRequestException('status must be PENDING, CLAIMED or ALL');
        }
        return array_map(fn (FactoryDevice $d) => $d->toArray(), $this->repository->list($status));
    }

    /** @return array<string,mixed> */
    public function claim(int $id, string $actor, ?int $actorClientId = null, ?string $label = null, ?int $packId = null): array
    {
        $device = $this->repository->findById($id);
        if ($device === null) {
            $device = $this->repository->findClaimedById($id);
            if ($device === null) throw new NotFoundException('Factory device not found');
            return $device->toArray();
        }
        $claimed = $this->repository->claimAndAudit($id, $actor, $actorClientId, $label, $packId);
        return ($claimed ?? $device)->toArray();
    }
}
