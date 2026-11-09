<?php
declare(strict_types=1);

namespace App\Infrastructure\Gateways\Switch;

use App\Domain\Devices\Device;

/**
 * UnsupportedSwitchGateway (F122).
 *
 * Terminal gateway for switches that are not Zigbee2MQTT. It exists so the
 * factory never falls back to the removed Tuya cloud integration. Every
 * operation fails closed with a stable reason.
 */
final class UnsupportedSwitchGateway implements SwitchGatewayInterface
{
    public function __construct(private string $reason = 'unsupported_transport')
    {
    }

    public function turnOn(int $roomId, array $ctx = []): array
    {
        return $this->failure();
    }

    public function turnOff(int $roomId, array $ctx = []): array
    {
        return $this->failure();
    }

    public function turnOnForDevice(Device $device, array $ctx = []): array
    {
        return $this->failure();
    }

    public function turnOffForDevice(Device $device, array $ctx = []): array
    {
        return $this->failure();
    }

    public function getProvider(): string
    {
        return 'RETIRED';
    }

    /** @return array{ok:bool, provider:string, error:string|null} */
    private function failure(): array
    {
        return ['ok' => false, 'provider' => $this->getProvider(), 'error' => $this->reason];
    }
}
