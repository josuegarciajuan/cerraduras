<?php
declare(strict_types=1);

namespace App\Domain\Devices;

use App\Domain\Rooms\RoomRepositoryInterface;
use App\Support\Errors\ConflictException;
use App\Support\Errors\ForbiddenException;
use App\Support\Errors\NotFoundException;
use App\Support\Errors\UnprocessableException;

/**
 * DeviceService: admin-facing operations for devices.
 *
 * Enforces:
 *  - The parent room exists.
 *  - kind is one of the supported ENUM values.
 *  - At most one device per (room, kind).
 *  - External id uniqueness per kind.
 *  - RPI devices MAY link to an api_clients row; the validity of that row
 *    is delegated to the FK and looked up on demand from callers.
 */
final class DeviceService
{
    private DeviceRepositoryInterface $devices;
    private RoomRepositoryInterface $rooms;

    public function __construct(DeviceRepositoryInterface $devices, RoomRepositoryInterface $rooms)
    {
        $this->devices = $devices;
        $this->rooms = $rooms;
    }

    /** @return list<Device> */
    public function listForRoom(int $roomId): array
    {
        return $this->devices->listForRoom($roomId);
    }

    /** @return list<Device> */
    public function listAll(): array
    {
        return $this->devices->findAll();
    }

    public function findForRoomKind(int $roomId, string $kind): ?Device
    {
        return $this->devices->findForRoomKind($roomId, $kind);
    }

    /**
     * Resolve the room associated to a given RPI device external id.
     * Used by QrValidateService to map the Raspberry's device_id to its room.
     */
    public function findRoomIdForRpiExternalId(string $externalId): ?int
    {
        $d = $this->devices->findByKindAndExternalId(Device::KIND_RPI, $externalId);
        if ($d === null) {
            return null;
        }
        // Resolve via pack (canonical), fallback to legacy roomId
        if ($d->packId !== null) {
            return $this->devices->resolveRoomId($d->id);
        }
        return $d->roomId;
    }

    /**
     * @param array<string,mixed>|null $meta
     */
    public function create(
        int $packId,
        string $kind,
        string $externalId,
        ?string $label,
        ?int $apiClientId,
        ?array $meta
    ): Device {
        $this->validateKind($kind);
        $this->validateExternalId($externalId);

        if ($this->devices->findByKindAndExternalId($kind, $externalId) !== null) {
            throw new ConflictException(
                'device_external_exists',
                'Ya existe un dispositivo con ese external_id para este tipo',
                ['kind' => $kind, 'external_id' => $externalId]
            );
        }

        $id = $this->devices->insert($packId, $kind, $externalId, $label, $apiClientId, $meta);
        $device = $this->devices->findById($id);
        if ($device === null) {
            throw new \RuntimeException('Device not found after insert');
        }
        return $device;
    }

    /**
     * Partial update of an existing device. `kind` and `room_id` are
     * intentionally immutable; to "move" a device, delete and recreate it.
     *
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): Device
    {
        $current = $this->devices->findById($id);
        if ($current === null) {
            throw new NotFoundException('Device not found', ['device_id' => $id]);
        }
        $sanitized = [];
        if (array_key_exists('external_id', $fields) && $fields['external_id'] !== null) {
            $ext = (string) $fields['external_id'];
            $this->validateExternalId($ext);
            if ($ext !== $current->externalId) {
                $conflict = $this->devices->findByKindAndExternalId($current->kind, $ext);
                if ($conflict !== null && $conflict->id !== $id) {
                    throw new ConflictException(
                        'device_external_id_taken',
                        'Another device of this kind uses the same external_id',
                        ['kind' => $current->kind, 'external_id' => $ext]
                    );
                }
            }
            $sanitized['external_id'] = $ext;
        }
        if (array_key_exists('api_client_id', $fields)) {
            $v = $fields['api_client_id'];
            $sanitized['api_client_id'] = $v === null ? null : (int) $v;
        }
        if (array_key_exists('meta', $fields)) {
            $m = $fields['meta'];
            if ($m !== null && !is_array($m)) {
                throw new UnprocessableException('invalid_meta', 'meta must be an object or null');
            }
            $sanitized['meta_json'] = $m === null ? null : json_encode($m, JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('pack_id', $fields)) {
            $sanitized['pack_id'] = $fields['pack_id'] === null ? null : (int) $fields['pack_id'];
        }
        if (array_key_exists('label', $fields)) {
            $sanitized['label'] = $fields['label'] === null ? null : (string) $fields['label'];
        }
        if (!empty($sanitized)) {
            $this->devices->update($id, $sanitized);
        }
        $reloaded = $this->devices->findById($id);
        if ($reloaded === null) {
            throw new \RuntimeException('Device disappeared during update');
        }
        return $reloaded;
    }

    public function delete(int $id): void
    {
        $existing = $this->devices->findById($id);
        if ($existing === null) {
            throw new NotFoundException('Device not found', ['device_id' => $id]);
        }
        $this->devices->delete($id);
    }

    private function validateKind(string $kind): void
    {
        if (!in_array($kind, Device::allKinds(), true)) {
            throw new UnprocessableException(
                'invalid_kind',
                'Unsupported device kind',
                ['allowed' => Device::allKinds()]
            );
        }
    }

    private function validateExternalId(string $externalId): void
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            throw new UnprocessableException('invalid_external_id', 'external_id is required');
        }
        if (mb_strlen($externalId) > 128) {
            throw new UnprocessableException(
                'invalid_external_id',
                'external_id must be <= 128 chars'
            );
        }
    }

    /**
     * Mark/unmark a device as identified ("mírame") by its chip/external id.
     * Called by the ESP32 when the physical identify button is pressed/released.
     *
     * @return array{identified:bool,room_id:int,device_id:int,identified_at?:string,previously_identified_at?:string}
     */
    public function identify(string $externalId, bool $state): array
    {
        $before = $this->devices->findByKindAndExternalId(Device::KIND_RPI, $externalId);
        if ($before === null) {
            throw new ForbiddenException(
                'device_mismatch',
                'Device not registered',
                ['external_id' => $externalId]
            );
        }

        $previouslyAt = $before->identifiedAt;

        $updated = $this->devices->markIdentified($externalId, $state);
        if ($updated === null) {
            throw new \RuntimeException('Device disappeared during identify');
        }

        $roomId = ($updated->packId !== null)
            ? $this->devices->resolveRoomId($updated->id)
            : $updated->roomId;

        $result = [
            'identified' => $updated->isIdentified,
            'room_id'    => $roomId,
            'device_id'  => $updated->id,
        ];

        if ($updated->isIdentified) {
            $result['identified_at'] = $updated->identifiedAt ?? '';
        } elseif ($previouslyAt !== null) {
            $result['previously_identified_at'] = $previouslyAt;
        }

        return $result;
    }

    /** @return list<array{room_id:int,code:string,device_id:int,external_id:string,identified_at:string}> */
    public function findIdentified(): array
    {
        return $this->devices->findIdentified();
    }

    /**
     * Admin force-unidentify a device by id.
     */
    public function unidentify(int $deviceId): void
    {
        $device = $this->devices->findById($deviceId);
        if ($device === null) {
            throw new NotFoundException('Device not found', ['device_id' => $deviceId]);
        }
        $this->devices->markIdentified($device->externalId, false);
    }
}
