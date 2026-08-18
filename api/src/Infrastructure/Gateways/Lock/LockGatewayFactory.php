<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Lock;

use App\Domain\Devices\DeviceRepositoryInterface;
use App\Domain\Rooms\Room;
use App\Support\Config;

/**
 * LockGatewayFactory: resolves which LockGateway implementation to use at
 * runtime, combining the global SIMULATED_MODE flag, the per-room
 * simulated_override field, and the LOCK_PROVIDER env var.
 *
 * Resolution rules (TSK-223, F20):
 *   1. If room->simulatedOverride === true  → SimulatedLockGateway.
 *   2. If room->simulatedOverride === false → use LOCK_PROVIDER for real.
 *   3. If room->simulatedOverride === null  → use global SIMULATED_MODE.
 *      - SIMULATED_MODE=true → SimulatedLockGateway.
 *      - SIMULATED_MODE=false → use LOCK_PROVIDER.
 *
 * LOCK_PROVIDER values:
 *   LOCAL     → LocalLockGateway  (no-op; ESP32 self-opens via relay)
 *   ESP32     → Esp32LockGateway  (future: calls ESP32 HTTP server)
 *   TUYA      → TuyaLockGateway   (Tuya Cloud API)
 *   SIMULATED → SimulatedLockGateway
 */
final class LockGatewayFactory
{
    /**
     * Resolve the correct gateway for the given room.
     */
    public static function make(?Room $room = null, ?DeviceRepositoryInterface $deviceRepo = null): LockGatewayInterface
    {
        $simulated = self::isSimulated($room);

        if ($simulated) {
            return new SimulatedLockGateway();
        }

        return self::resolveByProvider($deviceRepo);
    }

    /**
     * Return true if the lock for the given room should use the simulated
     * gateway.
     */
    public static function isSimulated(?Room $room): bool
    {
        if ($room !== null && $room->simulatedOverride !== null) {
            return $room->simulatedOverride;
        }
        return Config::getBool('SIMULATED_MODE', true);
    }

    /**
     * Resolve the gateway based on the LOCK_PROVIDER env var.
     */
    private static function resolveByProvider(?DeviceRepositoryInterface $deviceRepo): LockGatewayInterface
    {
        $provider = strtoupper(Config::get('LOCK_PROVIDER', 'LOCAL') ?? 'LOCAL');

        switch ($provider) {
            case 'LOCAL':
                return new LocalLockGateway();

            case 'SIMULATED':
                return new SimulatedLockGateway();

            case 'TUYA':
                if ($deviceRepo === null) {
                    error_log('[LockGatewayFactory] TUYA provider requires DeviceRepository; falling back to LOCAL');
                    return new LocalLockGateway();
                }
                return new TuyaLockGateway($deviceRepo);

            case 'ESP32':
                // Future: Esp32LockGateway($deviceRepo)
                error_log('[LockGatewayFactory] ESP32 provider not yet implemented; falling back to LOCAL');
                return new LocalLockGateway();

            default:
                error_log("[LockGatewayFactory] Unknown LOCK_PROVIDER={$provider}; falling back to LOCAL");
                return new LocalLockGateway();
        }
    }
}
