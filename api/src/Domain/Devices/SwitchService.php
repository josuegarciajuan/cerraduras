<?php
declare(strict_types=1);

namespace App\Domain\Devices;

use App\Domain\Rooms\Room;
use App\Domain\Rooms\RoomRepositoryInterface;
use App\Infrastructure\Gateways\Switch\SwitchGatewayFactory;
use App\Infrastructure\Gateways\Switch\SwitchGatewayInterface;
use App\Support\Clock;
use App\Support\Errors\ConflictException;/**
 * SwitchService: operations for smart electricity protector (EAWCBT-J) — RF-16.
 *
 * Responsibilities:
 *   - turnOn(): find the SWITCH device for a room and turn it on.
 *   - turnOff(): find the SWITCH device for a room and turn it off.
 *   - getSwitchForRoom(): return the Switch device for a room (or null).
 *
 * Best-effort semantics: if no SWITCH device is registered for the room,
 * operations are no-ops (return OK) UNLESS the room has no pack at all
 * (409 room_without_pack). If the gateway fails, the error is
 * logged and returned but never thrown as an exception. This ensures that
 * switch failures never block the primary flow (QR validation, stay close).
 *
 * See design.md §2.4b, TSK-SW7.
 */
final class SwitchService
{
    private DeviceRepositoryInterface $devices;
    private RoomRepositoryInterface $rooms;

    public function __construct(
        DeviceRepositoryInterface $devices,
        RoomRepositoryInterface $rooms
    ) {
        $this->devices = $devices;
        $this->rooms   = $rooms;
    }

    /**
     * Turn on the switch for the given room (best-effort).
     *
     * @return array{ok:bool, provider:string, error:string|null}
     */
    public function turnOn(int $roomId): array
    {
        $gateway = $this->resolveGateway($roomId);
        if ($gateway === null) {
            return $this->noSwitchResult();
        }
        try {
            $result = $gateway->turnOn($roomId);
            if ($result['ok']) {
                $this->persistLastCommand($roomId, 'ON');
            }
            return $result;
        } catch (\Throwable $e) {
            error_log('[SwitchService] turnOn failed for room ' . $roomId . ': ' . $e->getMessage());
            return ['ok' => false, 'provider' => $gateway->getProvider(), 'error' => $e->getMessage()];
        }
    }

    /**
     * Turn off the switch for the given room (best-effort).
     *
     * @return array{ok:bool, provider:string, error:string|null}
     */
    public function turnOff(int $roomId): array
    {
        $gateway = $this->resolveGateway($roomId);
        if ($gateway === null) {
            return $this->noSwitchResult();
        }
        try {
            $result = $gateway->turnOff($roomId);
            if ($result['ok']) {
                $this->persistLastCommand($roomId, 'OFF');
            }
            return $result;
        } catch (\Throwable $e) {
            error_log('[SwitchService] turnOff failed for room ' . $roomId . ': ' . $e->getMessage());
            return ['ok' => false, 'provider' => $gateway->getProvider(), 'error' => $e->getMessage()];
        }
    }

    /**
     * Turn on the switch for the given pack (pack-based, no room required).
     *
     * @return array{ok:bool, provider:string, error:string|null}
     */
    public function turnOnByPack(int $packId): array
    {
        return $this->turnByPack($packId, true);
    }

    /**
     * Turn off the switch for the given pack (pack-based, no room required).
     *
     * @return array{ok:bool, provider:string, error:string|null}
     */
    public function turnOffByPack(int $packId): array
    {
        return $this->turnByPack($packId, false);
    }

    /**
     * Return the SWITCH device for a room, or null if none is registered.
     */
    public function getSwitchForRoom(int $roomId): ?Device
    {
        return $this->devices->findForRoomKind($roomId, Device::KIND_SWITCH);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Persist the last command sent to the switch in device.meta_json,
     * so the dashboard can reflect the real switch state.
     *
     * Best-effort: failures are logged but never thrown — the primary
     * flow (turn on/off) must not be blocked by metadata persistence.
     */
    private function persistLastCommand(int $roomId, string $command): void
    {
        try {
            $device = $this->getSwitchForRoom($roomId);
            if ($device === null) {
                return; // No switch registered — nothing to persist
            }

            $now      = Clock::nowUtc()->format('Y-m-d\TH:i:s\Z');
            $existing = is_array($device->meta) ? $device->meta : [];
            $merged   = array_merge($existing, [
                'last_command' => $command,
                'commanded_at' => $now,
            ]);

            $this->devices->update($device->id, ['meta_json' => json_encode($merged, JSON_UNESCAPED_UNICODE)]);

            // Refresh last_seen_at: a successful command proves the switch is online/reachable.
            // Without this, the dashboard always shows the switch as offline (red dot)
            // because no other mechanism updates last_seen_at for SWITCH devices.
            $this->devices->updateLastSeen($device->id);
        } catch (\Throwable $e) {
            error_log('[SwitchService] persistLastCommand failed for room ' . $roomId . ': ' . $e->getMessage());
        }
    }

    /**
     * Resolve the appropriate switch gateway for the given room.
     * Returns null if the room has no SWITCH device registered.
     */
    private function resolveGateway(int $roomId): ?SwitchGatewayInterface
    {
        // Check if a SWITCH device is registered for this room (via pack)
        $device = $this->devices->findForRoomKind($roomId, Device::KIND_SWITCH);
        if ($device === null) {
            // Verify room has a pack assigned — if not, this is an error
            $room = $this->rooms->findById($roomId);
            if ($room !== null && $room->packId === null) {
                throw new ConflictException(
                    'room_without_pack',
                    'La habitación no tiene un pack de dispositivos asignado',
                    ['room_id' => $roomId]
                );
            }
            // Room has pack but no SWITCH in it → legitimate no-op
            return null;
        }

        $room = $this->rooms->findById($roomId);
        return SwitchGatewayFactory::make($room, $this->devices);
    }

    /**
     * Default result when switch is not present in the pack (legitimate no-op).
     */
    private function noSwitchResult(): array
    {
        return ['ok' => true, 'provider' => 'NONE', 'error' => null];
    }

    /**
     * Shared pack-based turn logic: find SWITCH device in the pack and
     * send the command via the resolved gateway, bypassing any room lookup.
     */
    private function turnByPack(int $packId, bool $turnOn): array
    {
        $device = $this->devices->findOneByPackAndKind($packId, Device::KIND_SWITCH);
        if ($device === null) {
            return $this->noSwitchResult();
        }

        // Canonical model (F30): resolve the room from the pack, never from a
        // direct device room_id.
        $room = $this->rooms->findByPackId($packId);
        $gateway = SwitchGatewayFactory::make($room, $this->devices);
        $action = $turnOn ? 'ON' : 'OFF';

        try {
            $result = $turnOn ? $gateway->turnOnForDevice($device) : $gateway->turnOffForDevice($device);
            if ($result['ok']) {
                $this->persistLastCommandForDevice($device, $action);
            }
            return $result;
        } catch (\Throwable $e) {
            error_log('[SwitchService] turnByPack failed for pack ' . $packId . ': ' . $e->getMessage());
            return ['ok' => false, 'provider' => $gateway->getProvider(), 'error' => $e->getMessage()];
        }
    }

    /**
     * Persist last command for a specific Device (pack-based variant).
     */
    private function persistLastCommandForDevice(Device $device, string $command): void
    {
        try {
            $now      = Clock::nowUtc()->format('Y-m-d\TH:i:s\Z');
            $existing = is_array($device->meta) ? $device->meta : [];
            $merged   = array_merge($existing, [
                'last_command' => $command,
                'commanded_at' => $now,
            ]);
            $this->devices->update($device->id, ['meta_json' => json_encode($merged, JSON_UNESCAPED_UNICODE)]);

            // Refresh last_seen_at: a successful command proves the switch is online/reachable.
            $this->devices->updateLastSeen($device->id);
        } catch (\Throwable $e) {
            error_log('[SwitchService] persistLastCommandForDevice failed: ' . $e->getMessage());
        }
    }
}
