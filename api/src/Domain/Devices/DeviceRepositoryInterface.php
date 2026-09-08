<?php
declare(strict_types=1);

namespace App\Domain\Devices;

interface DeviceRepositoryInterface
{
    /** @return list<Device> */
    public function listForRoom(int $roomId): array;

    /**
     * List every device from the `devices` table regardless of pack/room,
     * including unassigned RPI devices created by a factory claim (F39).
     * @return list<Device>
     */
    public function findAll(): array;

    public function findById(int $id): ?Device;

    public function findForRoomKind(int $roomId, string $kind): ?Device;

    /** @return list<Device> */
    public function findByPackAndKind(int $packId, string $kind): array;

    public function findOneByPackAndKind(int $packId, string $kind): ?Device;

    public function findByKindAndExternalId(string $kind, string $externalId): ?Device;

    public function findByExternalId(string $externalId): ?Device;

    /**
     * @param array<string,mixed>|null $meta
     */
    public function insert(int $packId, string $kind, string $externalId, ?string $label, ?int $apiClientId, ?array $meta): int;

    /**
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): int;

    public function delete(int $id): int;

    /**
     * Mark/unmark a device as identified ("mírame") by its external_id.
     * Only RPI-kind devices are matched. Returns the updated Device or null.
     */
    public function markIdentified(string $externalId, bool $state): ?Device;

    /**
     * List all RPI devices currently marked as identified, with room info.
     * @return list<array{room_id:int,code:string,device_id:int,external_id:string,identified_at:string}>
     */
    public function findIdentified(): array;

    /**
     * Update last_seen_at for a device (liveness tracking for dashboard).
     */
    public function updateLastSeen(int $deviceId): void;

    /**
     * Refresh last_seen_at for every device of a given kind inside a pack
     * (ESP32 heartbeat sub-device liveness, F33).
     *
     * @return int number of devices touched
     */
    public function touchPackKind(int $packId, string $kind): int;

    /**
     * Resolve the room_id for a device that belongs to a pack which is itself
     * assigned to a room (canonical device → pack → room). A device not linked
     * to any pack returns null.
     */
    public function resolveRoomId(int $deviceId): ?int;

    /**
     * Update battery percentage for a battery-powered device (F36).
     *
     * @param int $deviceId Device ID
     * @param ?int $pct Battery percentage 0-100, or null if unknown
     */
    public function updateBattery(int $deviceId, ?int $pct): void;
}
