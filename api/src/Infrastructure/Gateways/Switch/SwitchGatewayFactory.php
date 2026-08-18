<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Switch;

use App\Domain\Devices\DeviceRepositoryInterface;
use App\Domain\Rooms\Room;
use App\Support\Config;

/**
 * SwitchGatewayFactory: resolves which SwitchGateway implementation to use at
 * runtime, combining the global SIMULATED_MODE flag and the per-room
 * simulated_override field.
 *
 * Resolution rules (mirrors LockGatewayFactory, see design.md §2.4b):
 *   1. If room->simulatedOverride === true  → SimulatedSwitchGateway.
 *   2. If room->simulatedOverride === false → TuyaSwitchGateway.
 *   3. If room->simulatedOverride === null  → use global SIMULATED_MODE.
 *      - SIMULATED_MODE=true  → SimulatedSwitchGateway.
 *      - SIMULATED_MODE=false → TuyaSwitchGateway.
 *
 * TSK-SW6.
 */
final class SwitchGatewayFactory
{
    /**
     * Resolve the correct gateway for the given room.
     */
    public static function make(?Room $room = null, ?DeviceRepositoryInterface $deviceRepo = null): SwitchGatewayInterface
    {
        $simulated = self::isSimulated($room);

        if ($simulated) {
            return new SimulatedSwitchGateway();
        }

        if ($deviceRepo === null) {
            error_log('[SwitchGatewayFactory] Real mode requires DeviceRepository; falling back to Simulated');
            return new SimulatedSwitchGateway();
        }

        return new TuyaSwitchGateway($deviceRepo);
    }

    /**
     * Return true if the switch for the given room should use the simulated
     * gateway.
     */
    public static function isSimulated(?Room $room): bool
    {
        if ($room !== null && $room->simulatedOverride !== null) {
            return $room->simulatedOverride;
        }
        return Config::getBool('SIMULATED_MODE', true);
    }
}
