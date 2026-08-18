<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Switch;

use App\Domain\Devices\Device;

/**
 * SimulatedSwitchGateway: no-op implementation for dev and testing.
 *
 * In simulated mode the physical switch is never contacted; the gateway simply
 * returns an OK result immediately.
 *
 * See design.md §2.4b, RF-16.7, TSK-SW5.
 */
final class SimulatedSwitchGateway implements SwitchGatewayInterface
{
    private const PROVIDER = 'SIMULATED';

    public function turnOn(int $roomId, array $ctx = []): array
    {
        return [
            'ok'       => true,
            'provider' => self::PROVIDER,
            'error'    => null,
        ];
    }

    public function turnOff(int $roomId, array $ctx = []): array
    {
        return [
            'ok'       => true,
            'provider' => self::PROVIDER,
            'error'    => null,
        ];
    }

    public function turnOnForDevice(Device $device, array $ctx = []): array
    {
        return $this->turnOn(0, $ctx);
    }

    public function turnOffForDevice(Device $device, array $ctx = []): array
    {
        return $this->turnOff(0, $ctx);
    }

    public function getProvider(): string
    {
        return self::PROVIDER;
    }
}
