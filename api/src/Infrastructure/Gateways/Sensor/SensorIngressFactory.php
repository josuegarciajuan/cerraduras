<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Sensor;

use App\Domain\Devices\DeviceRepositoryInterface;
use App\Domain\Rooms\Room;
use App\Support\Config;

/**
 * SensorIngressFactory: resolves which SensorIngress implementation to use
 * at runtime, combining the global SIMULATED_MODE flag with the per-room
 * simulated_override field (design.md §10.1, TSK-082/202).
 *
 * Resolution rules:
 *   1. If room->simulatedOverride === true  → SimulatedSensorIngress.
 *   2. If room->simulatedOverride === false → TuyaSensorIngress (real).
 *   3. If room->simulatedOverride === null  → use global SIMULATED_MODE.
 *      - SIMULATED_MODE=true  → SimulatedSensorIngress.
 *      - SIMULATED_MODE=false → TuyaSensorIngress.
 *
 * Mirror of LockGatewayFactory. See TSK-202.
 */
final class SensorIngressFactory
{
    private DeviceRepositoryInterface $deviceRepo;

    public function __construct(DeviceRepositoryInterface $deviceRepo)
    {
        $this->deviceRepo = $deviceRepo;
    }

    /**
     * Resolve the correct sensor ingress for the given room.
     *
     * @return SensorIngressInterface
     */
    public function make(?Room $room = null): SensorIngressInterface
    {
        $simulated = $this->isSimulated($room);

        if (!$simulated) {
            return new TuyaSensorIngress($this->deviceRepo);
        }

        return new SimulatedSensorIngress();
    }

    /**
     * Return true if the sensor for the given room should use the simulated
     * ingress.
     */
    public function isSimulated(?Room $room): bool
    {
        if ($room !== null && $room->simulatedOverride !== null) {
            return $room->simulatedOverride;
        }
        return Config::getBool('SIMULATED_MODE', true);
    }
}
